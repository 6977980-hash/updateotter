"""Checks the guides listed in content/guides.json against the game CSVs (owner decision 2026-10-24).

Usage: python3 tools/guides/check.py   (CI runs it; exit 1 on any error)
         python3 tools/guides/check.py --review OUT.md   also writes a review copy with the
         [rbx_value] numbers filled in from today's CSVs, for the owner to read before a guide goes live.

Per guide:
- the page file exists, starts with a uo:page header with "template": "guide";
- label and summary are set and pass the number lint;
- the copy passes a port of RBX\\Data\\Lint\\NumberLint (hand-typed game numbers, banned words);
- every [rbx_value id field] points at a live record and a filled field;
- every link to /<game>/pets|eggs|mutations/<slug>/ is a live record, every /<game>/guides/<slug>/
  link is a listed guide, and every other internal link is a page file or a game section;
- every claim in "facts" still holds in today's data, so a guide that says "the Axolotl is the
  Lake's best pet" fails CI the day a data change makes that untrue.

Fact types (slugs, not ids):
  ["best_in_biome", biome, pet]      highest base income of the pets that spawn in that biome
  ["in_biome", pet, biome]           the pet's biome egg spawns there
  ["rarity", pet, rarity]
  ["earns_more", pet_a, pet_b]       base income a > b
  ["earns_same", pet_a, pet_b]       same base income
  ["fusion", pet, pet_a, pet_b]      pet earns more than a or b alone, less than both together
  ["top_earner", pet, ""]            highest base income of every live pet
  ["hatches_faster", pet_a, pet_b]   biome egg hatch time a < b
  ["more_speed_reward", pet_a, pet_b] biome egg Speed reward a > b
  ["mult_above", mutation_a, mutation_b] income multiplier a > b
  ["mult_over", mutation, n]         income multiplier > n (for "worth more than two plain pets")
  ["biome_before", biome_a, biome_b] a needs less Speed than b
  ["rarer_rarity", rarity_a, rarity_b] sort order a > b
"""
import csv
import html
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
GAME_DATA = {'steal-an-egg': 'sae', 'grow-a-garden': 'gag', 'grow-a-garden-2': 'gag2', 'fish-it': 'fish'}
LIVE = {'live', 'limited'}

# Port of NumberLint::UNIT_RE / STAT_RE / BANNED.
UNIT_RE = re.compile(r'(?:[x×]\s?\d+(?:[.,]\d+)?|\d+(?:[.,]\d+)?\s?(?:x|×|%|percent|sec|secs|seconds|s\b|min|minutes|hours?|robux|chance))', re.I)
STAT_RE = re.compile(r'(?:\d[\d.,]*\s*(?:\w+\s){0,2}?(?:multiplier|income|odds|chance|speed|hatch|weight|value|price)\b|\b(?:multiplier|income|odds|chance|speed|hatch|weight|value|price)\w*\s+(?:\w+\s){0,2}?(?:of\s|is\s|=\s?|:\s?)?\d)', re.I)
BANNED = ['official', '100% accurate', 'guaranteed', 'always up to date', 'the only', '#1 site', 'ultimate', 'insane', 'op!!', 'secret trick', 'free robux', 'hack', 'leaked', "before it's patched"]
DATES = re.compile(r'\b(?:19|20)\d{2}\b|\b\d{1,2}\s(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b', re.I)
HEADER = re.compile(r'^<!--\s*uo:page\s+(\{.*?\})\s*-->', re.S)
VALUE = re.compile(r'\[rbx_value\s+([^\]]*)\]')
ATTR = re.compile(r'(\w+)="([^"]*)"')
HREF = re.compile(r'href="(/[^"#]*)')

errors = []


def prose(text):
    text = re.sub(r'\[rbx_[a-z_]+[^\]]*\]', ' ', text)
    text = re.sub(r'<!--.*?-->', ' ', text, flags=re.S)
    text = re.sub(r'<span[^>]*class="[^"]*rbx-num[^"]*"[^>]*>.*?</span>', ' ', text, flags=re.S)
    text = re.sub(r'<[^>]+>', ' ', text)
    return html.unescape(text)


