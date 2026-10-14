# Changelog

## 0.2.0

- RSS reorganised under `/rss/`: central feed ordered by last modification
  (`/rss/`), plus `/rss/posts/`, `/rss/pages/`, `/rss/products/` (singular
  `post`, `page`, `product` accepted as aliases). Replaces `/mega-feeds/rss/`.
- Items carry a main image (enclosure + `media:content isDefault`) and any
  additional images found on the page (featured, WooCommerce gallery,
  attachments, images in content).
- Shared `Products\Source`: WooCommerce products flattened into per-variant
  offers (price incl. tax, stock, brand, size, colour, categories, images).
- Glami XML feed at `/glami/` (SHOP / SHOPITEM per variant, sold-out and
  image-less variants skipped).
- Product feeds use their own limits (default 1000, maximum 5000 products).
- Settings: Glami size system, category map, delivery; product limits.

## 0.1.0

- Dynamic RSS 2.0 feed at `/mega-feeds/rss/` (or `/?mega_feed=rss`).
- Bounded output: default and maximum item limits, `?limit=` and `?type=`.
- Generation-based cache on transients (uses a persistent object cache when
  present), conditional GET (ETag / Last-Modified, 304).
- Minimal settings screen under Settings > Mega Feeds.
