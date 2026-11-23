"""Fisch wiki parser: the one-time seed of data/fsch/*.csv (2026-11-23) and the shared parse rules
that tools/data/fisch_watch.py (the hourly watcher's auto import) uses for new fish and codes.

IDs are positional: NEVER re-run this over existing CSVs. New rows are appended by hand or by the
watcher with the next free ID (append only). Facts only: names, rarities, locations, bait, time,
weather, season, weights, C$ per kg, XP, mutation multipliers, appraisal chances, codes. No wiki
prose is copied (no descriptions, quotes, catch quips or notes).

Usage (seed only; for updates use tools/data/fisch_watch.py):
  python3 tools/data/fisch_wiki.py fetch       # polite API dump into SRC (batches of 50, 1 s pause)
  python3 tools/data/fisch_wiki.py build OUT   # write the CSVs (default /home/user/updateotter/data/fsch)
Report of every value it could not read goes to OUT_DIR/../../../fisch-build-report.md (scratch).
"""
import csv
import json
import os
import re
import sys
import time
import unicodedata
import urllib.parse
import urllib.request

SRC = os.environ.get('FISCH_SRC', '/tmp/claude-0/fisch')
API = 'https://fisch.fandom.com/api.php'
WIKI = 'https://fisch.fandom.com/wiki/'
UA = 'UpdateOtterDataBot/1.0 (fan site data import; polite)'
DAY = os.environ.get('FISCH_DAY') or time.strftime('%Y-%m-%d', time.gmtime())
G = 'FSCH'
TAIL = ['status', 'source_type', 'source_url', 'evidence_media_id', 'source_note', 'last_verified', 'verified_by',
        'added_in_update', 'removed_in_update', 'change_update_id']
SLUGGED_TAIL = TAIL + ['slug_history']

# Sort order is ours (lowest to highest, then the special/event groups). Colours are our own palette.
RARITIES = [
    ('Trash', '#8D8D8D'), ('Common', '#A0A0A0'), ('Uncommon', '#3CB043'), ('Unusual', '#7CB342'), ('Rare', '#2F80ED'),
    ('Legendary', '#F2994A'), ('Mythical', '#EB5757'), ('Exotic', '#9B51E0'), ('Secret', '#00BFA5'),
    ('Divine Secret', '#F2C94C'), ('Apex', '#C2185B'), ('Gemstone', '#26C6DA'), ('Fragment', '#78909C'),
    ('Relic', '#8D6E63'), ('Extinct', '#6D4C41'), ('Special', '#AB47BC'), ('Limited', '#E91E63'),
]
RARITY_FIX = {'unsual': 'Unusual', 'divinesecret': 'Divine Secret', 'mythic': 'Mythical', 'limitedl': 'Limited'}
WEATHER = ['Clear', 'Foggy', 'Rain', 'Windy', 'Stormy', 'Tropical Sun', 'Starfall', 'Eclipse', 'Aurora Borealis']
WEATHER_FIX = {'rainy': 'Rain', 'rail': 'Rain', 'cler': 'Clear', 'fog': 'Foggy', 'storm': 'Stormy', 'aurora': 'Aurora Borealis'}
SEASONS = ['Spring', 'Summer', 'Autumn', 'Winter']
TIMES = ['Day', 'Night']
ANY = {'none', 'any', 'n/a', 'npne', 'nons', 'none!', 'anytime', 'all'}
UNKNOWN = {'', 'tba', 'tbd', '?', 'unknown', '?/kg'}
# Fish location names that are another spelling of a location page.
LOC_ALIAS = {'ocean': 'The Ocean', 'the depths': 'The Depths', 'depths': 'The Depths', 'atlantis': 'Atlantis',
             'ethereal abyss pool': 'Ethereal Abyss'}

report = []
# Removed in the game per the wiki, but no update tells us when: kept as draft (hidden) until an UPD record can carry removed_in_update.
GONE = '; wiki marks it removed or unobtainable, kept as draft until we know which update removed it'


