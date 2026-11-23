"""Offline test of the Fisch auto import (tools/data/fisch_watch.py): no network, fake wiki pages.

Run: python3 tools/data/fisch_watch.test.py   (CI runs it). It copies data/fsch to a temp folder,
plans against made-up wiki pages and applies the plan there; the real CSVs are never written.
"""
import csv
import os
import shutil
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fisch_watch as F  # noqa: E402

fails = []


def ok(cond, msg):
    if not cond:
        fails.append(msg)


def iso(mins_ago, now):
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(now - mins_ago * 60))


tmp = tempfile.mkdtemp()
data = os.path.join(tmp, 'fsch')
shutil.copytree(F.DATA, data)
before = {t: F.read(t, data)[1] for t in ('fish', 'codes', 'locations', 'updates')}
fish0 = before['fish']
loc0 = next(r for r in before['locations'] if r['status'] == 'live')
watched = next(r for r in fish0 if r['value_per_kg'] and r['weight_avg_kg'] and r['status'] == 'live')
renamed = next(r for r in fish0 if r['status'] == 'live' and r['id'] != watched['id'] and r['rarity_id'])
vandal = next(r for r in fish0 if r['value_per_kg'] and r['status'] == 'live' and r['id'] not in (watched['id'], renamed['id']))
code_rep, code_on = [r for r in before['codes'] if r['code_status'] == 'unverified'][:2]
now = time.time()


def fish_page(name, mins_ago, loc, rarity='Legendary', ckg='5', avg='20', avg_c='100', extra=''):
    return {'rev': 900000 + len(name), 'ts': iso(mins_ago, now), 'text': (
        '{{Fish|rarity=%s|location=[[%s]]|bait=None|time=Night|weather=Rain|season=Any|lowest_kg=10|average_kg=%s|highest_kg=40'
        '|c_kg=%s|average_c=%s|base_xp=300}}%s' % (rarity, loc, avg, ckg, avg_c, extra))}


NEWLOC = 'Glimmer Reef Test'
wiki = {
    'Zz Test Fish': fish_page('Zz Test Fish', 120, loc0['name']),
    'Zz Fresh Fish': fish_page('Zz Fresh Fish', 10, loc0['name']),
    'Zz Stub Fish': {'rev': 1, 'ts': iso(300, now), 'text': '{{Fish|rarity=TBA|location=TBA}}'},
    'Zz Gone Fish': fish_page('Zz Gone Fish', 300, loc0['name'], extra='{{Removed}}'),
    'Zz Reef Fish': fish_page('Zz Reef Fish', 200, NEWLOC),
    NEWLOC: {'rev': 5, 'ts': iso(500, now), 'text': '{{Location|name=x}}'},
    'Not A Fish Page': {'rev': 2, 'ts': iso(500, now), 'text': 'a location page'},
    # watched record: the wiki corrected C$/kg (same average C$ check keeps it consistent)
    watched['name']: fish_page(watched['name'], 90, loc0['name'], ckg='7', avg='10', avg_c='70'),
    # renamed watched record (moved page)
    'Zz Renamed Fish': fish_page('Zz Renamed Fish', 90, loc0['name']),
    # vandalised watched record: value blanked
    vandal['name']: fish_page(vandal['name'], 90, loc0['name'], ckg='', avg='', avg_c=''),
    'Codes': {'rev': 777, 'ts': iso(180, now), 'text': (
        '<tabber>\nActive Codes=\n{|\n|-\n| ZZNEWCODE || 500 Coins || 10\n|-\n| %s || old reward || \n|}\n|-|\nExpired=\n{|\n|-\n| %s || x\n|}\n</tabber>'
        % (code_on['code'], code_rep['code']))},
}
moved = {renamed['name']: 'Zz Renamed Fish'}
fish_titles = ['Zz Test Fish', 'Zz Fresh Fish', 'Zz Stub Fish', 'Zz Gone Fish', 'Zz Reef Fish', 'Not A Fish Page', fish0[0]['name']]
watch = [watched['id'], renamed['id'], vandal['id']]
pl = F.plan(data, fish_titles, [NEWLOC, loc0['name']], wiki, moved, wiki['Codes'], watch, now)

