# Game data

Each game's dataset lives here as one CSV file per tab (`data/{game}/{tab}.csv`, headers from `wp rbx sheet-template`). This replaces the Google Sheet (owner decision 2026-10-23).

- After a deploy, the site notices that the files changed and imports them in the background. The dry run must have 0 errors, and every warning class and large change must be approved in `data/{game}/approvals.json`. Anything else stays on RBX Data → Import for a person to look at.
- Screenshots go in `data/{game}/media/`. They are copied into the media library once and matched by file name (the `media` tab's `file_name`).
- Status: `/wp-json/rbx/v1/bundled` (public, read-only). `wp rbx bundled --game=sae [--dry-run]` runs the import by hand.
- Values from the Fandom wiki are `source_type=community`, so the site shows them as "Unconfirmed" and their pages stay noindex. In-game checks change them to `in_game` and add a screenshot in `evidence_media_id`.