# ----------------------------------------------------------------------------- fetch

def api(params):
    params = dict(params, format='json')
    req = urllib.request.Request(API + '?' + urllib.parse.urlencode(params), headers={'User-Agent': UA})
    for i in range(4):
        try:
            return json.load(urllib.request.urlopen(req, timeout=60))
        except Exception:  # noqa: BLE001
            time.sleep(3 * (i + 1))
    raise SystemExit('wiki API failed: %r' % params)


def members(cat):
    out, cont = [], {}
    while True:
        r = api(dict(action='query', list='categorymembers', cmtitle=cat, cmlimit=500, cmnamespace=0, **cont))
        out += [m['title'] for m in r['query']['categorymembers']]
        if 'continue' not in r:
            return out
        cont = {'cmcontinue': r['continue']['cmcontinue']}


def dump(titles, name):
    res = {}
    for i in range(0, len(titles), 50):
        r = api(dict(action='query', prop='revisions', rvprop='content|timestamp|ids', rvslots='main',
                     titles='|'.join(titles[i:i + 50]), redirects=1))
        for p in r['query']['pages'].values():
            if 'revisions' in p:
                rv = p['revisions'][0]
                res[p['title']] = {'ts': rv['timestamp'], 'rev': rv['revid'], 'text': rv['slots']['main']['*']}
        time.sleep(1)
    os.makedirs(SRC, exist_ok=True)
    json.dump(res, open(os.path.join(SRC, name + '.json'), 'w'))
    print(name, len(titles), len(res))


def fetch():
    for cat, name in (('Category:Fish', 'fish'), ('Category:Locations', 'locations'), ('Category:Bait', 'bait')):
        dump(members(cat), name)
    dump(['Codes', 'Appraiser', 'Template:MutationsMain', 'Template:MutationsAttributes', 'Template:MutationsEventExclusive',
          'Template:MutationsAdminAbuse', 'Template:MutationsRemoved'], 'misc')


# ----------------------------------------------------------------------------- wikitext helpers

def balanced(text, j):
    depth, k = 0, j
    while k < len(text):
        if text.startswith('{{', k):
            depth += 1
            k += 2
            continue
        if text.startswith('}}', k):
            depth -= 1
            k += 2
            if depth == 0:
                return text[j + 2:k - 2]
            continue
        k += 1
    return text[j + 2:]


def split_top(body):
    parts, depth, sq, cur, k = [], 0, 0, '', 0
    while k < len(body):
        two = body[k:k + 2]
        if two in ('{{', '}}', '[[', ']]'):
            depth += {'{{': 1, '}}': -1}.get(two, 0)
            sq += {'[[': 1, ']]': -1}.get(two, 0)
            cur += two
            k += 2
            continue
        if body[k] == '|' and depth == 0 and sq == 0:
            parts.append(cur)
            cur = ''
        else:
            cur += body[k]
        k += 1
    parts.append(cur)
    return parts


def template(text, names):
    """Top-level params (lower-case keys) of the first {{Name|...}} whose name is in names."""
    low = {n.lower() for n in names}
    i = 0
    while True:
        j = text.find('{{', i)
        if j < 0:
            return None
        m = re.match(r'\{\{\s*([^|}\n]+?)\s*(?=[|}\n])', text[j:])
        if m and m.group(1).strip().lower() in low:
            out = {}
            for p in split_top(balanced(text, j))[1:]:
                if '=' in p:
                    a, b = p.split('=', 1)
                    out[a.strip().lower()] = b.strip()
            return out
        i = j + 2


