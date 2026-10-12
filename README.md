# Mega Feeds

WordPress plugin that serves a site's content as dynamic, cached feeds for
aggregators, social networks and marketplaces. Edit once locally, let the
other platforms pull it.

## Feeds

| Id | URL | Status |
|----|-----|--------|
| `rss` | `/mega-feeds/rss/` or `/?mega_feed=rss` | available |
| `pazaruvaj` | - | planned |
| `glami` | - | planned |
| `maksoft-cms` | - | planned |

Query parameters: `limit` (bounded by the configured maximum), `type` (one of
the post types enabled in settings).

## Design

- **Dynamic, bounded, cached.** A feed is rendered per request, never more than
  `limit_max` items, and reused from a transient (which WordPress routes to a
  persistent object cache when the site has one).
- **Generation-based invalidation.** Saving, trashing or deleting content, term
  changes and settings changes move one generation token; nothing is deleted.
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
    src/Feeds/               one class per format
    src/Admin/               settings screen

## Requirements

WordPress 5.8+, PHP 7.2+. Developed in the Maksoft WP fleet lab
(`wp/plugins/mega-feeds`, linked into the `delta` slot).
