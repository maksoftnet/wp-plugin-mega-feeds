# Mega Feeds

WordPress plugin that serves a site's content as dynamic, cached feeds for
aggregators, social networks and marketplaces. Edit once locally, let the
other platforms pull it.

## Feeds

| Id | URL | Status |
|----|-----|--------|
| `rss` | `/rss/` newest changes of every type | available |
| `rss` | `/rss/posts/`, `/rss/pages/`, `/rss/products/` | available |
| `glami` | `/glami/` | available |
| `pazaruvaj` | `/pazaruvaj/` | available |
| `maksoft-cms` | - | planned |

Without pretty permalinks every address also works as
`/?mega_feed=<id>&mega_scope=<scope>`.

Query parameters: `limit`, bounded by the configured maximum (content feeds
and product feeds have separate limits).

## Design

- **Dynamic, bounded, cached.** A feed is rendered per request, never more than
  `limit_max` items, and reused from a transient (which WordPress routes to a
  persistent object cache when the site has one).
- **Stored on disk, refreshed in the background.** Each feed variant is a
  file pair in `wp-content/uploads/mega-feeds/`. Requests are answered from the
  file (`HIT`); when content changed or the file is older than the refresh
  interval it is still served (`STALE`) while WP-Cron renders a new one. Only
  a variant with no file at all is rendered inline.
- **Generation token.** Saving, trashing or deleting content, term, stock and
  settings changes move one token; a file rendered under an older token is
  stale. Nothing is deleted on invalidation.
- **Debounced.** A burst of edits schedules one refresh, 15 seconds after the
  last change.
- **Conditional GET.** `ETag`, `Last-Modified`, `304`, `HEAD`. The document is
  byte-stable while content is unchanged.
- **One class per format.** Implement `MegaFeeds\Feeds\FeedInterface` and
  register through the `mega_feeds_register_feeds` filter.
- Served at `parse_request`, before the main query.

## Layout

    mega-feeds.php           bootstrap, activation, version gate
    src/Plugin.php           wiring
    src/Router.php           URLs, request args, HTTP semantics
    src/Registry.php         known formats
    src/Cache.php            generation + transients
    src/Settings.php         option, defaults, sanitizing
    src/Images.php           main + additional images of a post
    src/Products/Source.php  WooCommerce products -> per-variant offers
    src/Feeds/               one class per format
    src/Admin/               settings screen

## Requirements

WordPress 5.8+, PHP 7.2+. Developed in the Maksoft WP fleet lab
(`wp/plugins/mega-feeds`, linked into the `delta` slot).

## Operations

WP-Cron runs when the site gets a visit. For a feed that marketplaces poll,
prefer a real cron that calls WP-Cron on a schedule, and disable the
page-triggered one in `wp-config.php`:

    define( 'DISABLE_WP_CRON', true );

    */5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1

Response headers: `X-Mega-Feeds-Cache` (`HIT`, `STALE`, `MISS`, `BYPASS`) and
`X-Mega-Feeds-Generated` (when the served file was rendered).
