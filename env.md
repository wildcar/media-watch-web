# Environment Notes — media-watch-web

- Runtime target: Apache + PHP on the same media host as `rtorrent`.
- Public hostname target: `v.sitename.org`.
- Media files are expected to stay on their existing storage paths; the app
  only references them and must not modify or transcode them.
- Metadata storage defaults to SQLite under `var/media_watch.sqlite`, but the
  path can be overridden for deployment.

## `mod_xsendfile` for proper download progress

PHP-FPM streaming forces `Transfer-Encoding: chunked`, which strips
`Content-Length` from the response — browsers then can't show download
ETA / remaining bytes. With `mod_xsendfile` Apache delivers the file
itself via `sendfile(2)` and emits a real `Content-Length`.

Install + enable on the media host:

```
sudo apt install -y libapache2-mod-xsendfile
sudo a2enmod xsendfile
sudo systemctl reload apache2
```

The module needs to know which directories it's allowed to serve
from — without this it 403s every X-Sendfile request. Drop into the
site's vhost (or `/etc/apache2/conf-available/xsendfile.conf` and
`a2enconf`):

```
<IfModule xsendfile_module>
    XSendFile On
    XSendFilePath /mnt/storage/Media
</IfModule>
```

Then flip the bot/web flag — set `MEDIA_WATCH_USE_XSENDFILE=1` in the
service env (or `'use_xsendfile' => true` in `config.local.php`) and
reload Apache. Verify via:

```
curl -sI -H 'Range: bytes=0-1' \
  'https://v.wildcar.ru/stream/<media_id>?download=1'
```

A correctly-served response has `Content-Length: <total>` and **no**
`Transfer-Encoding: chunked`.

