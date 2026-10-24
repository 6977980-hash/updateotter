"""Builds the per-page share images (GR-04, Phase E) in assets/og/.

Usage: python3 tools/og/make.py   (needs Pillow and the Inter Display font)

One 1200x630 JPEG per site page in content/pages/{game}/ (hub, tools, lists, codes, guides), in
the brand style of root-files/og-*.jpg, plus one card per item that has a credited picture in
data/{game}/media.csv (the picture sits on the card with its credit). The theme picks
assets/og/{page path}.jpg when it exists, else the game's root-files/og-{game}.jpg
(Seo::og_url). Re-run after adding a page, a guide or an item picture, and commit the output.
"""
import csv
import json
import os
import re

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, 'assets', 'og')
FONT = '/usr/share/fonts/opentype/inter/InterDisplay-Bold.otf'
PRIMARY = (80, 50, 200)
SOFT = (217, 209, 255)
W, H = 1200, 630
GAMES = {'steal-an-egg': 'sae', 'grow-a-garden': 'gag', 'grow-a-garden-2': 'gag2', 'fish-it': 'fish'}
# Page kinds by last path part: share title suffix and subtitle.
KIND = {
    'calculator': ('Calculator', 'Income from base value, mutation and weight'),
    'speed-calculator': ('Speed Calculator', 'Which biomes your Speed can reach'),
    'codes': ('Codes', 'When we last checked and what really works'),
    'eggs': ('Eggs List', 'Every egg with hatch time, zone and pets'),
    'pets': ('Pets List', 'Every pet by rarity, with base income'),
    'mutations': ('Mutations', 'Every mutation and its multiplier'),
    'tier-list': ('Tier List', 'Permanent pets ranked by income'),
    'updates': ('Updates', 'Every game update, with dates'),
    'guides': ('Guides', 'Player guides, checked against the data'),
    'plants': ('Crops List', 'Every crop with its sell value'),
    'fish': ('Fish List', 'Every fish by rarity'),
    'rods': ('Rods List', 'Every rod and its stats'),
    'baits': ('Baits List', 'Every bait and its stats'),
}
KIND_ID = {
    'calculator': ('Kalkulator', 'Pendapatan dari nilai dasar, mutasi dan berat'),
    'codes': ('Kode', 'Kapan terakhir dicek dan apa yang benar-benar berfungsi'),
    'eggs': ('Daftar Telur', 'Semua telur dengan waktu menetas, zona dan pet'),
    'pets': ('Daftar Pet', 'Semua pet menurut kelangkaan'),
    'mutations': ('Mutasi', 'Semua mutasi dan pengalinya'),
    'tier-list': ('Tier List', 'Pet permanen diurutkan menurut pendapatan'),
}
HUB_SUB = {
    'steal-an-egg': 'Calculator · Eggs · Pets · Codes · Updates',
    'grow-a-garden': 'Calculator · Crops · Mutations · Codes',
    'grow-a-garden-2': 'Calculator · Crops · Mutations · Codes',
    'fish-it': 'Fish · Rods · Baits · Mutations',
}

_base = None


def font(size):
    return ImageFont.truetype(FONT, size)


def base():
    """Brand background with the lockup and footer line taken from the game share image."""
    global _base
    if _base is None:
        im = Image.new('RGB', (W, H), PRIMARY)
        over = Image.new('RGBA', (W, H), (0, 0, 0, 0))
        d = ImageDraw.Draw(over)
        d.ellipse((1080 - 260, 560 - 260, 1080 + 260, 560 + 260), fill=(255, 255, 255, 15))
        d.ellipse((1130 - 120, 90 - 120, 1130 + 120, 90 + 120), fill=(255, 255, 255, 13))
        im = Image.alpha_composite(im.convert('RGBA'), over)
        src = Image.open(os.path.join(ROOT, 'root-files', 'og-steal-an-egg.jpg')).convert('RGBA')
        im.paste(src.crop((80, 70, 520, 180)), (80, 70))
        im.paste(src.crop((80, 540, 540, 590)), (80, 540))
        _base = im
    return _base.copy()


def wrap(text, f, width):
    lines, cur = [], ''
    for w in text.split():
        t = (cur + ' ' + w).strip()
        if f.getlength(t) <= width or not cur:
            cur = t
        else:
            lines.append(cur)
            cur = w
    return lines + ([cur] if cur else [])


