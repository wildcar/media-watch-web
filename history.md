# history — media-watch-web

Per-repo task log. Each code-change task adds a short entry **before** work
starts.

---

## 2026-04-25

### HTTP registration API

- Add `POST /api/register` and `DELETE /api/records/{id}` so the Telegram
  bot orchestrator can register downloaded torrents directly, without the
  CLI script.
- `path` accepts a file or a directory:
  - `kind=movie` + directory → pick the largest video file, ignoring
    `*sample*` matches.
  - `kind=series` + directory → recursively scan, parse `S01E02 / 1x02`
    from filenames, register one record per episode; files without a
    parsable episode marker are returned as `warnings[]`.
- Auto-generate ids: base = `imdb_id` (or slug from title), with
  `-sNNeMM` for series episodes, and a numeric suffix `-2`, `-3`, … on
  collision with a different file path. Same-path re-registration is
  idempotent (upsert).
- Bearer auth via `MEDIA_WATCH_API_TOKEN` (env or `api_token` in
  `config.local.php`); endpoint returns 503 when no token is configured.

---

## 2026-04-23

### Initial scaffold
- Create an Apache + PHP web application for browser playback links under
  `https://v.sitename.org`.
- Implement:
  - `watch.php` with Open Graph / Twitter Card tags and HTML5 `<video>`
  - `stream.php` with HTTP Range support and direct file streaming
  - SQLite-backed metadata registry
  - CLI registration / deletion scripts
  - Apache `.htaccess` rewrite rules and deploy documentation

