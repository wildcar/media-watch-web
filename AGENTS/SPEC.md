# media-watch-web — functional & technical specification

Source of truth for *what this repo does* and *how it is built*. Cross-repo contract
(the download pipeline that feeds this app, the `media_id` scheme, hosts) lives in
`../AGENTS/SPEC.md`; this document is the repo-local detail.

## Purpose

Browser playback pages for video files already downloaded to the media host. For a
registered record the app:

- renders `/watch/<id>` — an HTML5 player page with Open Graph / Twitter Card meta
  so Telegram builds a rich preview (and, when dimensions are known, an inline
  player);
- renders `/series/<id>` — a season/episode index for series records;
- streams the file as-is over HTTP Range (or via `mod_xsendfile`) — **no transcode,
  no Telegram upload, no mutation of the original file**;
- remuxes non-browser containers (mkv, etc.) to `Share/<id>.mp4` in the background
  so browsers can play them;
- exposes an HTTP API (`POST /api/register`, `GET /api/records`,
  `DELETE /api/records/{id}`) for the bot orchestrator, plus CLI scripts.

## Stack

- PHP 8.3, `declare(strict_types=1)`, no Composer dependencies (plain PHP).
- Apache + `mod_php`/PHP-FPM, `mod_rewrite` for pretty URLs, optional
  `mod_xsendfile` for real `Content-Length` on downloads.
- SQLite via PDO (WAL, `synchronous=NORMAL`); single `records` table.
- External binaries: `ffprobe` (probe dimensions/codecs at register), `ffmpeg`
  (remux worker).
- Config from env vars, overridable by `config.local.php`.

## URL surface

`public/.htaccess` rewrites (Apache; the built-in PHP dev server ignores it — use
the `*.php?id=` forms locally). The `.htaccess` also re-exports `Authorization`
into `HTTP_AUTHORIZATION` so the Bearer token survives to PHP.

| Route | Script | Purpose |
|-------|--------|---------|
| `GET /watch/<id>` | `watch.php` | Player page + OG/Twitter meta |
| `GET /series/<id>` | `series.php` | Episode index for a series parent id |
| `GET /stream/<id>` | `stream.php` | Range stream of the original file; `?share=1` serves the remuxed mp4; `?download=1` sends `Content-Disposition: attachment` |
| `* /api/...` | `api.php` → `MediaWatchApi` | JSON API (below) |
| `GET /` | `index.php` | Static "service is running" page |

### HTTP API (`MediaWatchApi`)

Bearer auth via `api_token` / `MEDIA_WATCH_API_TOKEN`. Empty token ⇒ every endpoint
returns `503 api_disabled` (refuses to run as an open relay). `hash_equals` compare.

- **`POST /api/register`** — body `{path, title, media_id, kind?, description?,
  poster_url?, mime_type?}`.
  - `media_id` required, validated against `MEDIA_ID_REGEX` (see below).
  - `path` = file → registers that file; `path` = directory:
    - `kind` in {`movie`,`cartoon`} → largest video file wins (skips `*sample*`);
    - `kind=series` → recursive scan, one record per episode; unparsable files →
      `warnings[]`.
  - Episode id suffix `<media_id>-sNNeNN`; collision with a *different* file appends
    `-2`,`-3`,…; same-path re-register is idempotent (upsert).
  - 200 `{records:[…watch_url,stream_url…], warnings:[]}`; errors
    `400 invalid_json|invalid_argument|no_records`, `401 unauthorized`,
    `503 api_disabled`.
- **`GET /api/records`** — `{ids:[…]}`, lightweight id list; the bot diffs it against
  its own `watch_records` to prune entries the sweep removed.
- **`DELETE /api/records/{id}`** — `{id, deleted: bool}`.

### `media_id` register regex

```
^(imdb-tt\d{7,10}|rt-\d+|yt-[A-Za-z0-9_-]{6,32}|dl-[a-f0-9]{12})$
```

`rt-` rutracker topic id (default), `yt-` YouTube video id, `dl-` sha1(url)[:12] for
other yt-dlp sources, `imdb-tt…` reserved. The bot owns the id — the app never
derives one (server-side `slugify`/`makeBaseId` were dropped on the move to
composite ids).

### Episode parsing (series)

