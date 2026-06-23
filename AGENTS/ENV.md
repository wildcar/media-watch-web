# Environment — media-watch-web

Repo-local env: config keys, the Apache vhost, media paths, and the `mod_xsendfile`
recipe. Shared host facts (the media host identity, common deploy patterns, the
`git pull --ff-only` rule) live in `../AGENTS/ENV.md` — read that, don't duplicate.

## Runtime target

- Runs on the **media host** (`v.wildcar.ru`) — same box as `rtorrent`,
  `rtorrent-mcp`, `yt-dlp-mcp`, and Plex. Deploy dir `/opt/media-watch-web`, served
  by Apache + PHP-FPM 8.3 as user `www-data`.
- Public hostname target: `v.wildcar.ru` (`v.sitename.org` is the placeholder
  default in committed config / docs).
- Requires the `php8.3-sqlite3` extension — missing it surfaces as an opaque
  `500` with `PDOException: could not find driver` in the Apache error log.
- Needs `ffprobe` + `ffmpeg` on PATH for dimension probing and the remux worker.
- Media files stay on their existing storage paths; the app only references them and
  must never modify or transcode the original.

## Configuration keys

Env first, then `config.local.php` overrides (`cp config.local.php.example …`).

| Key | Default | Purpose |
|-----|---------|---------|
| `MEDIA_WATCH_BASE_URL` | `https://v.sitename.org` | external base URL for generated links |
| `MEDIA_WATCH_DB_PATH` | `var/media_watch.sqlite` | SQLite registry path |
| `MEDIA_WATCH_MEDIA_ROOTS` | (empty) | comma-separated allowlist of absolute media roots; register refuses files outside it |
| `MEDIA_WATCH_SITE_NAME` | `Media Watch` | page title prefix |
| `MEDIA_WATCH_API_TOKEN` | (empty) | Bearer token for `/api/*`; empty ⇒ `503` |
| `MEDIA_WATCH_SHARE_DIR` | `/mnt/storage/Media/Video/Share` | remuxed mp4 output dir (`www-data:www-data`) |
| `MEDIA_WATCH_USE_XSENDFILE` | off | hand downloads to Apache via `X-Sendfile` |
| `MEDIA_WATCH_PHP_CLI` | `/usr/bin/php` | PHP binary the remux worker is spawned with |

Never commit a real `config.local.php` or the SQLite db (both gitignored).

## Apache vhost (deploy/apache-vhost.conf)

`DocumentRoot /opt/media-watch-web/public`, `AllowOverride All` (the `.htaccess`
does the rewrites and re-exports `Authorization`→`HTTP_AUTHORIZATION` for the Bearer
token). `SetEnv` carries `MEDIA_WATCH_*`; the API token is best set in
`config.local.php` rather than the vhost. `MEDIA_WATCH_MEDIA_ROOTS` in prod is
`/mnt/storage/Media/Video/Movie,/mnt/storage/Media/Video/Series` (add `Cartoon` as
needed).

## `mod_xsendfile` for download progress

PHP-FPM streaming forces `Transfer-Encoding: chunked`, stripping `Content-Length` so
browsers show no download ETA. `mod_xsendfile` lets Apache deliver the file via
`sendfile(2)` with a real `Content-Length`.

```bash
sudo apt install -y libapache2-mod-xsendfile
sudo a2enmod xsendfile
```

Whitelist the media tree (without it Apache 403s every X-Sendfile request) — in the
vhost or `/etc/apache2/conf-available/xsendfile.conf` (`a2enconf`):

```
<IfModule xsendfile_module>
    XSendFile On
    XSendFilePath /mnt/storage/Media
</IfModule>
```

Then set `MEDIA_WATCH_USE_XSENDFILE=1` (or `'use_xsendfile' => true` in
`config.local.php`) and reload Apache. Verify:

```bash
curl -sI -H 'Range: bytes=0-1' 'https://v.wildcar.ru/stream/<media_id>?download=1'
# correct: Content-Length: <total>, and NO Transfer-Encoding: chunked
```

## Deploy

```bash
sudo -u www-data git -C /opt/media-watch-web pull --ff-only   # php-fpm picks up source; no restart
```

Scheduled job: `media-watch-sweep.timer` (hourly) runs `bin/sweep-missing.php
--quiet` (`deploy/media-watch-sweep.{timer,service}`).
