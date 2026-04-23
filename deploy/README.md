# Deployment

Target host assumptions:

- Apache is already installed
- PHP is already installed
- the host already sees the final media files on local disk
- public hostname: `v.sitename.org`

## Suggested layout

```text
/opt/media-watch-web/                  # git checkout
/opt/media-watch-web/public/           # Apache DocumentRoot
/opt/media-watch-web/var/              # SQLite + runtime state
```

## Apache modules

Enable the modules the app relies on:

```bash
sudo a2enmod rewrite headers mime
sudo systemctl reload apache2
```

## Install the site

```bash
sudo mkdir -p /opt/media-watch-web
sudo chown -R <deploy-user>:<deploy-group> /opt/media-watch-web
git clone <repo-url> /opt/media-watch-web
cd /opt/media-watch-web
cp config.local.php.example config.local.php
```

Edit `config.local.php` and set:

- `base_url` → `https://v.sitename.org`
- `db_path` → where the SQLite file should live
- `media_roots` → absolute movie / series roots on this host

Prepare the writable runtime directory:

```bash
sudo install -d -m 0775 -o <deploy-user> -g www-data /opt/media-watch-web/var
```

Install the vhost:

```bash
sudo cp deploy/apache-vhost.conf /etc/apache2/sites-available/media-watch-web.conf
sudo a2ensite media-watch-web.conf
sudo systemctl reload apache2
```

## TLS

This repository ships only the vhost skeleton. Attach your existing TLS
termination or issue a certificate for `v.sitename.org`, for example with
Certbot:

```bash
sudo certbot --apache -d v.sitename.org
```

## Registering Content

The web app does not discover files by itself; another local automation step
must register them:

```bash
php /opt/media-watch-web/bin/register.php \
  --id=my-id \
  --file=/mnt/storage/Media/Video/Movie/Example/example.mkv \
  --title="Example" \
  --description="Example description" \
  --poster-url="https://image.tmdb.org/t/p/w500/abc.jpg" \
  --kind=movie
```

Expected public URLs:

- `https://v.sitename.org/watch/<id>`
- `https://v.sitename.org/stream/<id>`

