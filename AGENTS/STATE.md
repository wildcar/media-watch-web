# State

Repo-local snapshot. Overwrite each iteration. Cross-repo view → `../AGENTS/STATE.md`.

## Goal

Browser playback pages for downloaded media on the media host: `/watch/<id>` +
`/series/<id>` with Telegram-friendly OG previews, range/X-Sendfile streaming, and
an HTTP register/sweep API driven by the bot — no transcode, no Telegram upload.

## Now

- Deployed at `/opt/media-watch-web` on the media host (`v.wildcar.ru`), wired to
  the bot's completion poller (register on download complete, prune on sweep).
- Movie/series/cartoon register, remux-to-mp4 (stereo AAC), X-Sendfile downloads,
  OG/Telegram previews, and the hourly `media-watch-sweep.timer` are all live.
- Harness migrated to the `agent-template` layout (PHP-flavoured).

## Next

- (when needed) nothing pending; the app follows whatever the bot registers.

## Open questions

- —

## Deferred

- **Watch-page access tokens with TTL.** Watch URLs are guessable (public path =
  `media_id`). Add a `tokens` table + `POST /api/tokens` (Bearer-auth) returning
  `/watch/<id>?token=…`; `watch.php`/`stream.php` require the token when one exists
  for a record; lazy-purge expired rows. Back-compat: records without a token row
  keep working. Deferred — all users share one Telegram chat today.
