"""Fisch auto import for the hourly update watcher (Phase C, 2026-11-23).

Two steps, so the watcher can look at the plan before anything is written:

  python3 tools/data/fisch_watch.py scan [--watch ID,ID,...] [--min-age 60] > plan.json
  python3 tools/data/fisch_watch.py apply plan.json [--roblox-updated ISO] [--day YYYY-MM-DD] > result.json

scan (read-only, wiki API only, one request at a time with a 1 s pause):
  - new fish: Category:Fish pages with a {{Fish}} infobox whose name is not in data/fsch/fish.csv
  - new locations those fish need (Category:Locations pages not in locations.csv)
  - new codes on the wiki Codes page, and reported codes the wiki now lists as expired
  - corrections and renames of the watched records (--watch: ids auto mode added in the last 48 h)
  An item is "ready" only when its wiki page has not been edited for --min-age minutes (default 60),
  so edits that get reverted within the hour never reach the site. Changes that look like vandalism
  (a filled number blanked, a number moved more than 10x, a rarity that no longer reads) are listed
  under "suspicious" and never applied.

apply writes ready items into data/fsch/*.csv by each file's own header (append only, next free id),
adds one FSCH-UPD row for new fish/codes, puts a renamed record's old slug in slug_history (the
router then 301s it) and prints the ids it touched. It never deletes rows or renumbers ids.
Parse rules are the seed's own (tools/data/fisch_wiki.py), so new rows look like the old ones.
"""
import argparse
import csv
import json
import os
import re
import sys
import time
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fisch_wiki as W  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DATA = os.path.join(ROOT, 'data', 'fsch')
SPLIT = r',|;|/|\(|\)|\band\b|\bor\b'
COMPARE = ['rarity_id', 'location_ids', 'location_text__en', 'bait_rule', 'bait_ids', 'bait_text__en', 'time_of_day', 'weather',
           'season', 'weight_min_kg', 'weight_avg_kg', 'weight_max_kg', 'value_per_kg', 'currency_id', 'base_xp', 'status']
NUMERIC = {'weight_min_kg', 'weight_avg_kg', 'weight_max_kg', 'value_per_kg', 'base_xp'}


# ----------------------------------------------------------------------------- CSV helpers

def read(tab, data=DATA):
    with open(os.path.join(data, tab + '.csv'), encoding='utf-8', newline='') as f:
        rd = csv.DictReader(f)
        return list(rd.fieldnames), list(rd)


def save(tab, header, rows, data=DATA):
    with open(os.path.join(data, tab + '.csv'), 'w', encoding='utf-8', newline='') as f:
        w = csv.DictWriter(f, fieldnames=header, lineterminator='\n', extrasaction='ignore')
        w.writeheader()
        for r in rows:
            w.writerow({h: W.fmt(r.get(h)) if not isinstance(r.get(h), str) else r.get(h) for h in header})


def next_id(rows, ent):
    n = max([int(r['id'].rsplit('-', 1)[1]) for r in rows if r.get('id', '').startswith('FSCH-' + ent + '-')] or [0])
    return n + 1


def maps(data=DATA):
    """Lower-case name -> id maps the parser needs, built from the CSVs (not from wiki order)."""
    _, rar = read('rarities', data)
    _, loc = read('locations', data)
    _, bai = read('baits', data)
    rar_id = {r['name'].lower(): r['id'] for r in rar}
    loc_id = {r['name'].lower(): r['id'] for r in loc}
    for a, t in W.LOC_ALIAS.items():
        if t.lower() in loc_id:
            loc_id.setdefault(a, loc_id[t.lower()])
    bai_id = {r['name'].lower(): r['id'] for r in bai}
    return rar_id, loc_id, bai_id


def title_of(url):
    return urllib.parse.unquote(url.split('/wiki/', 1)[1]).replace('_', ' ') if '/wiki/' in (url or '') else ''


