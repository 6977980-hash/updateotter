"""Writes the "What can I catch here?" test vectors from the real Fisch CSVs in data/fsch (live rows
only): the compact data the tool works on, built here independently of CatchFinder::data(), and for
every fishing spot a handful of time/weather/season picks with the expected result, worked out here
independently of both compute() twins. Both twins must match it in CI.
Usage: python3 catch-make-vectors.py OUT.json   (CI writes it to a temp file, so new fish from an
import never need a committed vectors file)."""
import csv, json, math, os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
D = os.path.join(ROOT, 'data', 'fsch')
DIMS = [('time_of_day', 'time'), ('weather', 'weather'), ('season', 'season')]


def rows(name):
    with open(os.path.join(D, name), newline='', encoding='utf-8') as f:
        return list(csv.DictReader(f))


def split(v):
    return [x for x in (v or '').split('|') if x]


def build():
    locs = {r['id']: r for r in rows('locations.csv')}
    rar = {r['id']: r for r in rows('rarities.csv')}
    baits = {r['id']: r for r in rows('baits.csv')}
    fish = [r for r in rows('fish.csv') if r['status'] == 'live']
    count = {}
    for f in fish:
        for lid in split(f['location_ids']):
            if lid in locs and locs[lid]['status'] == 'live':
                count[lid] = count.get(lid, 0) + 1
    spots = sorted(count, key=lambda lid: (-count[lid], locs[lid]['name']))
    ix = {lid: i for i, lid in enumerate(spots)}
    out = []
    for f in fish:
        l = sorted(ix[lid] for lid in split(f['location_ids']) if lid in ix)
        if not l:
            continue
        r = rar.get(f['rarity_id'])
        row = {'name': f['name'], 'url': '', 'r': r['name'] if r else '', 'ro': int(r['sort_order']) if r else 0, 'l': l}
        for field, key in DIMS:
            vals = split(f[field])
            row[key] = ([] if 'Any' in vals else vals) if vals else None
        if f['bait_rule'] == 'none':
            row['b'] = ''
        elif f['bait_rule'] == 'specific':
            names = [baits[b]['name'] for b in split(f['bait_ids']) if b in baits]
            row['b'] = ', '.join(names) if names else None
        else:
            row['b'] = None
        # Sell value at average weight: C$ per kg x average kg, rounded half up (formula FSCH-FRM-0001).
        if f['value_per_kg'] and f['weight_avg_kg']:
            row['v'] = float(math.floor(float(f['value_per_kg']) * float(f['weight_avg_kg']) + 0.5))
        else:
            row['v'] = None
        out.append(row)
    return {'spots': [{'slug': locs[s]['slug'], 'name': locs[s]['name'], 'n': count[s]} for s in spots], 'fish': out}


def expect(data, pick):
    got, here, only = [], 0, 0
    for f in data['fish']:
        if pick['spot'] not in f['l']:
            continue
        here += 1
        ok, needs = True, False
        for _, key in DIMS:
            want, has = pick[key], f[key]
            if has is None or want == '' or has == []:
                continue
            if want in has:
                needs = True
            else:
                ok = False
        if ok:
            got.append(f)
            only += 1 if needs else 0
    got.sort(key=lambda f: (f['v'] is None, -(f['v'] or 0), -f['ro'], f['name'].encode('utf-8')))
    best = next((f['name'] for f in got if f['v'] is not None), None)
    return {'names': [f['name'] for f in got], 'here': here, 'other': here - len(got), 'only': only, 'best': best}


PICKS = [('', '', ''), ('Night', 'Rain', 'Winter'), ('Day', 'Clear', 'Summer'), ('Night', '', ''), ('', 'Foggy', ''), ('', '', 'Autumn'), ('Day', 'Windy', 'Spring')]

data = build()
assert len(data['spots']) > 10 and len(data['fish']) > 300, 'Fisch data looks empty'
cases = []
for i in range(len(data['spots'])):
    for t, w, s in PICKS:
        pick = {'spot': i, 'time': t, 'weather': w, 'season': s}
        cases.append({'pick': pick, 'expect': expect(data, pick)})
with open(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), 'catch-vectors.json'), 'w', encoding='utf-8') as f:
    json.dump({'data': data, 'cases': cases}, f, ensure_ascii=False)
print(len(cases), 'catch cases written')
