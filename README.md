# UpdateOtter

Standalone WordPress theme for updateotter.com (fan-made calculators and wikis for Roblox games).

Branches:

| Branch | Deploys to (Hostinger Git) |
|---|---|
| `main` | `public_html/wp-content/themes/updateotter` |
| `rbx-data` | `public_html/wp-content/plugins/rbx-data` |
| `rbx-tools` | `public_html/wp-content/plugins/rbx-tools` |
| `rbx-notifier` | `public_html/wp-content/plugins/rbx-notifier` |

Theme layout:

- `functions.php` loads `inc/` (setup, assets, head tags, root files, pre-launch lock, navigation, ads, template tags).
- CSS sources are `assets/css/{tokens,base,layout,components}.css`. Run `sh tools/build-css.sh` after editing them; CI fails if `site.css` is stale. Only `min-width` media queries (mobile first).
- `root-files/` holds favicons, the web manifest and OG images; the theme serves them at the site root.
- `patterns/` holds the editor patterns (FAQ, how we calculated, comparison table, scam warning, update summary).

Pre-launch lock: while the `rbx_prelaunch_lock` option is on (default after the RBX Data plugin is activated), logged-out visitors see a "Coming soon" page, every response sends `X-Robots-Tag: noindex, nofollow`, and robots.txt disallows everything. Turn it off at launch (Phase 1c) on RBX Data → Setup or with `wp rbx prelaunch off`.
