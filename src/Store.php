<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered feeds on disk.
 *
 * Every feed variant (feed + scope + limit) is two files in
 * wp-content/uploads/mega-feeds/: the document (.xml) and its metadata
 * (.json: generation, version, generated_at, newest item, requested_at).
 *
 * Files, not options or transients: a catalogue feed is megabytes, and the
 * options table or an object cache is the wrong place for it. Writes are
 * atomic (temporary file + rename) so a reader never sees half a document,
 * and the directory is closed to direct web access - feeds are only served
 * through the Router, which owns the HTTP semantics.
 */
class Store {

	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'mega-feeds/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Index file and a deny rule: nothing here is meant to be fetched
			// directly.
			@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			@file_put_contents(
				$dir . '.htaccess',
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
			);
		}
		return $dir;
	}

	/**
	 * File-name stem of a variant: stable, filesystem safe, readable.
	 */
	public static function key( $feed_id, array $args ) {
		$scope = isset( $args['scope'] ) && '' !== $args['scope'] ? $args['scope'] : 'all';
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		return sanitize_file_name( $feed_id . '-' . $scope . '-' . $limit );
	}

	/**
	 * @return array|null { body_path, meta } or null when the variant has no
	 *                    complete pair of files yet.
	 */
	public static function read( $key ) {
		$dir  = self::dir();
		$body = $dir . $key . '.xml';
		$json = $dir . $key . '.json';
		if ( ! is_file( $body ) || ! is_file( $json ) ) {
			return null;
		}
		$meta = json_decode( (string) file_get_contents( $json ), true );
		if ( ! is_array( $meta ) ) {
			return null;
		}
		return array(
			'body_path' => $body,
			'meta'      => $meta,
		);
	}

	/**
	 * Write a rendered document and its metadata. The document goes first,
	 * the metadata last, so a variant only "exists" once it is complete.
	 */
	public static function write( $key, $feed_id, array $args, array $entry ) {
		$dir  = self::dir();
		$body = $dir . $key . '.xml';
		$json = $dir . $key . '.json';

		$previous = self::read( $key );
		$meta     = array(
			'feed'         => $feed_id,
			'args'         => $args,
			'generation'   => Cache::generation(),
			'version'      => MEGA_FEEDS_VERSION,
			'generated_at' => time(),
			'modified'     => isset( $entry['modified'] ) ? (int) $entry['modified'] : 0,
			'etag'         => '"' . md5( $entry['body'] ) . '"',
			'bytes'        => strlen( $entry['body'] ),
			'duration'     => isset( $entry['duration'] ) ? round( (float) $entry['duration'], 2 ) : 0,
			'requested_at' => $previous && isset( $previous['meta']['requested_at'] ) ? (int) $previous['meta']['requested_at'] : time(),
		);

		if ( ! self::atomic_write( $body, $entry['body'] ) ) {
			return false;
		}
		return self::atomic_write( $json, wp_json_encode( $meta ) ) ? $meta : false;
	}

	/**
	 * Remember that a variant is still being asked for, so the scheduled
	 * refresh keeps it warm. Written at most once an hour per variant: a hit
	 * must not cost a disk write.
	 */
	public static function touch( $key, array $meta ) {
		$last = isset( $meta['requested_at'] ) ? (int) $meta['requested_at'] : 0;
		if ( time() - $last < HOUR_IN_SECONDS ) {
			return;
		}
		$meta['requested_at'] = time();
		self::atomic_write( self::dir() . $key . '.json', wp_json_encode( $meta ) );
	}

	/**
	 * Metadata of every stored variant, key => meta.
	 */
	public static function all() {
		$out = array();
		foreach ( (array) glob( self::dir() . '*.json' ) as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $meta ) ) {
				$out[ basename( $file, '.json' ) ] = $meta;
			}
		}
		return $out;
	}

	public static function delete( $key ) {
		$dir = self::dir();
		@unlink( $dir . $key . '.xml' );
		@unlink( $dir . $key . '.json' );
	}

	/**
	 * Remove everything, e.g. on uninstall.
	 */
	public static function purge() {
		$dir = self::dir();
		foreach ( (array) glob( $dir . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( $dir );
	}

	private static function atomic_write( $path, $contents ) {
		$tmp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) {
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}
}
