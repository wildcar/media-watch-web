# Agent Instructions — media-watch-web

Primary entrypoint for any agent (Claude, Codex, DeepSeek, etc.) working **inside
this repo**. Read this first; it is authoritative for everything under
`media-watch-web/`.

## Workspace

`media-watch-web` is one repo of the **`movie_handler`** workspace (seven sibling
repos under a coordination root). Cross-repo architecture, end-to-end flows, hosts,
and shared agreements live in `../AGENTS.md` and `../AGENTS/SPEC.md` — read those
when reasoning about how the bot, MCP servers, and this app fit together. **This
file is authoritative for repo-local work.**

This is the **only non-MCP repo**: a PHP 8.3 + Apache watch-page web app that runs
on the **media host** (`v.wildcar.ru`), serving browser playback pages for video
files the rest of the system has already downloaded. No Python, no `uv`, no MCP
SDK — adapt any workspace-level "MCP server" instructions accordingly.

## Project

Browser playback for local media. The bot downloads a movie/series/cartoon to
`/mnt/storage/Media/Video/{Movie,Series,Cartoon}`, then calls `POST /api/register`
here. This app stores a record, runs ffprobe, remuxes non-browser containers to
`Share/<id>.mp4` in the background, and exposes `/watch/<id>` (and `/series/<id>`)
pages with Telegram-friendly Open Graph metadata. It does **not** transcode video
or re-upload into Telegram — it streams the file as-is over HTTP Range (or via
`mod_xsendfile`).

## Document Map

| File | Role |
|------|------|
| `AGENTS.md` | This entrypoint. Repo map, workflow, rules. |
| `CLAUDE.md` | Compatibility pointer to `AGENTS.md`. |
| `AGENTS/SPEC.md` | Repo-local functional + technical spec: endpoints, schema, remux worker, sweep. Source of truth for behaviour. |
| `AGENTS/STATE.md` | Current snapshot: goal, now, next, open questions, deferred. Overwritten each iteration. |
| `AGENTS/HISTORY.md` | Append-only iteration log, newest first. |
| `AGENTS/MEMORY.md` | Durable repo-local facts not derivable from code. The ONLY agent memory store here. |
| `AGENTS/ENV.md` | Repo-local env (db path, vhost, media paths). Shared host facts → `../AGENTS/ENV.md`. |
| `README.md` | User-facing feature/config/usage docs (kept). |
| `docs/adr/` | Architecture Decision Records (see `docs/adr/TEMPLATE.md`). |

## Environment

- Runs on the **media host** (`v.wildcar.ru`): Apache + PHP-FPM 8.3, deploy at
  `/opt/media-watch-web`, served as user `www-data`.
- Commit identity: `wildcar <wildcar@mail.ru>`. Remote `github.com/wildcar/media-watch-web`.
- Repo-local hosts/paths in `AGENTS/ENV.md`; shared host facts in `../AGENTS/ENV.md`.

## Startup Checklist

1. Read `AGENTS.md` (this file).
2. Read `AGENTS/SPEC.md` for endpoints, schema, and the remux/sweep behaviour.
3. Read `AGENTS/STATE.md` for the live snapshot.
4. Read top 3–5 entries in `AGENTS/HISTORY.md`.
5. Read `AGENTS/MEMORY.md` (durable repo facts).
6. `git status --short` before editing. Open `AGENTS/ENV.md` for deploy details.

## Change Workflow

For every iteration that changes code or behaviour:

1. If the functional contract changes — update `AGENTS/SPEC.md` first.
2. Make the change.
3. Overwrite `AGENTS/STATE.md`; if the cross-repo picture shifted, also prepend a
   one-line entry to the root `../AGENTS/HISTORY.md`.
4. Prepend a new entry to `AGENTS/HISTORY.md` (≤5 lines, newest first).
5. Commit and push after verification (see Project Rules).

### `AGENTS/HISTORY.md` entry format (≤5 lines, newest first)

```
## YYYY-MM-DD · <short iteration title>
- What: <one line — what changed>
- Why: <one line — reason / task>
- Files: <key paths, comma-separated>
- Next: <one line — what was planned right after>
```

## Memory

`AGENTS/MEMORY.md` is the **single** store of durable agent memory for this repo.
Read it at session start; append a short bullet when you learn a durable fact and
commit it with the related change. Durable facts → `MEMORY.md`; current snapshot →
`STATE.md`; iteration log → `HISTORY.md`. One bullet = one fact; don't record what
is already in the code, git history, or SPEC/STATE/HISTORY.

## Language Rules

- Source code, technical docs, code comments: **English**.
- Conversation with the user: **Russian**.
- End-user UI text: **Russian**, built so a language selector can be added later.
- Docs already written in another language are an established contract — keep them.

## Project Rules

- **`media_id` is the cross-server key**, shape `<source>-<id>`. The register
  endpoint validates it against
  `^(imdb-tt\d{7,10}|rt-\d+|yt-[A-Za-z0-9_-]{6,32}|dl-[a-f0-9]{12})$`. The bot owns
  the id; the app never derives one.
- **Stereo AAC for share files** — browsers play 5.1 AAC silent; the remux worker
  forces `-c:a aac -b:a 192k -ac 2`. Never relax this.
- **`og:video:width`/`og:video:height` are required** for Telegram's inline player;
  use `noindex` (never `nofollow`) in robots meta.
- **Secrets via env / `config.local.php`**, never committed. `MEDIA_WATCH_API_TOKEN`
  empty ⇒ `/api/*` returns `503` (no open relay).
- **Commit + push to `main` directly** after verification — no feature branch, no
  asking. **`git pull --ff-only`** on the prod host — never surprise merge commits.

## Stack & Commands

PHP 8.3 (`declare(strict_types=1)` everywhere, PSR-ish single-class files, PDO
SQLite), Apache + `mod_php`/PHP-FPM + `mod_rewrite` (+ optional `mod_xsendfile`),
external `ffprobe`/`ffmpeg`. No package manager — plain PHP, no Composer deps.

```bash
# local dev (built-in server ignores .htaccess — use the *.php paths)
cp config.local.php.example config.local.php
php -S 127.0.0.1:8080 -t public
#   http://127.0.0.1:8080/watch.php?id=<id>   /series.php?id=<id>   /stream.php?id=<id>

php -l public/watch.php                 # lint a file
php tests/api_smoke.php                  # API smoke test (no PHPUnit)
php bin/register.php --id=… --file=… --title=…   # CLI register
php bin/sweep-missing.php                # drop rows whose file is gone

# deploy (media host) — php-fpm picks up source automatically, no restart
sudo -u www-data git -C /opt/media-watch-web pull --ff-only
```

## Project Structure

```
media-watch-web/
├── AGENTS.md / CLAUDE.md / AGENTS/   # this harness
├── README.md                         # user-facing docs
├── docs/adr/                         # ADRs
├── public/        # web roots: index, watch.php, series.php, stream.php, api.php, .htaccess
├── src/           # MediaWatchStorage, MediaWatchStreamer, MediaWatchApi, bootstrap.php
├── bin/           # register / delete / remux-worker / sweep-missing / backfill-* / wipe-records
├── deploy/        # apache-vhost.conf, systemd sweep timer+service, README
├── tests/         # api_smoke.php
├── var/           # SQLite db (gitignored) + .gitkeep
└── config.local.php.example
```

## Code Style

Match the surrounding PHP: `declare(strict_types=1)`, typed properties/params,
constructor promotion, `match`, `final` classes, structured array returns over
exceptions across the HTTP boundary. UI strings in Russian; code/comments English.