def lint(content, extra=''):
    issues = []
    allow = re.search(r'<!--\s*rbx-allow-number:\s*[^>]+-->', content)
    scan = DATES.sub(' ', prose(content) + '\n' + extra)
    if not allow:
        for rule, rx in (('unit', UNIT_RE), ('stat-word', STAT_RE)):
            for hit in sorted(set(m.group(0) for m in rx.finditer(scan))):
                issues.append(f'{rule} "{hit.strip()}"')
    quoted = re.sub(r'<(p|ul|figure|table)\b[^>]*\brbx-lint-quote\b[^>]*>.*?</\1>', ' ', content, flags=re.S)
    low = (prose(quoted) + '\n' + extra).lower()
    for w in BANNED:
        if re.search(r'(?<![\w])' + re.escape(w) + r'(?![\w])', low):
            issues.append(f'banned-word "{w}"')
    return issues


def load(code):
    d = os.path.join(ROOT, 'data', code)
    t = {}
    for f in os.listdir(d):
        if f.endswith('.csv') and not f.startswith('_'):
            with open(os.path.join(d, f), encoding='utf-8', newline='') as fh:
                t[f[:-4]] = list(csv.DictReader(fh))
    return t


def num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


class Data:
    def __init__(self, code):
        self.t = load(code)
        self.by_id = {}
        for rows in self.t.values():
            for r in rows:
                if r.get('id'):
                    self.by_id[r['id']] = r
        self.slug = {k: {r['slug']: r for r in rows if r.get('slug') and r.get('status') in LIVE} for k, rows in self.t.items()}
        # Pet -> its biome-spawn egg and that egg's biomes.
        self.pet_egg = {}
        eggs = {r['id']: r for r in self.t.get('eggs', [])}
        for d in self.t.get('egg_drops', []):
            e = eggs.get(d['egg_id'])
            if e and e.get('obtain_method') == 'biome_spawn' and e.get('status') in LIVE:
                self.pet_egg.setdefault(d['pet_id'], e)

    def rec(self, kind, slug):
        r = self.slug.get(kind, {}).get(slug)
        if r is None:
            raise KeyError(f'no live {kind} "{slug}"')
        return r

    def pet_biomes(self, pet):
        e = self.pet_egg.get(pet['id'])
        return set(e['biome_ids'].split('|')) if e and e.get('biome_ids') else set()

    def egg_of(self, pet):
        e = self.pet_egg.get(pet['id'])
        if e is None:
            raise KeyError(f'pet "{pet["slug"]}" has no biome egg')
        return e

    def fact(self, f):
        kind, a, b = f[0], f[1], f[2]
        inc = lambda slug: num(self.rec('pets', slug)['base_income_per_s'])
        if kind == 'earns_same':
            return inc(a) is not None and inc(a) == inc(b), f'{a} {inc(a)} vs {b} {inc(b)}'
        if kind == 'fusion':
            x, y, z = inc(a), inc(b), inc(f[3])
            return None not in (x, y, z) and max(y, z) < x < y + z, f'{a} {x} vs {b} {y} + {f[3]} {z}'
        if kind == 'top_earner':
            best = max(self.slug['pets'].values(), key=lambda p: num(p['base_income_per_s']) or 0)
            return best['slug'] == a, f'top earner is {best["slug"]}'
        if kind == 'best_in_biome':
            bio = self.rec('biomes', a)
            pets = [p for p in self.slug['pets'].values() if bio['id'] in self.pet_biomes(p)]
            best = max(pets, key=lambda p: num(p['base_income_per_s']) or 0)
            return best['slug'] == b, f'best pet in {a} is {best["slug"]}'
        if kind == 'in_biome':
            return self.rec('biomes', b)['id'] in self.pet_biomes(self.rec('pets', a)), f'{a} does not spawn in {b}'
        if kind == 'rarity':
            p = self.rec('pets', a)
            got = self.by_id.get(p['rarity_id'], {}).get('slug', '')
            return got == b, f'{a} is {got or "no rarity"}'
        if kind == 'earns_more':
            x, y = num(self.rec('pets', a)['base_income_per_s']), num(self.rec('pets', b)['base_income_per_s'])
            return x is not None and y is not None and x > y, f'{a} {x} vs {b} {y}'
        if kind == 'hatches_faster':
            x, y = num(self.egg_of(self.rec('pets', a))['hatch_time_s']), num(self.egg_of(self.rec('pets', b))['hatch_time_s'])
            return x is not None and y is not None and x < y, f'{a} {x}s vs {b} {y}s'
        if kind == 'more_speed_reward':
            x, y = num(self.egg_of(self.rec('pets', a))['reward_speed']), num(self.egg_of(self.rec('pets', b))['reward_speed'])
            return x is not None and y is not None and x > y, f'{a} {x} vs {b} {y}'
        if kind == 'mult_above':
            x, y = num(self.rec('mutations', a)['income_mult']), num(self.rec('mutations', b)['income_mult'])
            return x is not None and y is not None and x > y, f'{a} {x} vs {b} {y}'
        if kind == 'mult_over':
            x = num(self.rec('mutations', a)['income_mult'])
            return x is not None and x > float(b), f'{a} {x}'
        if kind == 'biome_before':
            x, y = num(self.rec('biomes', a)['unlock_speed_required']) or 0, num(self.rec('biomes', b)['unlock_speed_required'])
            return y is not None and x < y, f'{a} {x} vs {b} {y}'
        if kind == 'rarer_rarity':
            x, y = int(self.rec('rarities', a)['sort_order']), int(self.rec('rarities', b)['sort_order'])
            return x > y, f'{a} {x} vs {b} {y}'
        raise KeyError(f'unknown fact type {kind}')


