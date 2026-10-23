"""Builds tests/fixtures/sae-fixture.zip: a small, obviously fake SAE dataset for local pipeline tests.

Never import it on the live site. Names start with "Fixture" so a leak is easy to spot.
Usage: python3 tests/fixtures/build.py TEMPLATE.xlsx [--variant=2]
  TEMPLATE.xlsx comes from `wp rbx sheet-template --game=sae`, so headers always match the config.
  variant 2 changes values (large change, removed pet, slug rename) to test diffs and rollback.
"""
import csv, io, sys, zipfile, os
import openpyxl

HEAD = {ws.title: [c for c in next(ws.iter_rows(values_only=True)) if c] for ws in openpyxl.load_workbook(sys.argv[1]).worksheets}

V2 = '--variant=2' in sys.argv
D = '2026-10-20'
SRC = dict(status='live', source_type='in_game', evidence_media_id='SAE-MED-0001', last_verified=D, verified_by='Ali')

def rows(tab, cols, items, _=None):
    out = io.StringIO()
    w = csv.DictWriter(out, fieldnames=HEAD[tab], extrasaction='ignore')
    w.writeheader()
    for it in items:
        w.writerow({**SRC, **it})
    return out.getvalue()

B = ['status', 'source_type', 'source_url', 'evidence_media_id', 'source_note', 'last_verified', 'verified_by', 'added_in_update', 'removed_in_update', 'change_update_id', 'slug_history']
tabs = {}
tabs['_meta'] = 'key,value,source_type,source_url,last_verified,verified_by\n' + '\n'.join([
    'game_code,SAE', 'schema_version,1.0', 'roblox_universe_id,1234567890', 'roblox_place_id,9876543210',
    'game_name,Steal an Egg', 'developer_name,Fixture Studio', 'redeem_ui_exists,no',
    'calc_defaults,"{""calculator"":{""pet"":""SAE-PET-0002"",""count"":1}}"',
]) + '\n'
tabs['currencies'] = rows('currencies', ['id', 'slug', 'name', 'symbol_text', 'is_premium'] + B, [
    dict(id='SAE-CUR-0001', slug='cash', name='Fixture Cash', symbol_text='$', is_premium='FALSE')], 'currencies')
tabs['rarities'] = rows('rarities', ['id', 'slug', 'name', 'sort_order', 'ui_color_hex'] + B, [
    dict(id='SAE-RAR-0001', slug='common', name='Fixture Common', sort_order='1'),
    dict(id='SAE-RAR-0002', slug='legendary', name='Fixture Legendary', sort_order='5')], 'rarities')
tabs['biomes'] = rows('biomes', ['id', 'slug', 'name', 'order_index', 'unlock_requirement_text__en'] + B, [
    dict(id='SAE-BIO-0001', slug='meadow', name='Fixture Meadow', order_index='1', unlock_requirement_text__en='Open from the start.')], 'biomes')
tabs['eggs'] = rows('eggs', ['id', 'slug', 'name', 'rarity_id', 'biome_ids', 'cost', 'currency_id', 'hatch_time_s', 'is_lab_egg', 'obtain_method', 'odds_published'] + B, [
    dict(id='SAE-EGG-0001', slug='fixture-egg', name='Fixture Egg', rarity_id='SAE-RAR-0001', biome_ids='SAE-BIO-0001', cost='1500', currency_id='SAE-CUR-0001', hatch_time_s='30', is_lab_egg='FALSE', obtain_method='biome_spawn', odds_published='FALSE')], 'eggs')
pets = [
    dict(id='SAE-PET-0001', slug='fixture-chick', name='Fixture Chick', rarity_id='SAE-RAR-0001', base_income_per_s='10', currency_id='SAE-CUR-0001', base_speed='16', is_secret='FALSE', is_mountable='FALSE', tier_list_rank='C'),
    dict(id='SAE-PET-0002', slug='fixture-dragon', name='Fixture Dragon', rarity_id='SAE-RAR-0002', base_income_per_s='2500' if not V2 else '9000', currency_id='SAE-CUR-0001', base_speed='24', is_secret='FALSE', is_mountable='TRUE', tier_list_rank='S'),
    dict(id='SAE-PET-0003', slug='fixture-duck' if not V2 else 'fixture-golden-duck', name='Fixture Duck', rarity_id='SAE-RAR-0001', base_income_per_s='12', currency_id='SAE-CUR-0001', tier_list_rank='B', slug_history='' if not V2 else ''),
]
if not V2:
    pets.append(dict(id='SAE-PET-0004', slug='fixture-old-cat', name='Fixture Old Cat', rarity_id='SAE-RAR-0001', base_income_per_s='5', currency_id='SAE-CUR-0001', tier_list_rank='D'))
tabs['pets'] = rows('pets', None, pets)
tabs['egg_drops'] = rows('egg_drops', ['id', 'egg_id', 'pet_id', 'chance_prob', 'observed_rate', 'sample_size'] + B, [
    dict(id='SAE-DRP-0001', egg_id='SAE-EGG-0001', pet_id='SAE-PET-0001', chance_prob='0.9'),
    dict(id='SAE-DRP-0002', egg_id='SAE-EGG-0001', pet_id='SAE-PET-0002', chance_prob='0.1')], 'pets')