def plain(s):
    s = re.sub(r'<!--.*?-->', '', s or '', flags=re.S)
    s = re.sub(r'\[\[File:\s*([^\]|]+?)(?:\.\w{3,4})?\s*(?:\|[^\]]*)?\]\]', lambda m: ' ' + m.group(1).replace('_', ' ') + ' ', s, flags=re.I)
    for _ in range(5):
        s = re.sub(r'\{\{\s*[^{}|]+\|([^{}]*?)\}\}', lambda m: m.group(1).split('|')[-1], s)
        s = re.sub(r'\{\{\s*([^{}|]+?)\s*\}\}', r'\1', s)
    s = re.sub(r'\[\[(?:[^\]|]*\|)?([^\]]*)\]\]', r'\1', s)
    s = re.sub(r"'''?|<br\s*/?>", ' ', s)
    s = re.sub(r'<[^>]+>|[{}\[\]]', '', s)
    return re.sub(r'\s+', ' ', s).strip()


def slugify(s):
    orig = s
    s = s.lower().replace("'", '').replace('’', '').replace('&', 'and').replace('®', '')
    s = re.sub(r'[^a-z0-9]+', '-', s).strip('-')
    if not s:  # emoji-only names (the wiki has fish named after an emoji): use the character name
        s = re.sub(r'[^a-z0-9]+', '-', ' '.join(unicodedata.name(ch, '') for ch in orig).lower()).strip('-') + '-emoji'
    return s[:60].strip('-')


def nums(s):
    """All readings of a wiki number: '14,7' -> [14.7], '2,500' -> [2500, 2.5], '5,000.5kg' -> [5000.5]."""
    s = plain(s).lower().replace('c$', '').replace('/kg', '').replace('kg', '').replace(' ', '')
    m = re.match(r'^([\d.,]+)([kmb]?)$', s)
    if not m:
        return []
    body, suf = m.group(1), m.group(2)
    mult = {'': 1, 'k': 1e3, 'm': 1e6, 'b': 1e9}[suf]
    out = []
    if ',' in body and '.' in body:
        out.append(float(body.replace(',', '')))
    elif ',' in body:
        if re.fullmatch(r'\d{1,3}(,\d{3})+', body):
            out.append(float(body.replace(',', '')))
        if body.count(',') == 1:
            out.append(float(body.replace(',', '.')))
    else:
        try:
            out.append(float(body))
        except ValueError:
            return []
    return [round(v * mult, 6) for v in out]


def num(s):
    v = nums(s)
    return v[0] if v else None


def fmt(v):
    if v is None:
        return ''
    if isinstance(v, bool):
        return 'TRUE' if v else 'FALSE'
    if isinstance(v, float):
        return ('%.6f' % v).rstrip('0').rstrip('.') if v != int(v) else str(int(v))
    return str(v)


def vocab(raw, words, fix, what, title):
    """Canonical list from a wiki condition value; ['Any'] for no requirement; [] when unknown."""
    p = plain(raw)
    low = p.lower().strip()
    if low in UNKNOWN or low.startswith('tba'):
        return []
    if low in ANY:
        return ['Any']
    found = []
    rest = low
    for k, w in fix.items():
        if re.search(r'\b' + re.escape(k) + r'\b', rest):
            found.append(w)
            rest = re.sub(r'\b' + re.escape(k) + r'\b', ' ', rest)
    for w in sorted(words, key=len, reverse=True):
        if re.search(r'\b' + re.escape(w.lower()) + r'\b', rest):
            found.append(w)
            rest = re.sub(r'\b' + re.escape(w.lower()) + r'\b', ' ', rest)
    rest = re.sub(r'\b(and|or|none|any)\b|[,;/()]', ' ', rest).strip()
    if rest:
        report.append('%s: %s has unread part %r (kept %s)' % (title, what, rest, sorted(set(found)) or 'nothing'))
    return sorted(set(found), key=lambda w: words.index(w) if w in words else 99)


