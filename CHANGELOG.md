# Changelog

## 0.3.3

- Feeds are stored as files in `wp-content/uploads/mega-feeds/` (document +
  JSON metadata, written atomically, closed to direct web access) instead of
  transients. A marketplace crawler no longer waits for a render: it always
  gets the last stored file.
- Background regeneration through WP-Cron: debounced after content, term,
  stock or settings changes; hourly warm-up of every enabled feed; one cron
  event per feed variant, each with its own time budget. A render only runs
  inline for the very first request of a variant that has no file yet.
- A stale file is served (`X-Mega-Feeds-Cache: STALE`) while it is refreshed;
  files older than one day are never handed out stale.
- Variants nobody requested for 7 days are removed.
- Settings: "Refresh every (seconds)" replaces "Cache" (default 3600); the
  feed table shows when each feed was generated and how long it took;
  "Regenerate feeds now" button.
- Stock changes (orders, imports) now invalidate feeds as well.

## 0.3.2

- Descriptions: `<address>`, `<pre>`, definition lists and other block tags now
  separate words too. Product copy pasted from other sites often wraps every
  line in `<address>`, which glued words together ("PantTrousers").

## 0.3.1

- Colour (and size) set on the product itself, not on a variation, is now
  sent when it has exactly one value. Shops that keep one colour per product
  previously produced no colour at all.
- Descriptions keep a space between paragraphs and list items
  ("Pant Trousers", not "PantTrousers"); the same applies to RSS text fields.
- Size labels such as "L - 42 - 46" are reduced to a single size ("L") and
  sent as the international system (INT); numeric sizes keep the configured
  system (EU by default).

## 0.3.0

- Pazaruvaj XML feed at `/pazaruvaj/` (Products / Product per variant,
  Identifier, Manufacturer, Name, ProductUrl, Price, Category with `>`,
  ImageUrl/ImageUrl2, Description, DeliveryTime/DeliveryCost, Barcode,
  NetPrice, Size, Color, GroupId, Attributes).
- Colour is only sent for variants that have their own picture.
- Sold-out items are listed with DeliveryTime NO (can be switched off).

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
