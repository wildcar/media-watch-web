# media-watch-web

Small Apache + PHP application that exposes browser playback links for local
video files already stored on the media host.

The app does **not** upload video into Telegram and does **not** transcode.
It serves files "as is" via HTTP Range responses and renders a watch page
with Open Graph metadata so Telegram builds a rich preview for the link.

Default public base URL: `https://v.sitename.org`

## Features

- `GET /watch/<id>` — HTML page with:
  - `og:title`
  - `og:description`
  - `og:image`
  - `og:type=video.movie`
  - `og:url`
  - `twitter:card=summary_large_image`
  - HTML5 `<video>` player pointing at `/stream/<id>`
- `GET /stream/<id>` — file streaming with:
  - MIME detection
  - `Accept-Ranges: bytes`
  - `Content-Range`
  - `206 Partial Content`
  - no transcoding
- SQLite registry for title metadata and file paths
- CLI registration script for future integration from `rtorrent-mcp`

## Repository Layout

```text
media-watch-web/
├── README.md
├── history.md
├── env.md
├── config.local.php.example
├── bin/
│   ├── delete.php
│   └── register.php
├── deploy/
│   ├── apache-vhost.conf
│   └── README.md
├── public/
│   ├── .htaccess
│   ├── index.php
│   ├── stream.php
│   └── watch.php
├── src/
│   ├── Storage.php
│   ├── Streamer.php
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

