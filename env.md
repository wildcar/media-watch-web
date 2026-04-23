# Environment Notes — media-watch-web

- Runtime target: Apache + PHP on the same media host as `rtorrent`.
- Public hostname target: `v.sitename.org`.
- Media files are expected to stay on their existing storage paths; the app
  only references them and must not modify or transcode them.
- Metadata storage defaults to SQLite under `var/media_watch.sqlite`, but the
  path can be overridden for deployment.

