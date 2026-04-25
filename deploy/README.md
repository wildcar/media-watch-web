# Deployment

Target host assumptions:

* Apache is already installed
* PHP ≥ 8.1 is already installed
* the host already sees the final media files on local disk
* public hostname: `v.sitename.org`
* deploy user: `www-data`

## PHP extensions

The application uses PDO + SQLite for the metadata registry, plus the
standard `finfo` and `json` extensions. On a clean Ubuntu/Debian box
with `php8.3-cli` installed, install the SQLite extension explicitly —
it is **not** part of the base php package and a missing driver shows
up only as a `500 Internal Server Error` with an empty body and a
`PDOException: could not find driver` line in the Apache error log.

    sudo apt install php8.3-sqlite3
    sudo systemctl reload apache2  # or: sudo systemctl reload php8.3-fpm

Adjust `8.3` to whatever PHP major.minor version your host runs
(`php -v`). Verify with:

    php -m | grep -i sqlite
    # → pdo_sqlite
    # → sqlite3

## Suggested layout

    /opt/media-watch-web/                  # git checkout
    /opt/media-watch-web/public/           # Apache DocumentRoot
    /opt/media-watch-web/var/              # SQLite + runtime state

## Apache modules

    sudo a2enmod rewrite headers mime
    sudo systemctl reload apache2

## Install the site

    sudo mkdir -p /opt/media-watch-web
    sudo chown -R www-data:www-data /opt/media-watch-web

    sudo -u www-data git clone <repo-url> /opt/media-watch-web
    cd /opt/media-watch-web

    sudo -u www-data cp config.local.php.example config.local.php

Edit `config.local.php` and set:

* `base_url` → `https://v.sitename.org`
* `db_path` → `/opt/media-watch-web/var/media-watch.sqlite`
* `media_roots` → absolute movie / series roots on this host

Prepare the writable runtime directory:

    sudo install -d -m 0755 -o www-data -g www-data /opt/media-watch-web/var

Install the vhost:

    sudo cp /opt/media-watch-web/deploy/apache-vhost.conf /etc/apache2/sites-available/media-watch-web.conf
    sudo a2ensite media-watch-web.conf
    sudo systemctl reload apache2

## TLS

This repository ships only the vhost skeleton. Attach your existing TLS termination or issue a certificate for `v.sitename.org`, for example with Certbot:

    sudo certbot --apache -d v.sitename.org

## Registering Content

The web app does not discover files by itself; another local automation step must register them:

    sudo -u www-data php /opt/media-watch-web/bin/register.php \
      --id=my-id \
      --file=/mnt/storage/Media/Video/Movie/Example/example.mkv \
      --title="Example" \
      --description="Example description" \
      --poster-url="https://image.tmdb.org/t/p/w500/abc.jpg" \
      --kind=movie

Expected public URLs:

* `https://v.sitename.org/watch/<id>`
* `https://v.sitename.org/stream/<id>`
