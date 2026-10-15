<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps stored feeds fresh without making a visitor (or a marketplace
 * crawler) wait for a render.
 *
 *   - content changes bump the cache generation, which schedules one
 *     debounced refresh of every known variant;
 *   - an hourly warm-up refreshes variants that are still requested and
 *     creates the default variants of every enabled feed;
 *   - the Router asks for a single variant when it serves a stale file.
 *
 * Rendering takes seconds on a large catalogue, so it only ever happens in
 * WP-Cron (one event per variant, each with its own time budget), except for
 * the very first request of a variant that has no file at all.
 */
class Regenerator {

	const EVENT_ALL    = 'mega_feeds_regenerate_all';
	const EVENT_ONE    = 'mega_feeds_regenerate_one';
	const EVENT_WARM   = 'mega_feeds_warm';
	const DEBOUNCE     = 15;                   // seconds after the last change.
	const KEEP_DAYS    = 7;                    // variants not requested for this long are dropped.
	const LOCK_SECONDS = 180;

	public static function hooks() {
		add_action( 'mega_feeds_invalidated', array( __CLASS__, 'schedule_all' ) );
		add_action( self::EVENT_ALL, array( __CLASS__, 'run_all' ) );
		add_action( self::EVENT_ONE, array( __CLASS__, 'run_one' ), 10, 3 );
		add_action( self::EVENT_WARM, array( __CLASS__, 'warm' ) );
		add_action( 'init', array( __CLASS__, 'ensure_warm_schedule' ) );
	}

	public static function ensure_warm_schedule() {
		if ( ! wp_next_scheduled( self::EVENT_WARM ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::EVENT_WARM );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::EVENT_WARM );
		wp_clear_scheduled_hook( self::EVENT_ALL );
		wp_clear_scheduled_hook( self::EVENT_ONE );
	}

	/**
	 * Content changed: refresh everything shortly, once. Further changes
	 * inside the debounce window ride on the already scheduled event.
	 */
	public static function schedule_all() {
		if ( ! wp_next_scheduled( self::EVENT_ALL ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE, self::EVENT_ALL );
		}
	}

	/**
	 * Ask for one variant to be refreshed now-ish and poke WP-Cron so it does
	 * not wait for the next visitor.
	 */
	public static function request_one( $feed_id, $scope, $limit ) {
		$args = array( (string) $feed_id, (string) $scope, (int) $limit );
		if ( ! wp_next_scheduled( self::EVENT_ONE, $args ) ) {
			wp_schedule_single_event( time(), self::EVENT_ONE, $args );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Every variant worth keeping fresh: the default address of each enabled
	 * feed scope, plus variants a client asked for recently.
	 *
	 * @return array[] each { feed, scope, limit }
	 */
	public static function targets() {
		$targets = array();

		foreach ( Registry::all() as $id => $feed ) {
			if ( ! Settings::feed_enabled( $id ) ) {
				continue;
			}
			foreach ( array_keys( $feed->scopes() ) as $scope ) {
				$args = Router::normalize_args( $feed, $scope, 0 );
				$targets[ Store::key( $id, $args ) ] = array(
					'feed'  => $id,
					'scope' => $scope,
					'limit' => $args['limit'],
				);
			}
		}

		$cutoff = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		foreach ( Store::all() as $key => $meta ) {
			if ( isset( $targets[ $key ] ) ) {
				continue;
			}
			if ( empty( $meta['feed'] ) || ! Registry::get( $meta['feed'] ) ) {
				continue;
			}
			if ( isset( $meta['requested_at'] ) && (int) $meta['requested_at'] >= $cutoff ) {
				$targets[ $key ] = array(
					'feed'  => $meta['feed'],
					'scope' => isset( $meta['args']['scope'] ) ? $meta['args']['scope'] : '',
					'limit' => isset( $meta['args']['limit'] ) ? (int) $meta['args']['limit'] : 0,
				);
			}
		}

		return $targets;
	}

	/**
	 * Fan out: one cron event per variant, so each gets its own time budget.
	 */
	public static function run_all() {
		$delay = 0;
		foreach ( self::targets() as $target ) {
			$args = array( $target['feed'], $target['scope'], (int) $target['limit'] );
			if ( ! wp_next_scheduled( self::EVENT_ONE, $args ) ) {
				wp_schedule_single_event( time() + $delay, self::EVENT_ONE, $args );
				$delay += 2;
			}
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Hourly: refresh what is stale or missing, drop what nobody asks for.
	 */
	public static function warm() {
		$ttl = (int) Settings::get( 'cache_ttl' );
		$gen = Cache::generation();

		foreach ( self::targets() as $key => $target ) {
			$record = Store::read( $key );
			$stale  = ! $record
				|| $record['meta']['generation'] !== $gen
				|| ( isset( $record['meta']['version'] ) && MEGA_FEEDS_VERSION !== $record['meta']['version'] )
				|| ( $ttl > 0 && time() - (int) $record['meta']['generated_at'] >= $ttl );
			if ( $stale ) {
				self::request_one( $target['feed'], $target['scope'], $target['limit'] );
			}
		}

		// Prune variants nobody has asked for in a week.
		$cutoff  = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		$targets = self::targets();
		foreach ( Store::all() as $key => $meta ) {
			if ( ! isset( $targets[ $key ] ) && ( empty( $meta['requested_at'] ) || (int) $meta['requested_at'] < $cutoff ) ) {
				Store::delete( $key );
			}
		}
	}

	public static function run_one( $feed_id, $scope, $limit ) {
		self::regenerate( (string) $feed_id, (string) $scope, (int) $limit );
	}

	/**
	 * Render a variant and store it. Guarded by a lock so two requests
	 * never render the same variant at once.
	 *
	 * @return array|null The stored entry (body, modified) or null when
	 *                    another process already holds the lock.
	 */
	public static function regenerate( $feed_id, $scope, $limit ) {
		$feed = Registry::get( $feed_id );
		if ( ! $feed || ! Settings::feed_enabled( $feed_id ) ) {
			return null;
		}

		$args = Router::normalize_args( $feed, $scope, $limit );
		$key  = Store::key( $feed_id, $args );
		$lock = 'mf_lock_' . md5( $key );

		if ( get_transient( $lock ) ) {
			return null;
		}
		set_transient( $lock, 1, self::LOCK_SECONDS );

		// A catalogue render can outlast the default 30 s. Where the host
		// forbids raising the limit this is simply a no-op.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		@ignore_user_abort( true );

		$started = microtime( true );
		$entry   = $feed->render( $args );
		$entry['duration'] = microtime( true ) - $started;
		Store::write( $key, $feed_id, $args, $entry );

		delete_transient( $lock );
		return $entry;
	}
}
