"""Checks item notes that Claude wrote without a person's review (Ali, 2026-10-26).

Usage: python3 tools/notes/check.py   (CI runs it; exit 1 on any error)

A row whose `notes_reviewed_by` is one of content/author.json `auto_check_ids` (e.g. "auto-check")
counts for indexing (gate I-3) without Ali's review, so CI must prove the note is safe:
- the row is live, has `notes__en` and a `notes_reviewed_on` date;
- the note passes the guides number lint (no hand-typed game numbers, no banned words);
- content/note-facts.json lists at least 2 facts for the record, at least one of them about the
  record itself, and every fact holds in today's CSVs (fact types as in tools/guides/check.py, plus
  ["from_egg", pet, egg]);
- every other pet, egg or biome the note names appears in one of its facts, so each comparison or
  "comes from" claim in the text is backed by a checked fact;
- the note is unique: 5-word-shingle Jaccard < 0.6 against every other note of the same entity,
  the same rule as the PHP gate (Gate\\Similarity).

Notes reviewed by a person (reviewer_ids) are not checked here.
"""
import html
import json
import os
import re
import sys

sys.dont_write_bytecode = True
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'tools', 'guides'))
import check as guides  # noqa: E402

DATE = re.compile(r'^\d{4}-\d{2}-\d{2}$')
NAMED = ('pets', 'eggs', 'biomes')
errors = []


def shingles(text, k=5):
    words = re.split(r'[^a-z0-9]+', re.sub(r'<[^>]+>', ' ', html.unescape(text)).lower())
    words = [w for w in words if w]
    if not words:
        return set()
    if len(words) < k:
        return {' '.join(words)}
    return {' '.join(words[i:i + k]) for i in range(len(words) - k + 1)}


def jaccard(a, b):
    if not a and not b:
        return 1.0
    inter = len(a & b)
    union = len(a) + len(b) - inter
    return inter / union if union else 0.0


def fact(data, f):
    if f[0] == 'from_egg':
        pet, egg = data.rec('pets', f[1]), data.rec('eggs', f[2])
        ok = any(d['egg_id'] == egg['id'] and d['pet_id'] == pet['id'] for d in data.t.get('egg_drops', []))
        return ok, f'{f[1]} is not in the drops of {f[2]}'
    return data.fact(f)


def named(data, text):
    """Slugs of the pets, eggs and biomes a note names, longest name first so "Fox Egg" is not also "Fox"."""
    names = []
    for kind in NAMED:
        for r in data.slug.get(kind, {}).values():
            for n in [r['name']] + [a.strip() for a in (r.get('aliases') or '').split('|') if a.strip()]:
                names.append((n, kind, r['slug']))
    names.sort(key=lambda x: -len(x[0]))
    low, out = text.lower(), set()
    for n, kind, slug in names:
        rx = re.compile(r'(?<![\w])' + re.escape(n.lower()) + r'(?![\w])')
        if rx.search(low):
            out.add((kind, slug))
            low = rx.sub(' ', low)
    return out


def main():
    author = json.load(open(os.path.join(ROOT, 'content', 'author.json'), encoding='utf-8'))
    auto = {str(v).strip().lower() for v in author.get('auto_check_ids', [])}
    path = os.path.join(ROOT, 'content', 'note-facts.json')
    facts_cfg = json.load(open(path, encoding='utf-8')) if os.path.isfile(path) else {}
    checked = 0
    for game, code in guides.GAME_DATA.items():
        if not os.path.isdir(os.path.join(ROOT, 'data', code)):
            continue
        data = guides.Data(code)
        gfacts = facts_cfg.get(game, {})
        for rid in gfacts:
            if rid.startswith('_'):
                continue
            r = data.by_id.get(rid)
            if r is None or (r.get('notes_reviewed_by') or '').strip().lower() not in auto:
                errors.append(f'{game} {rid}: facts listed in note-facts.json, but the record is missing or not "auto-check"')
        for table, rows in data.t.items():
            if not rows or 'notes__en' not in rows[0]:
                continue
            notes = {r['id']: shingles(r['notes__en']) for r in rows if (r.get('notes__en') or '').strip()}
            for r in rows:
                if (r.get('notes_reviewed_by') or '').strip().lower() not in auto:
                    continue
                checked += 1
                where = f'{game} {r["id"]} ({r.get("slug")})'
                text = r.get('notes__en') or ''
                if not text.strip():
                    errors.append(f'{where}: auto-check without notes__en')
                    continue
                if r.get('status') not in guides.LIVE:
                    errors.append(f'{where}: auto-check notes on a record that is not live')
                if not DATE.match(r.get('notes_reviewed_on') or ''):
                    errors.append(f'{where}: notes_reviewed_on must be a YYYY-MM-DD date')
                for i in guides.lint(text):
                    errors.append(f'{where}: lint {i}')
                fl = gfacts.get(r['id'], [])
                if len(fl) < 2:
                    errors.append(f'{where}: needs at least 2 facts in content/note-facts.json')
                covered = set()
                for f in fl:
                    try:
                        ok, why = fact(data, f)
                    except (KeyError, IndexError, ValueError, TypeError) as e:
                        errors.append(f'{where}: fact {f}: {e}')
                        continue
                    if not ok:
                        errors.append(f'{where}: fact {f} does not hold ({why}); rewrite the note')
                    covered.update(str(x) for x in f[1:])
                if r.get('slug') not in covered:
                    errors.append(f'{where}: no fact is about this record itself')
                for kind, slug in sorted(named(data, text)):
                    if slug != r.get('slug') and slug not in covered:
                        errors.append(f'{where}: the note names {kind[:-1]} "{slug}" but no fact backs it')
                mine = notes[r['id']]
                for oid, other in notes.items():
                    if oid != r['id'] and jaccard(mine, other) >= 0.6:
                        errors.append(f'{where}: note is too close to {oid} (Jaccard >= 0.6)')
    for e in errors:
        print(e)
    print(f'{checked} auto-check note(s), {len(errors)} error(s)')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
