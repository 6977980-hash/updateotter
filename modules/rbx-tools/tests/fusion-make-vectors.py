"""Writes fusion-vectors.json: the real SAE fusion recipes from data/sae (live rows only), plus
hand-made edge cases (qty 2, unknown income, three ingredients). Each case's `expect` is worked
out here, independently of both compute() twins, and both twins must match it in CI."""
import csv, json, os

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
D = os.path.join(ROOT, 'data', 'sae')


def rows(name):
    with open(os.path.join(D, name), newline='', encoding='utf-8') as f:
        return list(csv.DictReader(f))


def real():
    pets = {r['id']: r for r in rows('pets.csv')}
    data = {'pets': [], 'recipes': []}
    index = {}
    for f in rows('fusion_recipes.csv'):
        if f['status'] not in ('live', 'limited'):
            continue
        out = pets[f['output_pet_id']]
        # Cross-check: every pet a live recipe names is a live pet with a base income.
        for pid in [f['output_pet_id']] + [i['pet_id'] for i in json.loads(f['inputs'])]:
            assert pets[pid]['status'] in ('live', 'limited'), f"{f['id']}: {pid} is not live"
            assert pets[pid]['base_income_per_s'], f"{f['id']}: {pid} has no base income"
        inputs = []
        for i in json.loads(f['inputs']):
            p = pets[i['pet_id']]
            if p['id'] not in index:
                index[p['id']] = len(data['pets'])
                data['pets'].append({'name': p['name'], 'income': float(p['base_income_per_s']) if p['base_income_per_s'] else None})
            inputs.append({'pet': index[p['id']], 'qty': int(i.get('qty', 1))})
        data['recipes'].append({'name': out['name'], 'income': float(out['base_income_per_s']) if out['base_income_per_s'] else None, 'inputs': inputs})
    return data


def expect(data, owned):
    out = []
    for ri, r in enumerate(data['recipes']):
        miss, times, cost, slots = [], None, 0.0, -1
        for i in r['inputs']:
            have = max(0, int(owned[i['pet']]) if i['pet'] < len(owned) else 0)
            if have < i['qty']:
                miss.append({'pet': i['pet'], 'need': i['qty'] - have})
            t = have // i['qty']
            times = t if times is None else min(times, t)
            inc = data['pets'][i['pet']]['income']
            cost = None if cost is None or inc is None else cost + inc * i['qty']
            slots += i['qty']
        ch = None if cost is None or r['income'] is None else r['income'] - cost
        out.append({'recipe': ri, 'ready': not miss, 'times': times or 0, 'missing': miss, 'cost': cost, 'change': ch, 'slots': slots})

    def key(r):
        return (0 if r['ready'] else 1, len(r['missing']), 1 if r['change'] is None else 0, -(r['change'] or 0), r['recipe'])
    out.sort(key=key)
    need = {}
    for r in out:
        for m in r['missing']:
            n = need.setdefault(m['pet'], {'pet': m['pet'], 'need': 0, 'for': []})
            n['need'] = max(n['need'], m['need'])
            n['for'].append(r['recipe'])
    return {'ready': sum(1 for r in out if r['ready']), 'total': len(out), 'rows': out, 'missing': list(need.values())}


cases = []
r = real()
n = len(r['pets'])
for owned in ([0] * n, [1] * n, [1 if i % 2 == 0 else 0 for i in range(n)], [2] * n, [1] + [0] * (n - 1)):
    cases.append({'name': 'real ' + ''.join(map(str, owned)), 'data': r, 'owned': owned})
edge = {
    'pets': [{'name': 'A', 'income': 100.0}, {'name': 'B', 'income': None}, {'name': 'C', 'income': 50.0}, {'name': 'D', 'income': 10.0}],
    'recipes': [
        {'name': 'AA', 'income': 150.0, 'inputs': [{'pet': 0, 'qty': 2}]},
        {'name': 'AB', 'income': 500.0, 'inputs': [{'pet': 0, 'qty': 1}, {'pet': 1, 'qty': 1}]},
        {'name': 'ACD', 'income': 400.0, 'inputs': [{'pet': 0, 'qty': 1}, {'pet': 2, 'qty': 1}, {'pet': 3, 'qty': 1}]},
        {'name': 'CD?', 'income': None, 'inputs': [{'pet': 2, 'qty': 1}, {'pet': 3, 'qty': 1}]},
    ],
}
for owned in ([0, 0, 0, 0], [1, 0, 0, 0], [2, 1, 1, 1], [5, 0, 1, 1], [3, 3, 0, 0]):
    cases.append({'name': 'edge ' + ''.join(map(str, owned)), 'data': edge, 'owned': owned})
for c in cases:
    c['expect'] = expect(c['data'], c['owned'])
with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fusion-vectors.json'), 'w', encoding='utf-8') as f:
    json.dump(cases, f, indent=1)
    f.write('\n')
print(len(cases), 'cases')