def utc(ts):
    import calendar
    return calendar.timegm(time.strptime(ts, '%Y-%m-%dT%H:%M:%SZ'))


# ----------------------------------------------------------------------------- wiki (network)

def pages(titles):
    """{title: {'text','rev','ts'}} plus {asked title: final title} for redirects and normalised names."""
    out, moved = {}, {}
    for i in range(0, len(titles), 50):
        r = W.api(dict(action='query', prop='revisions', rvprop='content|timestamp|ids', rvslots='main',
                       titles='|'.join(titles[i:i + 50]), redirects=1))
        q = r.get('query', {})
        for k in ('normalized', 'redirects'):
            for m in q.get(k, []):
                moved[m['from']] = m['to']
        for p in q.get('pages', {}).values():
            if 'revisions' in p:
                rv = p['revisions'][0]
                out[p['title']] = {'text': rv['slots']['main']['*'], 'rev': rv['revid'], 'ts': rv['timestamp']}
        time.sleep(1)
    for a in list(moved):  # follow chains (normalised -> redirect)
        b = moved[a]
        while b in moved and moved[b] != b:
            b = moved[b]
        moved[a] = b
    return out, moved


# ----------------------------------------------------------------------------- plan (pure)

def suspicious(field, old, new):
    if field in NUMERIC:
        if old not in ('', None) and new in ('', None):
            return 'number blanked'
        try:
            a, b = float(old), float(new)
        except (TypeError, ValueError):
            return ''
        if a > 0 and b > 0 and (b / a > 10 or a / b > 10):
            return 'moved more than 10x'
        if b < 0:
            return 'negative'
    if field == 'rarity_id' and old and not new:
        return 'rarity no longer reads'
    if field == 'status' and new == 'draft':
        return 'wiki now marks it removed (removals are ask-first)'
    return ''