Tried in order against the filename: `SxxEyy` → `1x02` → `Season N … Episode M`.
Fallback for rutracker layouts that bury the season in a directory name
(«Сезон 9», «Season 9», «S09») and number files raw (`01. ….mkv`):
`parseEpisodeFromPath` pulls a leading number as the episode and walks the directory
chain (deepest first, then the registration root's own basename) for the season.
Double-episode `23-24.…` collapses to the first number.

## `records` table

Single table, `id TEXT PRIMARY KEY`. Columns:

| Column | Notes |
|--------|-------|
| `id` | composite `media_id`; series episodes get `-sNNeNN` |
| `file_path` | resolved absolute path (validated against `media_roots`) |
| `title`, `description`, `poster_url` | metadata (UTF-8) |
| `mime_type` | finfo-detected if not supplied |
| `kind` | `movie` \| `series` \| `cartoon` (anything else coerced to `movie`) |
| `video_width`, `video_height` | from ffprobe; 0 if probe failed |
| `duration_seconds` | from ffprobe `format=duration` |
| `share_path` | path of the remuxed mp4 (`Share/<id>.mp4`) when applicable |
| `remux_status` | `not_needed` \| `pending` \| `running` \| `ready` \| `failed` |
| `remux_error` | last remux failure message |
| `created_at`, `updated_at` | ISO-8601 UTC |

Schema is created `IF NOT EXISTS`; later columns are added by idempotent
`PRAGMA table_info` + `ALTER TABLE ADD COLUMN` migrations (SQLite lacks
`ADD COLUMN IF NOT EXISTS`).

## Remux-to-mp4 worker

On register, `decideRemux` classifies the file:

- browser-playable extension (`mp4/m4v/webm/ogv/mov`) → `not_needed`.
- video codec H.264/HEVC in a non-browser container → `pending`; the share path is
  `Share/<id>.mp4`. If that file already exists and is non-empty → `ready` (reuse,
  don't re-spawn — re-muxing a big release wastes an hour).
- no video stream / non-remuxable codec / unset share dir → `failed`.

`pending` ⇒ `spawnRemuxWorker` detaches `bin/remux-worker.php <id>` via
`nohup … &` so the HTTP request returns immediately. The worker:

- carries video with `-c:v copy` (H.264/HEVC ride along);
- **forces stereo AAC audio** (`-c:a aac -b:a 192k -ac 2`) **unconditionally** —
  browsers render 5.1 AAC silent; `audioStrategy` exists but stereo-AAC always wins
  in practice;
- writes to `Share/<id>.mp4` (per-record, `www-data:www-data`), holds an advisory
  `flock` so a duplicate spawn no-ops;
- updates the row `running` → `ready`/`failed`.

`watch.php` picks the player source from `remux_status`: `play_original` /
`play_share` / `pending` (meta-refresh every 20 s) / `unplayable`.

## Open Graph / Telegram preview rules

- Emit `og:title`, `og:description`, `og:type=video.movie`, `og:url`, `og:image`
  (poster), and — when something is playable — `og:video*` + `twitter:player*`
  pointing at the playable source (original or share mp4).
- **`og:video:width`/`og:video:height` are required** — without them Telegram
  refuses the inline player and falls back to a plain link card. Emitted only when
  ffprobe populated the dimensions.
- **`robots: noindex`** only — `nofollow` kills the preview entirely.
- Telegram inline video size limit ~20 MB; bigger files show only the og:image
  card (by design). `@WebpageBot` flushes the preview cache when og tags change.

## Streaming

`MediaWatchStreamer` serves the file with `Accept-Ranges: bytes`, `Content-Range`,
`206 Partial Content`, MIME detection, no transcode. `?download=1` ⇒
`Content-Disposition: attachment`. With `use_xsendfile` on, PHP only sets headers
and emits `X-Sendfile: <path>`; Apache `sendfile(2)`s the body and emits a **real
`Content-Length`** — PHP-FPM streaming otherwise forces `Transfer-Encoding: chunked`
and the browser shows no download ETA. Requires `mod_xsendfile` + `XSendFilePath`
whitelisting `/mnt/storage/Media` (see `AGENTS/ENV.md`).

## `bin/sweep-missing.php` (hourly cleanup)

`sweepMissing()` drops every row whose `file_path` no longer exists on disk and
unlinks the matching `Share/<id>.mp4`, returning deleted ids. Files get deleted
routinely (rutracker re-uploads supersede; manual Plex clears; storage cap), so
without this `/watch/<id>` 404s and the bot's `/list` shows dead links. Run via the
`media-watch-sweep.timer` systemd unit hourly (`--quiet` for the timer).

## CLI scripts (`bin/`)

`register.php` / `delete.php` (manual single-record CRUD), `remux-worker.php` (spawned
worker), `sweep-missing.php` (cleanup), `backfill-dimensions.php` (re-probe rows with
`width=0`), `backfill-remux.php` (re-evaluate remux for pre-feature rows),
`wipe-records.php` (one-shot post-migration table clear).

## Configuration

Env first, then `config.local.php` overrides. Keys: `MEDIA_WATCH_BASE_URL`,
`MEDIA_WATCH_DB_PATH`, `MEDIA_WATCH_MEDIA_ROOTS` (comma-separated allowlist —
register refuses files outside it), `MEDIA_WATCH_SITE_NAME`, `MEDIA_WATCH_API_TOKEN`,
`MEDIA_WATCH_SHARE_DIR` (default `/mnt/storage/Media/Video/Share`),
`MEDIA_WATCH_USE_XSENDFILE`, `MEDIA_WATCH_PHP_CLI` (worker spawn). Detail in
`AGENTS/ENV.md`.

## Current state

- Deployed on the media host, wired to the bot's completion poller end to end.
- Movie / series / cartoon registration, remux-to-mp4, X-Sendfile downloads,
  OG/Telegram previews, hourly sweep — all live.
- Watch URLs are public (path = `media_id`, guessable). Token-gated access is
  deferred — see `AGENTS/STATE.md`.
