# history — media-watch-web

Per-repo task log. Each code-change task adds a short entry **before** work
starts.

---

## 2026-04-25

### Browser-playable proxy for non-mp4 originals

- The watch page now serves the **original** file for download in
  every case (unchanged), but renders the embedded `<video>` against
  whatever is actually playable in browsers:
  - Original is browser-friendly (`mp4`/`m4v`/`webm`) → play it.
  - Original is `mkv`/etc but the video codec is H.264/HEVC →
    asynchronously remux to mp4 in `/mnt/storage/Media/Video/Share/<id>.mp4`
    (audio re-encoded to AAC only when source was AC3/DTS/etc),
    then play that file. Until ready: page shows "Идёт подготовка
    к воспроизведению…".
  - Anything else → page renders "Воспроизведение данного файла
    здесь невозможно" instead of the player.
- New columns on `records`: `share_path`, `remux_status`
  (`not_needed | pending | running | ready | failed`),
  `remux_error`. Idempotent migrations as before.
- `bin/remux-worker.php <id>` is the background worker; spawned by
  `Storage::upsert` via `proc_open` + detached so the HTTP request
  returns immediately. The worker holds an advisory `flock` on the
  output file so a duplicate spawn no-ops cleanly.
- `stream.php?share=1` serves the remuxed file with `Content-Type:
  video/mp4`. Original `/stream/<id>` is unchanged.
- No subtitle extraction, no original-file mutation, no rtorrent
  side-effects. Per the user's revised spec.

---

## 2026-04-25

### ffprobe video dimensions for og:video:width/height

- Telegram refuses to render an inline player from `og:video` alone —
  it needs `og:video:width` and `og:video:height` declared on the
  watch page. Without them clients fall back to "just a link", which
  is exactly what we observed on `https://v.wildcar.ru/watch/tt26443597`
  even after `@WebpageBot` cache refresh.
- On register: run `ffprobe -v error -select_streams v:0 -show_entries
  stream=width,height -show_entries format=duration` against the
  resolved file. Persist `video_width`, `video_height`,
  `duration_seconds` columns. ffprobe failures are non-fatal — we just
  store zeros and skip emitting the tags.
- `watch.php` now emits `og:video:width`/`og:video:height` (and a
  matching `twitter:player:width`/`height`) when the columns are
  populated.
- `bin/backfill-dimensions.php` walks existing rows with `width=0`
  and probes them in-place.

---

## 2026-04-25

### Emit og:video / twitter:player tags

- Add `og:video`, `og:video:url`, `og:video:secure_url`, `og:video:type`
  and the matching `twitter:player*` tags on the watch page, pointing
  at `/stream/<id>`.
- Emitted unconditionally for every record; clients (Telegram in
  particular) decide whether to render an inline player based on the
  declared content type. For containers Telegram can't play (mkv,
  avi, …) it gracefully falls back to the existing image preview.
- Width/height are deferred — see workspace-root `AGENTS-TODO.md`.

---

## 2026-04-25

### Replace bottom watch link with a download button

- Replace the "Ссылка: <watch_url>" line at the bottom of the watch
  page with a 📥 «Скачать файл» button that hits
  `/stream/<id>?download=1`.
- `Streamer::send` now takes an `$asAttachment` flag and emits
  `Content-Disposition: attachment` instead of `inline` when set.
- For containers the browser doesn't natively play (`.mkv`, `.avi`,
  `.ts`, `.mpg`, `.wmv`, `.flv`), the page also shows a hint
  recommending VLC and naming the offending extension. Whitelist of
  natively-playable containers: mp4, m4v, webm, ogg, ogv, mov.

### Document php-sqlite3 prerequisite

Add an explicit "PHP extensions" section to `deploy/README.md`. Missing
`php8.3-sqlite3` shows up as `500 Internal Server Error` with an empty
body and `PDOException: could not find driver` in the Apache error log
— surfaced during the first prod completion run.

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