def plan(data, fish_titles, loc_titles, wiki, moved, codes_page, watch, now, min_age=60):
    """Everything the scan found. wiki = fetched pages; moved = redirects; watch = record ids."""
    rar_id, loc_id, bai_id = maps(data)
    _, fish = read('fish', data)
    _, codes = read('codes', data)
    known = {r['name'].lower() for r in fish}
    out = {'scanned_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(now)), 'min_age': min_age,
           'new_fish': [], 'new_locations': [], 'new_codes': [], 'code_expired': [], 'corrections': [], 'renames': [],
           'suspicious': [], 'skipped': [], 'report': []}
    W.report.clear()

    def ready(p):
        return (now - utc(p['ts'])) / 60 >= min_age

    # New fish
    need_loc = set()
    loc_lower = {t.lower(): t for t in loc_titles}
    for t0 in sorted(fish_titles):
        t = moved.get(t0, t0)
        if t0.lower() in known or t.lower() in known:
            continue
        p = wiki.get(t)
        if not p or W.template(p['text'], ['Fish']) is None:
            continue
        row, mm = W.parse_fish(t, p, '', rar_id, loc_id, bai_id)
        if row['status'] == 'draft':
            out['skipped'].append({'title': t, 'why': 'wiki marks it removed or unobtainable'})
            continue
        missing = [k for k in ('rarity_id', 'location_text__en') if not row.get(k)]
        miss_loc = [loc_lower[x.strip().lower()] for x in re.split(SPLIT, row.get('location_text__en') or '')
                    if x.strip().lower() in loc_lower and x.strip().lower() not in loc_id]
        item = {'title': t, 'row': {k: v for k, v in row.items() if k != 'id'}, 'rev': p['rev'], 'ts': p['ts'],
                'ready': ready(p) and not missing, 'why': ('stub, no ' + ' or '.join(missing)) if missing else ('' if ready(p) else 'edited in the last hour'),
                'value_mismatch': list(mm) if mm else None, 'needs_locations': miss_loc}
        if item['ready']:
            need_loc.update(miss_loc)
        out['new_fish'].append(item)
    for t in sorted(need_loc):
        p = wiki.get(t)
        if p:
            gone = bool(re.search(r'\{\{\s*removed', p['text'], re.I))
            out['new_locations'].append({'title': t, 'rev': p['rev'], 'ts': p['ts'], 'draft': gone})

    # Codes
    if codes_page:
        have = {c['code'].lower(): c for c in codes}
        cready = ready(codes_page)
        for c in W.parse_codes(codes_page):
            old = have.get(c['code'].lower())
            if not old:
                out['new_codes'].append(dict(c, ready=cready))
            elif c['status'] == 'expired' and old['code_status'] == 'unverified':
                out['code_expired'].append({'id': old['id'], 'code': old['code'], 'ready': cready})
        out['codes_rev'] = codes_page['rev']
        out['codes_ts'] = codes_page['ts']

    # Watched records: corrections and renames (fish and codes auto mode added in the last 48 h)
    by_id = {r['id']: r for r in fish}
    for rid in watch:
        r = by_id.get(rid)
        if not r:
            continue
        t = title_of(r['source_url'])
        nt = moved.get(t, t)
        p = wiki.get(nt)
        if not p or W.template(p['text'], ['Fish']) is None:
            out['report'].append('%s %s: wiki page missing or no fish infobox now, left as is' % (rid, t))
            continue
        if nt != t:
            out['renames'].append({'id': rid, 'old_title': t, 'new_title': nt, 'old_slug': r['slug'], 'ready': ready(p)})
        new, _ = W.parse_fish(nt, p, rid, rar_id, loc_id, bai_id)
        ch, bad = {}, {}
        for k in COMPARE:
            a, b = r.get(k, ''), W.fmt(new.get(k))
            if a != b:
                why = suspicious(k, a, b)
                (bad if why else ch)[k] = [a, b] + ([why] if why else [])
        if ch:
            out['corrections'].append({'id': rid, 'name': r['name'], 'changes': ch, 'rev': p['rev'], 'ts': p['ts'], 'ready': ready(p)})
        if bad:
            out['suspicious'].append({'id': rid, 'name': r['name'], 'changes': bad, 'rev': p['rev']})
    out['report'] += list(W.report)
    return out


# ----------------------------------------------------------------------------- apply