def write(out, tab, header, rows):
    os.makedirs(out, exist_ok=True)
    with open(os.path.join(out, tab + '.csv'), 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow(header)
        for r in rows:
            w.writerow([fmt(r.get(h)) for h in header])


def base(rid, slug, name, url, note, status='live'):
    return {'id': rid, 'slug': slug, 'name': name, 'status': status, 'source_type': 'community', 'source_url': url,
            'source_note': note, 'last_verified': DAY, 'verified_by': 'claude'}


def wurl(title):
    return WIKI + urllib.parse.quote(title.replace(' ', '_'), safe="()'_,-.")


def uniq_slugs(rows):
    seen = set()
    for r in rows:
        s, n = r['slug'] or 'item', 2
        while s in seen:
            s = '%s-%d' % (r['slug'][:57], n)
            n += 1
        r['slug'] = s
        seen.add(s)


def table_rows(text):
    """Rows of the wikitables in text as lists of raw cells (style prefixes removed)."""
    out = []
    text = re.sub(r'<!--.*?-->', '', text, flags=re.S)
    for t in re.finditer(r'\{\|(.*?)\n\|\}', text, flags=re.S):
        for chunk in re.split(r'\n\|-[^\n]*', t.group(1))[1:]:
            cells = []
            for line in re.split(r'\n(?=\|(?!\}))', '\n' + chunk):
                line = line.strip()
                if not line.startswith('|') or line.startswith('|+'):
                    continue
                for c in line[1:].split('||'):
                    c = re.sub(r'^\s*style="[^"]*"\s*\|', '', c)
                    cells.append(c.strip())
            if cells:
                out.append(cells)
    return out


# ----------------------------------------------------------------------------- build

def rarity_of(raw, title, rar_id):
    """Rarity record id from a wiki rarity value; rar_id maps lower-case rarity name -> id."""
    p = re.sub(r'\(.*?\)', '', plain(raw)).strip()
    k = re.sub(r'(?<=[a-z])[FB]$', '', p).strip().lower()
    k = RARITY_FIX.get(k.replace(' ', ''), k).lower()
    if k in rar_id:
        return rar_id[k]
    if p:
        report.append('%s: rarity %r not read' % (title, p))
    return None


def parse_fish(t, p, rid, rar_id, loc_id, bai_id):
    """One fish row from a wiki page {'text','rev','ts'}; maps are lower-case name -> record id.
    Returns (row, mismatch) where mismatch is set when the wiki's own value numbers disagree."""
    d = template(p['text'], ['Fish'])
    gone = bool(re.search(r'\{\{\s*(removed|unobtainable)\b', p['text'], re.I))
    r = base(rid, slugify(t), t, wurl(t), 'Infobox facts from the wiki fish page', 'live')
    r['wiki_revision'] = '%d (%s)' % (p['rev'], p['ts'][:10])
    r['rarity_id'] = rarity_of(d.get('rarity', ''), t, rar_id)
    if r['rarity_id'] and r['rarity_id'] == rar_id.get('limited'):
        r['status'] = 'limited'
    if gone:
        r['status'] = 'draft'
        r['source_note'] += GONE
    # Location
    lt = plain(d.get('location', ''))
    r['location_text__en'] = '' if lt.lower() in UNKNOWN else lt[:200]
    ids = []
    for part in re.split(r',|;|/|\(|\)|\band\b|\bor\b', lt):
        k = part.strip().lower()
        if k in loc_id:
            ids.append(loc_id[k])
    r['location_ids'] = '|'.join(sorted(set(ids)))
    # Bait
    bt = re.sub(r'\s*\(Bait\)', '', plain(d.get('bait', '')))
    bl = bt.lower()
    if bl in ANY or re.fullmatch(r'\W*\(?none\)?\W*', bl):
        r['bait_rule'] = 'none'
    elif bl in UNKNOWN or bl.startswith('tba'):
        r['bait_rule'] = 'unknown'
    else:
        ids = [bai_id[x.strip().lower()] for x in re.split(r',|/|\bor\b|\band\b', bt) if x.strip().lower() in bai_id]
        r['bait_ids'] = '|'.join(sorted(set(ids)))
        r['bait_rule'] = 'specific'
        r['bait_text__en'] = bt[:120]
        if not ids:
            report.append('%s: bait %r is not a bait page (kept as text)' % (t, bt))
    r['time_of_day'] = '|'.join(vocab(d.get('time', ''), TIMES, {}, 'time', t))
    r['weather'] = '|'.join(vocab(d.get('weather', ''), WEATHER, WEATHER_FIX, 'weather', t))
    r['season'] = '|'.join(vocab(d.get('season', ''), SEASONS, {'fall': 'Autumn'}, 'season', t))
    mm = None
    # Numbers: pick the reading of C$/kg and weights that matches the wiki's own average C$ when both exist.
    ck, lo, av, hi, ac = (nums(d.get(k, '')) for k in ('c_kg', 'lowest_kg', 'average_kg', 'highest_kg', 'average_c'))
    best = None
    for c in ck or [None]:
        for a in av or [None]:
            for x in ac or [None]:
                if None not in (c, a, x) and x > 0:
                    err = abs(c * a - x) / x
                    if best is None or err < best[0]:
                        best = (err, c, a)
    c = best[1] if best else (ck[0] if ck else None)
    a = best[2] if best else (av[0] if av else None)
    if best and best[0] > 0.02:
        # The wiki's own numbers disagree: we do not pick one, the value stays TBA until the wiki settles.
        mm = (t, c, a, ac[0])
        c = None
    lo_v = lo[0] if lo else None
    hi_v = hi[0] if hi else None
    if None not in (lo_v, hi_v) and lo_v > hi_v:
        report.append('%s: lightest %s > heaviest %s, weights left out' % (t, lo_v, hi_v))
        lo_v = hi_v = None
    r.update(value_per_kg=c, weight_min_kg=lo_v, weight_avg_kg=a, weight_max_kg=hi_v,
             currency_id='FSCH-CUR-0001' if c is not None else None)
    xp = num(d.get('base_xp', ''))
    r['base_xp'] = int(xp) if xp is not None else None
    return r, mm


def parse_codes(page):
    """Codes from the wiki Codes page: [{'code','reward','level','status'}], status unverified|expired."""
    out = []
    text = page['text']
    act = re.search(r'Active Codes\s*=(.*?)\|-\|\s*Expired\s*=', text, re.S)
    exp = re.search(r'\|-\|\s*Expired\s*=(.*?)</tabber>', text, re.S)
    for chunk, status in ((act.group(1) if act else '', 'unverified'), (exp.group(1) if exp else '', 'expired')):
        for cells in table_rows(chunk if chunk.rstrip().endswith('|}') else chunk + '\n|}'):
            code = plain(cells[0]).strip()
            if not code or ' ' in code or code.lower() in {c['code'].lower() for c in out}:
                continue
            reward = plain(re.sub(r'\n\s*\*', ', ', cells[1])) if len(cells) > 1 else ''
            reward = '' if reward.upper() == 'TBA' else reward.strip(', ')[:240]
            lvl = plain(cells[2]) if status == 'unverified' and len(cells) > 2 else ''
            out.append({'code': code, 'reward': reward, 'level': '' if lvl.upper() == 'TBA' else lvl, 'status': status})
    return out


def build(out):
    fish = json.load(open(os.path.join(SRC, 'fish.json')))
    locs = json.load(open(os.path.join(SRC, 'locations.json')))
    baits = json.load(open(os.path.join(SRC, 'bait.json')))
    misc = json.load(open(os.path.join(SRC, 'misc.json')))

    # Currency and rarities
    cur = [dict(base('FSCH-CUR-0001', 'c', 'C$', wurl('C$'), 'Fish sell for C$ (coins) on the wiki'), symbol_text='C$', is_premium=False)]
    rar, rar_id = [], {}
    for i, (n, col) in enumerate(RARITIES, 1):
        rid = 'FSCH-RAR-%04d' % i
        rar_id[n.lower()] = rid
        rar.append(dict(base(rid, slugify(n), n, wurl('Bestiary'), 'Rarity names used by the wiki fish infobox; sort order and colours are ours'),
                        sort_order=i, ui_color_hex=col))

    def rarity(raw, title):
        return rarity_of(raw, title, rar_id)

    # Locations: Category:Locations pages that use the Location infobox
    loc_rows, loc_id = [], {}
    # Location pages: Category:Locations, plus location pages filed under Category:Fish (e.g. Mushgrove Swamp).
    pool = dict(locs)
    for t, p in fish.items():
        if template(p['text'], ['Fish']) is None and (template(p['text'], ['Location']) is not None or 'DISPLAYTITLE' in p['text'][:200]):
            pool.setdefault(t, p)
    used = set()
    for p in fish.values():
        d = template(p['text'], ['Fish']) or {}
        for part in re.split(r',|;|/|\(|\)|\band\b|\bor\b', plain(d.get('location', ''))):
            used.add(part.strip().lower())
    names = sorted(t for t, p in pool.items() if template(p['text'], ['Location']) is not None or t.lower() in used)
    locs = pool
    for i, t in enumerate(names, 1):
        rid = 'FSCH-LOC-%04d' % i
        gone = bool(re.search(r'\{\{\s*removed', locs[t]['text'], re.I))
        loc_rows.append(base(rid, slugify(t), t, wurl(t), 'Location page on the wiki (rev %d, %s)%s' % (locs[t]['rev'], locs[t]['ts'][:10], GONE if gone else ''),
                             'draft' if gone else 'live'))
        loc_id[t.lower()] = rid
    for a, t in LOC_ALIAS.items():
        if t.lower() in loc_id:
            loc_id.setdefault(a, loc_id[t.lower()])
    uniq_slugs(loc_rows)

    # Baits
    bai_rows, bai_id = [], {}
    names = sorted(t for t in baits if t not in ('Baits',))
    for i, t in enumerate(names, 1):
        d = template(baits[t]['text'], ['Bait', 'BaitInfobox']) or {}
        name = re.sub(r'\s*\(Bait\)$', '', t)
        rid = 'FSCH-BAI-%04d' % i
        r = base(rid, slugify(name), name, wurl(t), 'Bait infobox on the wiki (rev %d, %s)' % (baits[t]['rev'], baits[t]['ts'][:10]))
        r['rarity_id'] = rarity(d.get('rarity', ''), t)
        for col, keys in (('preferred_luck_pct', ('preferred_luck', 'pref_luck')), ('universal_luck_pct', ('universal_luck', 'univ_luck')),
                          ('lure_speed_pct', ('lure_speed', 'lure')), ('resilience_pct', ('resilience',))):
            for k in keys:
                if k in d:
                    v = re.sub(r'[^\d.,-]', '', plain(d[k]).replace('%', ''))
                    r[col] = num(v.lstrip('-')) if v.strip('-') else None
                    if r[col] is not None and v.startswith('-'):
                        r[col] = -r[col]
                    break
        bai_rows.append(r)
        bai_id[name.lower()] = rid
    uniq_slugs(bai_rows)

    # Fish
    fsh_rows, mism = [], []
    titles = sorted(t for t, p in fish.items() if template(p['text'], ['Fish']) is not None)
    for i, t in enumerate(titles, 1):
        r, mm = parse_fish(t, fish[t], 'FSCH-FSH-%04d' % i, rar_id, loc_id, bai_id)
        if mm:
            mism.append(mm)
        fsh_rows.append(r)
    uniq_slugs(fsh_rows)

    # Mutations: main table, event tables, admin events, removed
    def mult(cell):
        p = plain(cell).lower().replace('×', 'x')
        m = re.match(r'^\s*([\d.]+)\s*x?\s*$', p.split('(')[0])
        if m and float(m.group(1)) > 0:
            return float(m.group(1)), ''
        return None, plain(cell)[:60]

    ap = {}
    for n, x, c in re.findall(r'\{\{Mutation\|([^}]+)\}\}\s*\(([\d.]+)x\)\s*\n\|\s*([\d.]+)%', misc['Appraiser']['text']):
        ap[n.strip().lower()] = float(c) / 100
    mut_rows, seen = [], set()
    groups = [('Template:MutationsMain', 'standard'), ('Template:MutationsEventExclusive', 'event'),
              ('Template:MutationsAdminAbuse', 'admin'), ('Template:MutationsRemoved', 'removed')]
    for page, grp in groups:
        text = misc[page]['text']
        # Event name per table from the === headings.
        pieces = re.split(r"\n===\s*'*(.*?)'*\s*===", text) if grp == 'event' else [text]
        evs = [('', pieces[0])] if grp != 'event' else list(zip(pieces[1::2], pieces[2::2]))
        for ev, chunk in evs:
            for cells in table_rows(chunk):
                if not cells or 'Mutation' not in cells[0] and not re.search(r'\w', plain(cells[0])):
                    continue
                name = plain(cells[0])
                if not name or name.lower() in seen or len(cells) < 2:
                    continue
                seen.add(name.lower())
                mv, mtext = mult(cells[1])
                appr = None
                if grp != 'admin' and len(cells) > 2:
                    a = plain(cells[2]).lower()
                    appr = True if a.startswith('yes') else False if a.startswith('no') else None
                ver = plain(cells[-1]) if len(cells) >= 4 else ''
                ver = ver if re.fullmatch(r'\d+\.\d+(\.\d+)?', ver) else ''
                rid = 'FSCH-MUT-%04d' % (len(mut_rows) + 1)
                st = {'standard': 'live', 'event': 'limited', 'admin': 'limited', 'removed': 'draft'}[grp]
                r = base(rid, slugify(name), name, WIKI + 'Mutations', 'Multiplier from the wiki mutation tables (%s, rev %d, %s)' % (
                    page.split(':')[1], misc[page]['rev'], misc[page]['ts'][:10]), st)
                r.update(value_mult=mv, mult_range_text__en=mtext if mv is None else '', mutation_group=grp,
                         event_name=ev.strip("' ") if grp == 'event' else '', appraisable=appr,
                         appraise_chance_prob=ap.get(name.lower()), added_in_version=ver if grp != 'removed' else '')
                if mv is None:
                    report.append('mutation %s: multiplier %r is not one number (kept as text)' % (name, mtext))
                mut_rows.append(r)
    uniq_slugs(mut_rows)

    # Attributes with one fixed multiplier (Shiny, Sparkling). Size attributes are weight ranges, not inputs.
    atr_rows = []
    for cells in table_rows(misc['Template:MutationsAttributes']['text']):
        name = plain(cells[0])
        mv, _ = mult(cells[1]) if len(cells) > 1 else (None, '')
        if mv is None:
            continue
        ch = re.search(r'([\d.]+)%\s*obtainment chance', cells[-1])
        rid = 'FSCH-ATR-%04d' % (len(atr_rows) + 1)
        r = base(rid, slugify(name), name, WIKI + 'Mutations#Attributes', 'Attribute table on the wiki (rev %d, %s)' % (
            misc['Template:MutationsAttributes']['rev'], misc['Template:MutationsAttributes']['ts'][:10]))
        r.update(sort_order=len(atr_rows) + 1, value_mult=mv, chance_prob=float(ch.group(1)) / 100 if ch else None)
        atr_rows.append(r)

    # Codes: Active tab = reported (unverified, not tested by us), Expired tab = expired.
    cod_rows = []
    for c in parse_codes(misc['Codes']):
        status = c['status']
        rid = 'FSCH-COD-%04d' % (len(cod_rows) + 1)
        cod_rows.append({'id': rid, 'code': c['code'], 'reward_text__en': c['reward'], 'level_required': c['level'],
                         'code_status': status, 'status': 'live', 'source_type': 'community', 'source_url': WIKI + 'Codes',
                         'source_note': ('Listed as active on the wiki codes page (rev %d, %s); not redeemed by us' if status == 'unverified'
                                         else 'Listed as expired on the wiki codes page (rev %d, %s)') % (misc['Codes']['rev'], misc['Codes']['ts'][:10]),
                         'last_verified': DAY, 'verified_by': 'claude'})

    # Write
    write(out, 'currencies', ['id', 'slug', 'name', 'symbol_text', 'is_premium'] + SLUGGED_TAIL, cur)
    write(out, 'rarities', ['id', 'slug', 'name', 'sort_order', 'ui_color_hex'] + SLUGGED_TAIL, rar)
    write(out, 'locations', ['id', 'slug', 'name', 'aliases'] + SLUGGED_TAIL, loc_rows)
    write(out, 'baits', ['id', 'slug', 'name', 'rarity_id', 'preferred_luck_pct', 'universal_luck_pct', 'lure_speed_pct', 'resilience_pct'] + SLUGGED_TAIL, bai_rows)
    write(out, 'fish', ['id', 'slug', 'name', 'aliases', 'rarity_id', 'location_ids', 'location_text__en', 'bait_rule', 'bait_ids', 'bait_text__en', 'time_of_day',
                        'weather', 'season', 'weight_min_kg', 'weight_avg_kg', 'weight_max_kg', 'value_per_kg', 'currency_id', 'base_xp',
                        'wiki_revision'] + SLUGGED_TAIL, fsh_rows)
    write(out, 'mutations', ['id', 'slug', 'name', 'aliases', 'value_mult', 'mult_range_text__en', 'mutation_group', 'event_name', 'appraisable',
                             'appraise_chance_prob', 'added_in_version'] + SLUGGED_TAIL, mut_rows)
    write(out, 'attributes', ['id', 'slug', 'name', 'sort_order', 'value_mult', 'chance_prob'] + SLUGGED_TAIL, atr_rows)
    write(out, 'codes', ['id', 'code', 'reward_text__en', 'reward_items', 'level_required', 'code_status', 'first_seen', 'last_tested_at',
                         'tested_by', 'expires_at'] + TAIL, cod_rows)

    full = [r for r in fsh_rows if r['value_per_kg'] is not None and r['weight_avg_kg'] is not None]
    lines = ['# Fisch import report (%s)' % DAY, '',
             'fish %d (with C$/kg %d, with C$/kg + weights %d), locations %d, baits %d, mutations %d, attributes %d, codes %d (reported %d)' % (
                 len(fsh_rows), sum(r['value_per_kg'] is not None for r in fsh_rows), len(full), len(loc_rows), len(bai_rows),
                 len(mut_rows), len(atr_rows), len(cod_rows), sum(c['code_status'] == 'unverified' for c in cod_rows)),
             '', '## Wiki average C$ does not match C$/kg x average kg (over 2%%): %d' % len(mism)]
    lines += ['- %s: %s x %s vs %s' % m for m in mism]
    lines += ['', '## Values not read (left empty, never guessed): %d' % len(report)] + ['- ' + x for x in report]
    open(os.path.join(SRC, 'fisch-build-report.md'), 'w').write('\n'.join(lines) + '\n')
    print(lines[2])
    print('mismatches', len(mism), 'unread', len(report))


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == 'fetch':
        fetch()
    else:
        out = sys.argv[2] if len(sys.argv) > 2 else '/home/user/updateotter/data/fsch'
        if os.path.exists(os.path.join(out, 'fish.csv')):
            raise SystemExit('refusing to overwrite %s: ids are positional, use tools/data/fisch_watch.py to append' % out)
        build(out)
