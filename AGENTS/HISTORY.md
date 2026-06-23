# History

Newest first. Each entry ≤5 lines using the format in `AGENTS.md`. Repo-local log;
cross-repo log is `../AGENTS/HISTORY.md`.

---

## 2026-06-23 · Migrate harness to agent-template layout
- What: Added PHP-flavoured `AGENTS.md` + `CLAUDE.md` pointer + `AGENTS/{SPEC,STATE,HISTORY,MEMORY,ENV}.md` + `docs/adr/TEMPLATE.md`; folded `history.md`→HISTORY, `env.md`→ENV.
- Why: Adopt the workspace-standard harness in this (non-MCP) repo.
- Files: `AGENTS.md`, `CLAUDE.md`, `AGENTS/*`, `docs/adr/TEMPLATE.md`; removed `history.md`, `env.md`.
- Next: Repo follows whatever the bot registers; token-gated watch access still deferred.

## 2026-04-27 · media_id: accept dl-<sha1[:12]> for non-YouTube yt-dlp sources
- What: Extended `MEDIA_ID_REGEX` to match `dl-[a-f0-9]{12}` alongside existing prefixes; smoke test covers round-trip.
- Why: Bot downloads from yt-dlp's full extractor list; Vimeo/Twitch/TikTok lack a canonical short id, so use sha1(url)[:12].
- Files: `src/Api.php`, `tests/api_smoke.php`.
- Next: routine `git pull` on media host; PHP-FPM picks it up.

## 2026-04-27 · Accept kind=cartoon (single-file, separate routing)
- What: `Api.php`/`Storage.php` now pass `cartoon` through (was coerced to `movie`); cartoon uses the single-file movie codepath, no episode parsing.
- Why: Animated movies route to `Cartoon/` for Plex + a 🎨 marker in the bot's `/list`; animated series stay `series`.
- Files: `src/Api.php`, `src/Storage.php`, `tests/api_smoke.php`.
- Next: routine `git pull`; PHP-FPM picks it up without reload.

## 2026-04-26 · Register: season-in-dir + raw-numbered filename fallback
- What: Added `parseEpisodeFromPath` — leading-number episode + deepest season-named directory; handles «Друзья (1994)/Сезон 9/01.mkv». `SxxEyy` still wins.
- Why: First series register failed — season buried in dir name, files numbered raw, so filename-only patterns never matched.
- Files: `src/Api.php`, `tests/api_smoke.php` (Russian season fixture w/ 23-24 double-ep).
- Next: continue series coverage.

## 2026-04-26 · X-Sendfile for download progress
- What: Optional `MediaWatchStreamer::send` path (`MEDIA_WATCH_USE_XSENDFILE=1`) emits `X-Sendfile`; Apache delivers via sendfile(2) with a real Content-Length. Default off.
- Why: PHP-FPM streaming forces `Transfer-Encoding: chunked`, stripping Content-Length so the browser shows no download ETA.
- Files: `src/Streamer.php`, `src/bootstrap.php`, `env.md`→`AGENTS/ENV.md`.
- Next: needs `mod_xsendfile` + `XSendFilePath /mnt/storage/Media` on the host.

## 2026-04-26 · /api/register — composite media_id contract
- What: Body field `imdb_id`→`media_id` (required, regex-validated); dropped server-side `makeBaseId`/`slugify`; added `bin/wipe-records.php`. Episode ids unchanged.
- Why: Cross-repo move to typed `<source>-<id>` so different release sources stop colliding on one PK and YouTube gets a slot.
- Files: `src/Api.php`, `bin/wipe-records.php`.
- Next: roll out web first, `php bin/wipe-records.php --yes`, then deploy the bot (sends `media_id`).

## 2026-04-25 · Watch page polish — description under player, drop VLC hint
- What: Moved description to a full-width block under the video; removed the «откройте в VLC» fallback line; description fallback used only for meta tags.
- Why: Wide screens pinched the description at 60ch; the «📥 Скачать» button already covers the VLC path.
- Files: `public/watch.php`.
- Next: —

## 2026-04-25 · Browser-playable proxy for non-mp4 originals
- What: Async remux of H.264/HEVC mkv to `Share/<id>.mp4` (AAC only when source non-AAC); new `share_path`/`remux_status`/`remux_error` columns; `remux-worker.php` spawned detached via `proc_open`, advisory flock; `stream.php?share=1`.
- Why: Serve a browser-playable copy without re-encoding video or mutating originals.
- Files: `src/Storage.php`, `bin/remux-worker.php`, `public/stream.php`, `public/watch.php`.
- Next: backfill remux for pre-feature rows.

## 2026-04-25 · ffprobe video dimensions for og:video:width/height
- What: On register, ffprobe width/height/duration into new columns; `watch.php` emits `og:video:width/height` + `twitter:player:width/height`; `bin/backfill-dimensions.php` for existing rows.
- Why: Telegram refuses the inline player from `og:video` alone — it needs declared width/height.
- Files: `src/Storage.php`, `public/watch.php`, `bin/backfill-dimensions.php`.
- Next: emit width/height once probe data exists (done here).

## 2026-04-25 · Emit og:video / twitter:player tags
- What: Added `og:video`, `og:video:url/secure_url/type` and matching `twitter:player*` tags on the watch page, pointing at `/stream/<id>`, emitted unconditionally.
- Why: Let Telegram build an inline player; unplayable containers gracefully fall back to the image preview.
- Files: `public/watch.php`.
- Next: add og:video:width/height (deferred then shipped).

## 2026-04-25 · Replace bottom watch link with a download button
- What: Bottom link → 📥 «Скачать файл» hitting `/stream/<id>?download=1`; `Streamer::send` gained an `$asAttachment` flag (`Content-Disposition: attachment`); VLC hint for non-native containers. Documented `php8.3-sqlite3` prereq.
- Why: Cleaner download path; missing sqlite ext surfaced as opaque 500 in prod.
- Files: `public/watch.php`, `src/Streamer.php`, `deploy/README.md`.
- Next: —

## 2026-04-25 · HTTP registration API
- What: Added `POST /api/register` + `DELETE /api/records/{id}`; file-or-directory register (movie largest-file, series per-episode parse), id auto-gen + collision suffix, idempotent same-path upsert; Bearer auth, 503 when no token.
- Why: Let the Telegram bot orchestrator register downloads directly, without the CLI.
- Files: `src/Api.php`, `public/api.php`.
- Next: wire the bot's completion poller to it.

## 2026-04-23 · Initial scaffold
- What: Apache + PHP browser-playback app: `watch.php` (OG/Twitter + HTML5 video), `stream.php` (HTTP Range, direct streaming), SQLite registry, CLI register/delete, `.htaccess` rewrites + deploy docs.
- Why: Expose `/watch/<id>` playback links under `https://v.sitename.org` without transcoding or uploading to Telegram.
- Files: new repo `media-watch-web/`.
- Next: add the HTTP registration API.
