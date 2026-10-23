# UpdateOtter

Standalone WordPress theme for updateotter.com (fan-made calculators and wikis for Roblox games).

Deploy: `main` is the only deploy branch. Hostinger Git deploys it to `public_html/wp-content/themes/updateotter`. Every merge to `main` goes live on the server (the pre-launch lock still hides the site until launch).

The site's own code ships inside the theme as modules (no separate plugins to install):

| Folder | What it does |
|---|---|
| `modules/rbx-data` | Game data: Sheet import, validation, changelog, page gate, exports, data blocks, Setup screen |
| `modules/rbx-tools` | Calculators |
| `modules/rbx-notifier` | Roblox update alerts and the per-update checklist |

`inc/modules.php` loads them and runs their install steps (tables, roles, rewrite rules, cron) once per `UPDATEOTTER_MODULES_VERSION`; bump that constant when a module needs its install steps re-run. If a module is also active as a separate plugin, the plugin copy wins and an admin notice asks to remove it. The old `rbx-data`, `rbx-tools` and `rbx-notifier` branches are frozen and not deployed.

Theme layout:

- `functions.php` loads `inc/` (modules, setup, assets, head tags, root files, pre-launch lock, navigation, ads, template tags).
- CSS sources are `assets/css/{tokens,base,layout,components}.css`. Run `sh tools/build-css.sh` after editing them; CI fails if `site.css` is stale. Only `min-width` media queries (mobile first).
- `root-files/` holds favicons, the web manifest and OG images; the theme serves them at the site root.
- `patterns/` holds the editor patterns (FAQ, how we calculated, comparison table, scam warning, update summary).

Pre-launch lock: while the `rbx_prelaunch_lock` option is on (on by default), logged-out visitors see a "Coming soon" page, every response sends `X-Robots-Tag: noindex, nofollow`, and robots.txt disallows everything. Turn it off at launch (Phase 1c) on RBX Data → Setup or with `wp rbx prelaunch off`.
