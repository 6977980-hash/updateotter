"""Checks the bundled game data in data/<game>/ before it deploys (UO-67).

Usage: python3 tools/data/check.py   (CI runs it; exit 1 on any error)
Per CSV: it parses, every row has the header's column count, ids are unique and look like
GAME-ENT-0001, slugs are unique per file, and every *_id value that looks like an id points at
a record of the same game. approvals.json must be valid JSON. The importer does the full
validation on the server; this only stops broken files before they reach main.
provenance.csv rows may only cite the wiki, Roblox or the developer's own channels: a second
row there turns a value into "Matches 2 sources", and fan or guide sites never count.
"""
import csv
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ID = re.compile(r'^[A-Z0-9]+-[A-Z]{3}-\d{4}$')
# Sites a provenance row may cite. Add a developer channel here only when _meta.csv lists it.
PRV_SITES = ('fandom.com', 'roblox.com')
errors = []

for game_dir in sorted(glob.glob(os.path.join(ROOT, 'data', '*', ''))):
    game = os.path.basename(os.path.dirname(game_dir))
    ids = set()
    tables = {}
    for path in sorted(glob.glob(os.path.join(game_dir, '*.csv'))):
        name = os.path.relpath(path, ROOT)
        try:
            with open(path, encoding='utf-8', newline='') as f:
                rows = list(csv.reader(f))
        except (UnicodeDecodeError, csv.Error) as e:
            errors.append(f'{name}: cannot parse: {e}')
            continue
        if not rows:
            errors.append(f'{name}: empty file')
            continue
        head = rows[0]
        if len(set(head)) != len(head):
            errors.append(f'{name}: duplicate column names')
        tables[name] = (head, rows[1:])
        if 'id' not in head:
            continue
        i = head.index('id')
        slug = head.index('slug') if 'slug' in head else None
        slugs = set()
        for n, r in enumerate(rows[1:], start=2):
            if not any(c.strip() for c in r):
                continue
            if len(r) != len(head):
                errors.append(f'{name} row {n}: {len(r)} columns, header has {len(head)}')
                continue
            rid = r[i].strip()
            if not ID.match(rid):
                errors.append(f'{name} row {n}: bad id {rid!r}')
            elif rid in ids:
                errors.append(f'{name} row {n}: duplicate id {rid}')
            ids.add(rid)
            if slug is not None and r[slug].strip():
                if r[slug] in slugs:
                    errors.append(f'{name} row {n}: duplicate slug {r[slug]!r}')
                slugs.add(r[slug])
    for name, (head, rows) in tables.items():
        refs = [k for k, h in enumerate(head) if h.endswith('_id') and h != 'id']
        for n, r in enumerate(rows, start=2):
            if len(r) != len(head):
                continue
            for k in refs:
                for v in re.split(r'[,|;\s]+', r[k].strip()):
                    if ID.match(v) and v not in ids:
                        errors.append(f'{name} row {n}: {head[k]} points at unknown id {v}')
    prv = tables.get(os.path.join('data', game, 'provenance.csv'))
    meta = tables.get(os.path.join('data', game, '_meta.csv'))
    official = set()
    if meta and 'key' in meta[0] and 'value' in meta[0]:
        for r in meta[1]:
            if len(r) == len(meta[0]) and r[meta[0].index('key')] == 'official_channels':
                official = {re.sub(r'^www\.', '', re.sub(r'^https?://', '', u).split('/')[0]) for u in r[meta[0].index('value')].split('|') if u.strip()}
    if prv and 'source_url' in prv[0]:
        k = prv[0].index('source_url')
        for n, r in enumerate(prv[1], start=2):
            if len(r) != len(prv[0]) or not r[k].strip():
                continue
            host = re.sub(r'^www\.', '', re.sub(r'^https?://', '', r[k].strip()).split('/')[0].lower())
            if host not in official and not any(host == s or host.endswith('.' + s) for s in PRV_SITES):
                errors.append(f'data/{game}/provenance.csv row {n}: source {host} is not the wiki, Roblox or an official channel')
    ap = os.path.join(game_dir, 'approvals.json')
    if os.path.exists(ap):
        try:
            json.load(open(ap, encoding='utf-8'))
        except ValueError as e:
            errors.append(f'{os.path.relpath(ap, ROOT)}: invalid JSON: {e}')

if errors:
    print('\n'.join(errors))
    sys.exit(1)
print('ok')
