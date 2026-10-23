# Game data

Each game's dataset lives here as one CSV file per tab (`data/{game}/{tab}.csv`, headers from `wp rbx sheet-template`). This replaces the Google Sheet (owner decision 2026-10-23).

- After a deploy, the site notices that the files changed and imports them in the background. The dry run must have 0 errors, and every warning class and large change must be approved in `data/{game}/approvals.json`. Anything else stays on RBX Data → Import for a person to look at.
- Screenshots go in `data/{game}/media/`. They are copied into the media library once and matched by file name (the `media` tab's `file_name`).
- Status: `/wp-json/rbx/v1/bundled` (public, read-only). `wp rbx bundled --game=sae [--dry-run]` runs the import by hand.
- Values from the Fandom wiki are `source_type=community`, so the site shows them as "Unconfirmed" and their pages stay noindex. In-game checks change them to `in_game` and add a screenshot in `evidence_media_id`.

## Page copy

The text of the site Pages (home, game hub, list pages, trust and policy pages) lives in `content/pages/{path}.html` as block markup. After a deploy it is written into the matching WordPress Page. A Page that someone edited in WordPress after the last sync is not overwritten (`wp rbx pages-sync --force` overwrites it). Status: the `_pages` entry of `/wp-json/rbx/v1/bundled`. Page status (draft or published) is never changed by the sync.

## Local copy for dry runs

`sh tools/local-wp.sh /tmp/uo-wp` builds a local WordPress + SQLite site with this theme. Then run `php /tmp/uo-wp.wp-cli.phar --allow-root --path=/tmp/uo-wp rbx bundled --game=sae --dry-run` and `... rbx pages-sync`, and serve it with `php -S localhost:8080 -t /tmp/uo-wp`.
