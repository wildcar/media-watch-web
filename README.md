# media-watch-web

Small Apache + PHP application that exposes browser playback links for local
video files already stored on the media host.

The app does **not** upload video into Telegram. Browser-playable files stream
**as is** over HTTP Range; files a browser can't play (e.g. an mkv) get a
one-time background **remux** into an mp4 — the video stream is copied
untouched and only the audio is re-encoded to stereo AAC, so there is no full
transcode (truly incompatible video codecs are refused, not re-encoded). Watch
pages carry Open Graph metadata so Telegram builds a rich preview for the link.

Default public base URL: `https://v.sitename.org`

## Features

- `GET /watch/<id>` — HTML page with Open Graph / Twitter Card metadata
  (`og:title`, `og:description`, `og:image`, `og:type=video.movie`, `og:url`,
  `twitter:card`) and an HTML5 `<video>` player. Browser-playable files play
  directly; non-playable ones show a "preparing" state until the background
  remux is `ready`, then play the remuxed mp4.
- `GET /series/<id>` — index page for a multi-episode series: episodes grouped
  by season, each linking to its own `/watch/<id>` page.
- `GET /stream/<id>` — file streaming with MIME detection, `Accept-Ranges`,
  `Content-Range`, `206 Partial Content`, and no transcoding (serves the
  original file, or the remuxed mp4 once it is `ready`).
- HTTP registration API (Bearer-auth): `POST /api/register`,
  `GET /api/records`, `DELETE /api/records/{id}`. Consumed by the Telegram bot
  on download completion. A `register.php` / `delete.php` CLI does the same
  from the shell.
- SQLite registry for title metadata, file paths, and remux state. Hourly
  `bin/sweep-missing.php` drops records whose file vanished from disk (and the
  matching remuxed mp4).

## Repository Layout

```text
media-watch-web/
├── README.md
├── AGENTS.md
├── CLAUDE.md
├── AGENTS/
├── config.local.php.example
├── bin/
│   ├── register.php            # CLI register (single file or directory)
│   ├── delete.php              # CLI delete by id
│   ├── remux-worker.php        # background mkv → mp4 remux
│   ├── sweep-missing.php       # hourly: drop records whose file is gone
│   ├── backfill-dimensions.php # one-off: fill video_width/height
│   ├── backfill-remux.php      # one-off: queue remux for old records
│   └── wipe-records.php        # maintenance: clear the registry
├── deploy/
│   ├── apache-vhost.conf
│   └── README.md
├── public/
│   ├── .htaccess               # rewrites /watch /stream /series /api
│   ├── index.php
│   ├── watch.php
│   ├── series.php
│   ├── stream.php
│   └── api.php
├── src/
│   ├── Storage.php             # SQLite registry + remux decision
│   ├── Streamer.php            # range streaming
│   ├── Api.php                 # HTTP API handler
│   └── bootstrap.php
└── var/
    └── .gitkeep
```

## Configuration

Configuration is loaded from environment variables first, then optionally
overridden by `config.local.php`.

Supported keys:

- `MEDIA_WATCH_BASE_URL` — external base URL, default `https://v.sitename.org`
- `MEDIA_WATCH_DB_PATH` — SQLite path, default `var/media_watch.sqlite`
- `MEDIA_WATCH_MEDIA_ROOTS` — comma-separated allowlist of absolute media roots
- `MEDIA_WATCH_SITE_NAME` — page title prefix, default `Media Watch`
- `MEDIA_WATCH_API_TOKEN` — Bearer token for the registration HTTP API.
  Empty/unset disables `POST /api/register` and `DELETE /api/records/{id}`
  (they answer `503`).

To override locally:

```bash
cp config.local.php.example config.local.php
$EDITOR config.local.php
```

## Registering a Video

Use the CLI from the same host that has access to the media files:

```bash
php bin/register.php \
  --id=tt1160419-2026-04-23 \
  --file=/mnt/storage/Media/Video/Movie/Dune (2021)/Dune.mkv \
  --title="Дюна" \
  --description="Paul Atreides..." \
  --poster-url="https://image.tmdb.org/t/p/w500/abc.jpg" \
  --kind=movie
```

The script prints the stored record as JSON. Re-registering the same `id`
updates the metadata in place.

Delete a record:

```bash
php bin/delete.php --id=tt1160419-2026-04-23
```

## Registration HTTP API

For automation (e.g. the Telegram bot orchestrator), the same registry is
exposed over HTTP. Auth is a Bearer token — the API is disabled (`503`)
until `api_token` / `MEDIA_WATCH_API_TOKEN` is set.

### `POST /api/register`

Register one or many records from either a single file or a directory.

Request body (JSON):

```json
{
  "path": "/mnt/storage/Media/Video/Movie/Dune (2021)",
  "title": "Дюна",
  "kind": "movie",
  "imdb_id": "tt1160419",
  "description": "Paul Atreides…",
  "poster_url": "https://image.tmdb.org/t/p/w500/abc.jpg"
}
```

Behaviour:

- `path` is a **file** → registers exactly that file.
- `path` is a **directory** + `kind=movie` → picks the largest video file
  inside the directory, ignoring filenames containing `sample`.
- `path` is a **directory** + `kind=series` → recursively scans for video
  files, parses `S01E02` / `1x02` / `Season 1 Episode 2` from filenames
  and registers one record per episode. Files that don't match are
  reported as `warnings[]` and skipped.

Auto-generated id:

- base = `imdb_id` if it matches `tt\d{7,10}`, otherwise a slug from
  `title`;
- series episode → `<base>-sNNeMM`;
- on collision with a **different** file, a numeric suffix is appended
  (`-2`, `-3`, …); a re-register of the **same** path under the same id
  is idempotent (upsert).

Response (200):

```json
{
  "records": [
    {
      "id": "tt1160419",
      "title": "Дюна",
      "kind": "movie",
      "file_path": "/mnt/.../Dune.mkv",
      "season": null,
      "episode": null,
      "watch_url": "https://v.sitename.org/watch/tt1160419",
      "stream_url": "https://v.sitename.org/stream/tt1160419"
    }
  ],
  "warnings": []
}
```

Common error codes: `401 unauthorized`, `400 invalid_argument`,
`400 invalid_json`, `400 no_records`, `503 api_disabled`.

Example call:

```bash
curl -sS -X POST https://v.sitename.org/api/register \
  -H "Authorization: Bearer $MEDIA_WATCH_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"path":"/mnt/.../Dune (2021)","title":"Дюна","kind":"movie","imdb_id":"tt1160419"}'
```

### `GET /api/records`

Lightweight — returns just the live list of registered ids:

```json
{ "ids": ["tt1160419", "yt-dQw4w9WgXcQ", "rt-6543210"] }
```

The bot polls this hourly and prunes any of its own watch links whose id has
disappeared here (e.g. dropped by `sweep-missing.php`).

### `DELETE /api/records/{id}`

Removes a single record. Returns `{ "id", "deleted": true|false }` (`404` when
the id was unknown).

## Local Development

```bash
cp config.local.php.example config.local.php
php -S 127.0.0.1:8080 -t public
```

Then open:

- `http://127.0.0.1:8080/watch/<id>`
- `http://127.0.0.1:8080/stream/<id>`

The built-in PHP server ignores `.htaccess`, so for local dev use:

- `http://127.0.0.1:8080/watch.php?id=<id>`
- `http://127.0.0.1:8080/stream.php?id=<id>`

Apache rewrite rules are provided under `public/.htaccess` for deployment.

## Deployment

See [deploy/README.md](deploy/README.md). The intended host is the same one
that runs `rtorrent` and stores the downloaded video files.