def apply(pl, data=DATA, day=None, roblox_updated=''):
    day = day or time.strftime('%Y-%m-%d', time.gmtime())
    # Roblox API time (2026-11-23T03:46:12.5Z) -> the CSV's datetime form (2026-11-23 03:46:12).
    roblox_updated = re.sub(r'\.\d+', '', (roblox_updated or '').replace('T', ' ').rstrip('Z'))
    res = {'upd_id': None, 'new_fish': [], 'new_locations': [], 'new_codes': [], 'code_expired': [], 'corrections': [],
           'renames': [], 'records': []}
    fh, fish = read('fish', data)
    lh, locs = read('locations', data)
    ch, codes = read('codes', data)
    uh, upds = read('updates', data)
    slugs = {r['slug'] for r in fish} | {s for r in fish for s in (r.get('slug_history') or '').split('|') if s}

    def uniq(s, taken):
        b, n = s or 'item', 2
        while s in taken or not s:
            s = '%s-%d' % (b[:57], n)
            n += 1
        taken.add(s)
        return s

    fish_new = [f for f in pl['new_fish'] if f['ready']]
    code_new = [c for c in pl['new_codes'] if c['ready']]
    upd = None
    if fish_new or code_new:
        uid = 'FSCH-UPD-%04d' % next_id(upds, 'UPD')
        uslugs = {r['slug'] for r in upds}
        names = [f['title'] for f in fish_new]
        bits = []
        if fish_new:
            bits.append('%d new fish' % len(fish_new))
        if code_new:
            bits.append('%d new code%s' % (len(code_new), '' if len(code_new) == 1 else 's'))
        summary = []
        if fish_new:
            summary.append('We added %s to the fish list from the community wiki: %s.' % (
                'one new fish' if len(names) == 1 else '%d new fish' % len(names), ', '.join(names[:12]) + (' and more' if len(names) > 12 else '')))
        if code_new:
            summary.append('The wiki reports %s; we have not redeemed %s, so players can say below each code if it worked.' % (
                ', '.join(c['code'] for c in code_new), 'it' if len(code_new) == 1 else 'them'))
        upd = {'id': uid, 'slug': uniq('%s-fisch-wiki-update' % day, uslugs), 'title__en': 'Fisch: %s (%s)' % (' and '.join(bits), day),
               'release_date': day, 'roblox_updated_at': roblox_updated, 'summary__en': ' '.join(summary), 'affected_record_ids': '',
               'status': 'live', 'source_type': 'community', 'source_url': W.WIKI + 'Special:RecentChanges',
               'source_note': 'New fish and codes on the community wiki, imported by our update watcher after the pages were stable for an hour',
               'last_verified': day, 'verified_by': 'claude'}
        res['upd_id'] = uid

    # New locations first, so new fish can point at them.
    loc_names = {r['name'].lower() for r in locs}
    lslugs = {r['slug'] for r in locs}
    for l in pl.get('new_locations', []):
        if l['title'].lower() in loc_names:
            continue
        rid = 'FSCH-LOC-%04d' % next_id(locs, 'LOC')
        row = W.base(rid, uniq(W.slugify(l['title']), lslugs), l['title'], W.wurl(l['title']),
                     'Location page on the wiki (rev %d, %s)%s' % (l['rev'], l['ts'][:10], W.GONE if l['draft'] else ''),
                     'draft' if l['draft'] else 'live')
        row['last_verified'] = day
        locs.append(row)
        loc_names.add(l['title'].lower())
        res['new_locations'].append(rid)
    _, loc_id, _ = maps_from(locs)

    for f in fish_new:
        if f['title'].lower() in {r['name'].lower() for r in fish}:
            continue
        rid = 'FSCH-FSH-%04d' % next_id(fish, 'FSH')
        row = dict(f['row'], id=rid, last_verified=day, added_in_update=upd['id'])
        row['slug'] = uniq(row['slug'], slugs)
        ids = {loc_id[x.strip().lower()] for x in re.split(SPLIT, row.get('location_text__en') or '') if x.strip().lower() in loc_id}
        row['location_ids'] = '|'.join(sorted(ids))
        fish.append(row)
        res['new_fish'].append({'id': rid, 'name': row['name'], 'slug': row['slug'], 'has_value': bool(row.get('value_per_kg') not in (None, '')),
                                'full_data': all(row.get(k) not in (None, '') for k in ('value_per_kg', 'weight_avg_kg', 'weight_min_kg', 'weight_max_kg'))})

    for c in code_new:
        rid = 'FSCH-COD-%04d' % next_id(codes, 'COD')
        codes.append({'id': rid, 'code': c['code'], 'reward_text__en': c['reward'], 'level_required': c['level'], 'code_status': c['status'],
                      'first_seen': day, 'status': 'live', 'source_type': 'community', 'source_url': W.WIKI + 'Codes',
                      'source_note': ('Listed as active on the wiki codes page (rev %d, %s); not redeemed by us' if c['status'] == 'unverified'
                                      else 'Listed as expired on the wiki codes page (rev %d, %s)') % (pl['codes_rev'], pl['codes_ts'][:10]),
                      'last_verified': day, 'verified_by': 'claude', 'added_in_update': upd['id']})
        res['new_codes'].append({'id': rid, 'code': c['code'], 'status': c['status']})

    cby = {r['id']: r for r in codes}
    for c in pl['code_expired']:
        r = cby.get(c['id'])
        if c['ready'] and r and r['code_status'] == 'unverified':
            r['code_status'] = 'expired'
            r['source_note'] = 'Listed as expired on the wiki codes page (rev %d, %s)' % (pl['codes_rev'], pl['codes_ts'][:10])
            r['last_verified'] = day
            res['code_expired'].append(c['id'])

    fby = {r['id']: r for r in fish}
    for rn in pl['renames']:
        r = fby.get(rn['id'])
        if not rn['ready'] or not r or r['name'] == rn['new_title']:
            continue
        hist = [s for s in (r.get('slug_history') or '').split('|') if s]
        old = r['slug']
        new = W.slugify(rn['new_title'])
        if new != old:
            r['slug'] = uniq(new, slugs)
            hist.append(old)
        r['slug_history'] = '|'.join(dict.fromkeys(hist))
        r['name'] = rn['new_title']
        r['source_url'] = W.wurl(rn['new_title'])
        r['change_update_id'] = r.get('added_in_update') or r.get('change_update_id', '')
        r['last_verified'] = day
        res['renames'].append({'id': rn['id'], 'from': old, 'to': r['slug']})
    for co in pl['corrections']:
        r = fby.get(co['id'])
        if not co['ready'] or not r:
            continue
        for k, v in co['changes'].items():
            r[k] = v[1]
        r['wiki_revision'] = '%d (%s)' % (co['rev'], co['ts'][:10])
        r['change_update_id'] = r.get('added_in_update') or r.get('change_update_id', '')
        r['last_verified'] = day
        res['corrections'].append({'id': co['id'], 'fields': sorted(co['changes'])})

    if upd:
        upd['affected_record_ids'] = '|'.join([f['id'] for f in res['new_fish']] + [c['id'] for c in res['new_codes']])
        upds.append(upd)
    res['records'] = [f['id'] for f in res['new_fish']] + [c['id'] for c in res['new_codes']]
    save('fish', fh, fish, data)
    save('locations', lh, locs, data)
    save('codes', ch, codes, data)
    save('updates', uh, upds, data)
    return res