# Review copy: [rbx_value] filled in roughly the way Number::format shows it.
LADDER = [('K', 3), ('M', 6), ('B', 9), ('T', 12), ('Qa', 15), ('Qi', 18)]


def show(v, fmt):
    if fmt == 'mult':
        return '×' + (f'{v:.4f}'.rstrip('0').rstrip('.'))
    if fmt == 'duration':
        t = int(v + 0.5)
        parts, start = [], False
        for k, n in (('d', t // 86400), ('h', t % 86400 // 3600), ('m', t % 3600 // 60), ('s', t % 60)):
            if not start and n == 0 and k != 's':
                continue
            parts.append((f'{n:02d}' if start else str(n)) + k)
            start = True
        return ' '.join(parts)
    if fmt == 'int':
        return f'{int(round(v)):,}'
    suf, div = '', 1
    for s, e in LADDER:
        if abs(v) >= 10 ** e:
            suf, div = s, 10 ** e
    m = v / div
    txt = f'{m:.3g}' if m < 1000 else f'{m:,.0f}'
    if 'e' in txt:
        txt = f'{m:,.0f}'
    return txt + suf


FORMATS = {}


def formats():
    if not FORMATS:
        for f in os.listdir(os.path.join(ROOT, 'modules', 'rbx-data', 'games')):
            g = json.load(open(os.path.join(ROOT, 'modules', 'rbx-data', 'games', f), encoding='utf-8'))
            for ent, spec in g.get('entities', {}).items():
                for name, d in (spec.get('fields') or {}).items():
                    if d.get('internal'):
                        continue
                    t = d.get('type', '')
                    FORMATS[(g['game_code'], ent, name)] = d.get('display_format') or {'int': 'int', 'mult': 'mult', 'duration_s': 'duration'}.get(t, 'abbr')
    return FORMATS


def review_text(body, data):
    def val(m):
        a = dict(ATTR.findall(m.group(1)))
        r = data.by_id.get(a.get('id', ''), {})
        v = num(r.get(a.get('field', '')))
        if v is None:
            return r.get(a.get('field', ''), '??')
        code, ent = a['id'].split('-')[0], a['id'].split('-')[1]
        return show(v, a.get('format') or formats().get((code, ent, a['field']), 'abbr'))
    t = VALUE.sub(val, body)
    t = re.sub(r'<!-- wp:rbx/data-section \{"game":"[a-z0-9]+","section":"([a-z_-]+)"[^>]*/-->', r'\n\n_[live data table: \1]_\n\n', t)
    t = re.sub(r'<!-- wp:shortcode -->\s*\[uo_guides[^\]]*\]\s*<!-- /wp:shortcode -->', '\n\n_[list of the other live guides]_\n\n', t)
    t = re.sub(r'<!--.*?-->', '', t, flags=re.S)
    t = re.sub(r'<h2[^>]*>(.*?)</h2>', r'\n\n## \1\n\n', t, flags=re.S)
    t = re.sub(r'<li>(.*?)</li>', r'\n- \1', t, flags=re.S)
    t = re.sub(r'<a href="([^"]*)">(.*?)</a>', r'[\2](https://updateotter.com\1)', t, flags=re.S)
    t = re.sub(r'</?strong>', '**', t)
    t = re.sub(r'<[^>]+>', '', t)
    t = re.sub(r'\n{3,}', '\n\n', t)
    return html.unescape(t).strip()


def main():
    review = sys.argv[sys.argv.index('--review') + 1] if '--review' in sys.argv else ''
    path = os.path.join(ROOT, 'content', 'guides.json')
    try:
        cfg = json.load(open(path, encoding='utf-8'))
    except (OSError, ValueError) as e:
        print(f'content/guides.json: {e}')
        return 1
    pages = set()
    for dp, _, fs in os.walk(os.path.join(ROOT, 'content', 'pages')):
        for f in fs:
            if f.endswith('.html'):
                pages.add(os.path.relpath(os.path.join(dp, f), os.path.join(ROOT, 'content', 'pages'))[:-5])
    out = []
    for game, gc in cfg.items():
        code = GAME_DATA.get(game)
        if code is None:
            errors.append(f'{game}: unknown game folder')
            continue
        data = Data(code)
        sections = set(json.load(open(os.path.join(ROOT, 'modules', 'rbx-data', 'games', code + '.json'), encoding='utf-8')).get('sections', {}))
        if not re.match(r'^\d{4}-\d{2}-\d{2}$', str(gc.get('start', ''))):
            errors.append(f'{game}: "start" must be a YYYY-MM-DD date')
        slugs = [g.get('slug', '') for g in gc.get('guides', [])]
        if len(set(slugs)) != len(slugs):
            errors.append(f'{game}: duplicate guide slugs')
        for n, g in enumerate(gc.get('guides', []), start=1):
            slug = g.get('slug', '')
            where = f'{game}/guides/{slug}'
            fp = os.path.join(ROOT, 'content', 'pages', game, 'guides', slug + '.html')
            if not os.path.isfile(fp):
                errors.append(f'{where}: no page file')
                continue
            src = open(fp, encoding='utf-8').read()
            hm = HEADER.match(src)
            head = json.loads(hm.group(1)) if hm else {}
            if head.get('template') != 'guide' or not head.get('title'):
                errors.append(f'{where}: first line must be a uo:page header with a title and "template": "guide"')
            body = HEADER.sub('', src, count=1)
            for k in ('label', 'summary'):
                if not str(g.get(k, '')).strip():
                    errors.append(f'{where}: "{k}" is empty')
            for i in lint(body, '\n'.join([head.get('title', ''), g.get('label', ''), g.get('summary', '')])):
                errors.append(f'{where}: lint {i}')
            for m in VALUE.finditer(body):
                a = dict(ATTR.findall(m.group(1)))
                r = data.by_id.get(a.get('id', ''))
                if r is None or r.get('status') not in LIVE:
                    errors.append(f'{where}: [rbx_value] {a.get("id")} is not a live record')
                elif a.get('field') != 'name' and (r['id'].split('-')[0], r['id'].split('-')[1], a.get('field')) not in formats():
                    errors.append(f'{where}: [rbx_value] field "{a.get("field")}" is not a shown field of {r["id"].split("-")[1]}')
                elif str(r.get(a.get('field', ''), '')).strip() == '':
                    errors.append(f'{where}: [rbx_value] {a.get("id")} field "{a.get("field")}" is empty')
            for href in HREF.findall(body):
                p = href.strip('/')
                parts = p.split('/')
                if len(parts) == 3 and parts[0] == game and parts[1] in ('pets', 'eggs', 'mutations'):
                    if parts[2] not in data.slug.get(parts[1], {}):
                        errors.append(f'{where}: link {href} is not a live {parts[1][:-1]}')
                elif len(parts) == 3 and parts[1] == 'guides':
                    if parts[0] != game or parts[2] not in slugs:
                        errors.append(f'{where}: link {href} is not a guide listed in guides.json')
                elif p not in pages and not (len(parts) == 2 and parts[0] == game and parts[1] in sections):
                    errors.append(f'{where}: link {href} is not a page of this site')
            for f in g.get('facts', []):
                try:
                    ok, why = data.fact(f)
                except (KeyError, IndexError, ValueError) as e:
                    errors.append(f'{where}: fact {f}: {e}')
                    continue
                if not ok:
                    errors.append(f'{where}: fact {f} no longer holds ({why}); rewrite that line')
            if g.get('queue') and g.get('reviewed_by') and not g.get('reviewed_on'):
                errors.append(f'{where}: "reviewed_on" is empty')
            if review and g.get('queue'):
                qn = sum(1 for x in gc['guides'][:n] if x.get('queue'))
                state = f'reviewed by {g["reviewed_by"]} on {g.get("reviewed_on")}' if g.get('reviewed_by') else 'NOT reviewed yet'
                out.append(f'# {qn}. {head.get("title", slug)}\n\nURL when live: https://updateotter.com/{where}/ · {state}\n\n{review_text(body, data)}\n')
    if review:
        with open(review, 'w', encoding='utf-8') as fh:
            fh.write('\n\n---\n\n'.join(out) + '\n')
    for e in errors:
        print(e)
    print(f'{len(errors)} error(s)')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
