# history — media-watch-web

Per-repo task log. Each code-change task adds a short entry **before** work
starts.

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

