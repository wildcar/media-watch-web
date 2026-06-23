# Memory

Durable repo-local facts NOT derivable from code, git history, or SPEC/STATE/HISTORY.
The ONLY agent memory store for this repo — read at session start; append a short
bullet when you learn something durable and commit it with the related change.
Cross-repo facts live in `../AGENTS/MEMORY.md` — don't duplicate them here.

MEMORY.md = durable facts; current state → STATE.md; iteration log → HISTORY.md.

## Project facts

- **Stereo AAC is mandatory for share files.** Browsers play 5.1 AAC silent, so the
  remux worker forces `-c:a aac -b:a 192k -ac 2` regardless of source layout.
- **Telegram needs `og:video:width`/`og:video:height`.** Without them the inline
  player is refused and the link degrades to a plain card — `og:video` alone isn't
  enough. `@WebpageBot` must re-crawl after og tags change.
- **Robots meta uses `noindex` only — never `nofollow`.** `nofollow` kills the
  Telegram link preview entirely.
- **`X-Sendfile` is the only way to get a real `Content-Length` on downloads.**
  PHP-FPM streaming forces `Transfer-Encoding: chunked` (no Content-Length, no
  browser ETA); `mod_xsendfile` + `XSendFilePath /mnt/storage/Media` fixes it.
- Inline Telegram video limit is ~20 MB; larger files intentionally show only the
  og:image card.
