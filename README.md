# UpdateOtter

Standalone WordPress theme for updateotter.com (fan-made calculators and wikis for Roblox games).

Deploy: `main` is the only deploy branch. Hostinger Git deploys it to `public_html/wp-content/themes/updateotter`. Every merge to `main` goes live on the server (the pre-launch lock still hides the site until launch).

The site's own code ships inside the theme as modules (no separate plugins to install):

| Folder | What it does |
|---|---|
| `modules/rbx-data` | Game data: import (bundled CSVs in `data/`, see `data/README.md`), validation, changelog, page gate, exports, data blocks, Setup screen |
| `modules/rbx-tools` | Calculators |
| `modules/rbx-notifier` | Roblox update alerts and the per-update checklist |

`inc/modules.php` loads them and runs their install steps (tables, roles, rewrite rules, cron) once per `UPDATEOTTER_MODULES_VERSION`; bump that constant when a module needs its install steps re-run. If a module is also active as a separate plugin, the plugin copy wins and an admin notice asks to remove it. The old `rbx-data`, `rbx-tools` and `rbx-notifier` branches are frozen and not deployed.

Theme layout:

- `functions.php` loads `inc/` (modules, setup, assets, head tags, root files, pre-launch lock, navigation, ads, template tags).
- CSS sources are `assets/css/{tokens,base,layout,components}.css`. Run `sh tools/build-css.sh` after editing them; CI fails if `site.css` is stale. Only `min-width` media queries (mobile first).
- `root-files/` holds favicons, the web manifest and OG images; the theme serves them at the site root.
- `patterns/` holds the editor patterns (FAQ, how we calculated, comparison table, scam warning, update summary).

Pre-launch lock: while the `rbx_prelaunch_lock` option is on (on by default), logged-out visitors see a "Coming soon" page, every response sends `X-Robots-Tag: noindex, nofollow`, and robots.txt disallows everything. Turn it off at launch (Phase 1c) on RBX Data → Setup or with `wp rbx prelaunch off`.

Ads (Phase 1d): `content/ads.json` holds the AdSense publisher ID, the "approved" switch and the manual slot IDs. With only the publisher ID set, the home page gets the `google-adsense-account` tag and `/ads.txt` is served; slots and the ad script render only when `approved` is `true`. Every unit carries `data-tag-for-age-treatment="1"`; Auto ads stay off in the AdSense account. Place a slot inside page content with `[uo_ad slot="below-tool"]`. Bump the `purge` id in `content/launch.json` after changing the file.

Indonesian pages (Phase 3): every page under `content/pages/id/` becomes `/id/...`, the Indonesian twin of the English page at the same path. `RBX\Data\Content\Lang` switches `/id/` requests to the `id_ID` locale (UI strings from `languages/*-id_ID.l10n.php`, built from `tools/i18n/id.py` with `python3 tools/i18n/build.py`; CI fails if they are stale or a key no longer exists in the code), uses decimal commas, translates game-config labels through `content/i18n/id.json`, links game pages to their Indonesian twins, and prints hreflang `en`/`id`/`x-default` on both pages of each pair. Item pages and policy pages stay English. No translation plugin is used.

Guides (2026-10-24): each guide is a page file under `content/pages/{game}/guides/` with a `uo:page` header (`"template": "guide"`), listed in `content/guides.json`. A guide with `"queue": true` stays a draft; from `start` one weekly slot opens (Saturday 06:00 UTC for Steal an Egg) and the first queued guide in list order with `reviewed_by` set goes live in it (`RBX\Data\Content\Guides`). Before it publishes, the page must be synced, pass the number lint and every `[rbx_value]` must resolve. Links to a guide that is not live render as plain text, and `[uo_guides game="..."]` lists the live guides (hub page and "More guides" at the end of each guide), so older guides link to a new one as soon as it is live. `python3 tools/guides/check.py` (CI) checks every guide against the CSVs, including the `facts` each guide claims (for example "the Axolotl is the Lake's Legendary"); a data change that makes a claim untrue fails CI until the line is rewritten. `--review FILE` writes a readable copy with today's numbers filled in. Queue status: `GET /wp-json/rbx/v1/guides`.

Images (Phase E, 2026-10-24): item pictures are rows in `data/{game}/media.csv` with the file in `data/{game}/media/`. Besides our own screenshots, `image_source=wiki_cc` allows a wiki picture, but only one whose wiki file page carries a licence tag (`{{Self}}` = CC BY-SA 3.0, or `{{PD}}`); untagged files are never used (owner decision). Such a row needs `license_note` and `source_url` (the wiki file page); the item page shows the credit under the picture and `/image-credits/` lists them all. Wiki pictures never count as first-hand evidence for the index gate (I-5). Share images: `python3 tools/og/make.py` builds `assets/og/{page path}.jpg` for hubs, tools, lists, guides and pictured items; `Seo::page_og()` uses that file when it exists, else the game's `root-files/og-{game}.jpg`. Re-run it after adding a page, guide or picture.