def card(title, sub, path, picture=None, credit=''):
    im = base()
    d = ImageDraw.Draw(im)
    width = 640 if picture else 1000
    size = 84
    while True:
        f = font(size)
        lines = wrap(title, f, width)
        if len(lines) <= 2 or size <= 56:
            break
        size -= 6
    y = 250 if len(lines) == 1 else 220
    for line in lines[:2]:
        d.text((90, y), line, font=f, fill='white')
        y += int(size * 1.18)
    fs = font(32)
    for line in wrap(sub, fs, width)[:2]:
        d.text((90, y + 14), line, font=fs, fill=SOFT)
        y += 42
    if picture:
        pic = Image.open(picture).convert('RGBA')
        box = 340
        panel = Image.new('RGBA', (box + 40, box + 40), (0, 0, 0, 0))
        ImageDraw.Draw(panel).rounded_rectangle((0, 0, box + 39, box + 39), 28, fill='white')
        scale = min(box / pic.width, box / pic.height)
        pic = pic.resize((max(1, round(pic.width * scale)), max(1, round(pic.height * scale))), Image.LANCZOS)
        panel.paste(pic, (20 + (box - pic.width) // 2, 20 + (box - pic.height) // 2), pic)
        im.paste(panel, (770, 140), panel)
        if credit:
            fc = font(17)
            for i, line in enumerate(wrap(credit, fc, 380)[:2]):
                d.text((770, 536 + i * 24), line, font=fc, fill=SOFT)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    im.convert('RGB').save(path, 'JPEG', quality=82, optimize=True, progressive=True)


def header(file):
    with open(file, encoding='utf-8') as fh:
        m = re.match(r'<!-- uo:page (\{.*?\}) -->', fh.readline())
    return json.loads(m.group(1)) if m else {}


def main():
    made = []
    guides = json.load(open(os.path.join(ROOT, 'content', 'guides.json'), encoding='utf-8'))
    pages = os.path.join(ROOT, 'content', 'pages')
    for lang in ('', 'id/'):
        for folder in GAMES:
            name = {'fish-it': 'Fish It!'}.get(folder, folder.replace('-', ' ').title().replace(' An ', ' an ').replace(' A ', ' a '))
            hub = os.path.join(pages, lang + folder + '.html')
            if not os.path.exists(hub):
                continue
            sub = HUB_SUB[folder] if not lang else 'Kalkulator · Telur · Pet · Kode'
            card(name, sub, os.path.join(OUT, lang + folder + '.jpg'))
            made.append(lang + folder)
            for root, _, files in os.walk(os.path.join(pages, lang + folder)):
                for fn in sorted(files):
                    rel = os.path.relpath(os.path.join(root, fn), pages)[:-5]
                    last = rel.split('/')[-1]
                    if '/guides/' in rel:
                        g = next((x for x in guides.get(folder, {}).get('guides', []) if x['slug'] == last), None)
                        title = (g or {}).get('label') or header(os.path.join(root, fn)).get('title') or last
                        card(title.split(':')[0], name + ' guide', os.path.join(OUT, rel + '.jpg'))
                    else:
                        k = (KIND_ID if lang else KIND).get(last)
                        if not k:
                            continue
                        card(name + ' ' + k[0], k[1], os.path.join(OUT, rel + '.jpg'))
                    made.append(rel)
    # Item cards: items with a picture (own screenshot of the item alone, or a credited wiki image).
    for folder, code in GAMES.items():
        mfile = os.path.join(ROOT, 'data', code, 'media.csv')
        if not os.path.exists(mfile):
            continue
        name = {'fish-it': 'Fish It!'}.get(folder, folder.replace('-', ' ').title().replace(' An ', ' an ').replace(' A ', ' a '))
        records = {}
        for f in ('pets', 'eggs', 'mutations', 'plants'):
            p = os.path.join(ROOT, 'data', code, f + '.csv')
            if os.path.exists(p):
                for r in csv.DictReader(open(p, encoding='utf-8')):
                    records[r['id']] = (f, r)
        for m in csv.DictReader(open(mfile, encoding='utf-8')):
            ids = [x for x in m['depicts_record_ids'].split('|') if x]
            if m['status'] != 'live' or len(ids) != 1 or m['shows_fields'] or ids[0] not in records:
                continue
            f, r = records[ids[0]]
            if r.get('status') != 'live':
                continue
            credit = ''
            if m['image_source'] == 'wiki_cc':
                lic = 'CC BY-SA 3.0' if m['license_note'].startswith('CC BY-SA') else 'public domain'
                credit = 'Image: ' + m['captured_by'] + ', ' + name + ' Wiki, ' + lic
            kind = {'pets': 'pet', 'eggs': 'egg', 'mutations': 'mutation', 'plants': 'crop'}[f]
            rel = folder + '/' + f + '/' + r['slug']
            card(r['name'], name + ' ' + kind + ': stats, income and where to get it', os.path.join(OUT, rel + '.jpg'),
                 os.path.join(ROOT, 'data', code, 'media', m['file_name']), credit)
            made.append(rel)
    print(len(made), 'share images in assets/og/')


if __name__ == '__main__':
    main()