tabs['mutations'] = rows('mutations', ['id', 'slug', 'name', 'applies_to', 'income_mult', 'stack_rule', 'obtain_method_text__en'] + B, [
    dict(id='SAE-MUT-0001', slug='golden', name='Fixture Golden', applies_to='pet', income_mult='2', stack_rule='multiply', obtain_method_text__en='Random when hatching.')], 'mutations')
tabs['sizes'] = rows('sizes', ['id', 'slug', 'name', 'order_index', 'income_mult'] + B, [
    dict(id='SAE-SIZ-0001', slug='big', name='Fixture Big', order_index='2', income_mult='1.5')], 'sizes')
tabs['updates'] = rows('updates', ['id', 'slug', 'title__en', 'release_date', 'summary__en', 'affected_record_ids'] + B, [
    dict(id='SAE-UPD-0001', slug='2026-10-18-fixture-update', title__en='Fixture Update 1', release_date='2026-10-18', summary__en='Added the fixture dragon.', affected_record_ids='SAE-PET-0002')], 'updates')
tabs['codes'] = rows('codes', ['id', 'code', 'reward_text__en', 'code_status', 'first_seen', 'last_tested_at', 'tested_by'] + B, [
    dict(id='SAE-COD-0001', code='FIXTURE100', reward_text__en='100 Fixture Cash', code_status='active', first_seen='2026-10-18', last_tested_at='2026-10-22 10:00', tested_by='Ali', source_type='official', source_url='https://www.roblox.com/games/9876543210')], 'codes')
tabs['mechanics'] = rows('mechanics', ['id', 'mechanic_key', 'status', 'description__en', 'definition__en', 'in_glossary', 'source_type', 'last_verified', 'verified_by'], [
    dict(id='SAE-MEC-0001', mechanic_key='income_formula', status='confirmed', definition__en='Income is base income times the mutation and size multipliers.', in_glossary='TRUE'),
    dict(id='SAE-MEC-0002', mechanic_key='mutation_multiplier', status='confirmed', definition__en='A mutation multiplies income.', in_glossary='TRUE'),
    dict(id='SAE-MEC-0003', mechanic_key='size_multiplier', status='confirmed', definition__en='Size multiplies income.', in_glossary='FALSE'),
    dict(id='SAE-MEC-0004', mechanic_key='mutation_stacking', status='unconfirmed', definition__en='Not tested yet.', in_glossary='FALSE')], 'mechanics')
inputs = '{"pet":{"type":"ref","target":"PET"},"mutation":{"type":"ref","target":"MUT"},"size":{"type":"ref","target":"SIZ"},"count":{"type":"number"}}'
expr = 'ref(PET, pet).base_income_per_s * if(has(mutation), ref(MUT, mutation).income_mult, 1) * if(has(size), ref(SIZ, size).income_mult, 1) * count'
tabs['formulas'] = rows('formulas', ['id', 'key', 'tool', 'label__en', 'expression', 'inputs', 'output_unit__en', 'display_format', 'sort_order', 'requires_mechanics', 'version', 'status', 'source_type', 'last_verified', 'verified_by', 'evidence_media_id'], [
    dict(id='SAE-FRM-0001', key='income_per_s', tool='calculator', label__en='Income per second', expression=expr, inputs=inputs, output_unit__en='per second', display_format='abbr', sort_order='1', requires_mechanics='income_formula', version='1')], 'formulas')
tabs['formula_tests'] = rows('formula_tests', ['id', 'formula_id', 'inputs', 'expected', 'tolerance_abs', 'tolerance_rel', 'evidence_media_id', 'verified_by', 'status'], [
    dict(id='SAE-TST-0001', formula_id='SAE-FRM-0001', inputs='{"pet":"fixture-chick","count":1}', expected='10', evidence_media_id='SAE-MED-0001'),
    dict(id='SAE-TST-0002', formula_id='SAE-FRM-0001', inputs='{"pet":"fixture-chick","mutation":"golden","count":1}', expected='20', evidence_media_id='SAE-MED-0001'),
    dict(id='SAE-TST-0003', formula_id='SAE-FRM-0001', inputs='{"pet":"fixture-chick","mutation":"golden","size":"big","count":2}', expected='60', evidence_media_id='SAE-MED-0001')], 'formula_tests')
tabs['media'] = rows('media', ['id', 'file_name', 'alt__en', 'captured_on', 'captured_by', 'depicts_record_ids', 'contains_usernames', 'image_source', 'image_credit', 'status'], [
    dict(id='SAE-MED-0001', file_name='sae-fixture-chick.png', alt__en='Fixture Chick income in the game', captured_on=D, captured_by='Ali', depicts_record_ids='SAE-PET-0001', contains_usernames='FALSE', image_source='own_screenshot', image_credit='Screenshot by UpdateOtter')], 'media')
for t in HEAD:
    if t not in tabs and not t.startswith('_'):
        tabs[t] = ','.join(HEAD[t]) + '\n'
tabs['_internal'] = 'note\nprivate lead, must be ignored\n'

name = 'sae-fixture-v2.zip' if V2 else 'sae-fixture.zip'
path = os.path.join(os.path.dirname(os.path.abspath(__file__)), name)
with zipfile.ZipFile(path, 'w') as z:
    for t, body in tabs.items():
        z.writestr(t + '.csv', body)
print(path)