def maps_from(locs):
    loc_id = {r['name'].lower(): r['id'] for r in locs}
    for a, t in W.LOC_ALIAS.items():
        if t.lower() in loc_id:
            loc_id.setdefault(a, loc_id[t.lower()])
    return None, loc_id, None


# ----------------------------------------------------------------------------- main

def scan(args):
    _, fish = read('fish', args.data)
    watch = [x for x in (args.watch or '').split(',') if x]
    fish_titles = W.members('Category:Fish')
    loc_titles = W.members('Category:Locations')
    known = {r['name'].lower() for r in fish}
    want = [t for t in fish_titles if t.lower() not in known]
    by_id = {r['id']: r for r in fish}
    want += [title_of(by_id[i]['source_url']) for i in watch if i in by_id]
    _, loc_rows = read('locations', args.data)
    have_loc = {r['name'].lower() for r in loc_rows}
    want += [t for t in loc_titles if t.lower() not in have_loc]
    wiki, moved = pages(sorted(set(want)) + ['Codes'])
    codes_page = wiki.get('Codes')
    return plan(args.data, fish_titles, loc_titles, wiki, moved, codes_page, watch, time.time(), args.min_age)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('cmd', choices=['scan', 'apply'])
    ap.add_argument('plan', nargs='?')
    ap.add_argument('--data', default=DATA)
    ap.add_argument('--watch', default='')
    ap.add_argument('--min-age', type=int, default=60)
    ap.add_argument('--day')
    ap.add_argument('--roblox-updated', default='')
    a = ap.parse_args()
    if a.cmd == 'scan':
        print(json.dumps(scan(a), indent=1, ensure_ascii=False))
    else:
        print(json.dumps(apply(json.load(open(a.plan, encoding='utf-8')), a.data, a.day, a.roblox_updated), indent=1, ensure_ascii=False))


if __name__ == '__main__':
    main()
