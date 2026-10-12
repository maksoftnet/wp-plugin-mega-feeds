# Changelog

## 0.1.0

- Dynamic RSS 2.0 feed at `/mega-feeds/rss/` (or `/?mega_feed=rss`).
- Bounded output: default and maximum item limits, `?limit=` and `?type=`.
- Generation-based cache on transients (uses a persistent object cache when
  present), conditional GET (ETag / Last-Modified, 304).
- Minimal settings screen under Settings > Mega Feeds.
