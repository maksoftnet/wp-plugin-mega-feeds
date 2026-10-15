=== Mega Feeds ===
Contributors: maksoft
Tags: feed, rss, export, syndication, marketplace
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve your site content as dynamic, cached feeds for aggregators, social networks and marketplaces.

== Description ==

Edit content once on your own site and let other platforms pull it in the
format they expect. Mega Feeds generates feeds on demand, keeps them bounded
and cached, and answers conditional requests so polling stays cheap.

Available now:

* RSS 2.0 at /rss/ (newest changes), /rss/posts/, /rss/pages/, /rss/products/ with main and additional images.
* Glami product feed at /glami/ (WooCommerce).
* Pazaruvaj product feed at /pazaruvaj/ (WooCommerce).

Planned: marketplace product feeds and further syndication formats.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/mega-feeds`.
2. Activate it.
3. Open Settings > Mega Feeds to find the feed URLs.

== Changelog ==

= 0.3.3 =
* Feeds stored as files and regenerated in the background (WP-Cron), stale file served while refreshing.

= 0.3.2 =
* Descriptions: more block tags separate words.

= 0.3.1 =
* Colour from product-level attributes, readable descriptions, single-size normalisation.

= 0.3.0 =
* Pazaruvaj feed.

= 0.2.0 =
* RSS scopes, additional images, Glami feed, product limits.

= 0.1.0 =
* First release: dynamic RSS 2.0 feed, limits, cache, settings screen.