nf = {f['title']: f for f in pl['new_fish']}
ok(set(nf) == {'Zz Test Fish', 'Zz Fresh Fish', 'Zz Stub Fish', 'Zz Reef Fish'}, 'new fish found: %s' % sorted(nf))
ok(nf['Zz Test Fish']['ready'], 'stable complete fish is ready')
ok(not nf['Zz Fresh Fish']['ready'], 'fish edited 10 min ago waits')
ok(not nf['Zz Stub Fish']['ready'] and 'stub' in nf['Zz Stub Fish']['why'], 'stub waits')
ok([s['title'] for s in pl['skipped']] == ['Zz Gone Fish'], 'removed fish skipped')
ok([l['title'] for l in pl['new_locations']] == [NEWLOC], 'new location planned')
ok([c['code'] for c in pl['new_codes']] == ['ZZNEWCODE'] and pl['new_codes'][0]['ready'], 'new code found')
ok([c['id'] for c in pl['code_expired']] == [code_rep['id']], 'reported code now expired')
cor = {c['id']: c for c in pl['corrections']}
ok(watched['id'] in cor and cor[watched['id']]['changes']['value_per_kg'][1] == '7', 'correction of watched fish: %s' % cor.get(watched['id']))
ok([r['id'] for r in pl['renames']] == [renamed['id']], 'rename found')
ok(any(s['id'] == vandal['id'] and 'value_per_kg' in s['changes'] for s in pl['suspicious']), 'blanked value is suspicious')
ok(vandal['id'] not in cor or 'value_per_kg' not in cor[vandal['id']]['changes'], 'blanked value not applied')

res = F.apply(pl, data, day='2026-11-24', roblox_updated='2026-11-24T03:00:00Z')
after = {t: F.read(t, data)[1] for t in ('fish', 'codes', 'locations', 'updates')}
fa = {r['id']: r for r in after['fish']}
ok(len(after['fish']) == len(fish0) + 2, 'two fish appended (%d)' % (len(after['fish']) - len(fish0)))
ok([r['id'] for r in after['fish'][:len(fish0)]] == [r['id'] for r in fish0], 'old ids unchanged and in order')
n = len(fish0)
new = after['fish'][n:]
ok(new[0]['id'] == 'FSCH-FSH-%04d' % (int(fish0[-1]['id'][-4:]) + 1), 'next free id')
ok(all(r['added_in_update'] == res['upd_id'] for r in new), 'added_in_update set')
reef = next(r for r in new if r['name'] == 'Zz Reef Fish')
ok(reef['location_ids'] == res['new_locations'][0], 'new fish points at new location')
ok(after['locations'][-1]['name'] == NEWLOC, 'location appended')
upd = after['updates'][-1]
ok(upd['roblox_updated_at'] == '2026-11-24 03:00:00', 'roblox time format')
ok(upd['id'] == res['upd_id'] and upd['slug'] == '2026-11-24-fisch-wiki-update' and 'Zz Test Fish' in upd['summary__en'], 'update row')
ok(set(upd['affected_record_ids'].split('|')) == set(res['records']), 'affected ids')
cn = after['codes'][-1]
ok(cn['code'] == 'ZZNEWCODE' and cn['code_status'] == 'unverified' and cn['first_seen'] == '2026-11-24', 'new code is reported (player votes)')
ok(next(r for r in after['codes'] if r['id'] == code_rep['id'])['code_status'] == 'expired', 'code expired')
ok(fa[watched['id']]['value_per_kg'] == '7' and fa[watched['id']]['last_verified'] == '2026-11-24', 'correction applied')
rn = fa[renamed['id']]
ok(rn['name'] == 'Zz Renamed Fish' and rn['slug'] == 'zz-renamed-fish' and renamed['slug'] in rn['slug_history'].split('|'), 'rename keeps old slug for 301')
ok(fa[vandal['id']]['value_per_kg'] == vandal['value_per_kg'], 'vandal value kept')
for t, rows in after.items():
    h = F.read(t, data)[0]
    with open(os.path.join(data, t + '.csv'), encoding='utf-8', newline='') as f:
        ok(all(len(r) == len(h) for r in csv.reader(f)), '%s column count' % t)
# Nothing ready -> nothing written
pl2 = F.plan(data, ['Zz Fresh Fish'], [], {'Zz Fresh Fish': wiki['Zz Fresh Fish']}, {}, None, [], now)
res2 = F.apply(pl2, data, day='2026-11-24')
ok(res2['upd_id'] is None and not res2['records'], 'no update row when nothing is ready')

shutil.rmtree(tmp)
if fails:
    print('FAIL\n- ' + '\n- '.join(fails))
    sys.exit(1)
print('fisch_watch: all checks passed')
