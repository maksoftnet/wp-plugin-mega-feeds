<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small text helpers shared by every feed.
 */
class Text {

	/**
	 * HTML to plain text. Block-level closing tags and <br> become a space
	 * first, so "<p>One</p><p>Two</p>" reads "One Two", not "OneTwo".
	 */
	public static function plain( $html ) {
		$html = preg_replace( '#</(p|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|article)>|<br\s*/?>#i', ' ', (string) $html );
		$text = wp_strip_all_tags( strip_shortcodes( (string) $html ) );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( null === $text ? '' : $text );
	}

	/**
	 * Reduce a size label to a single size and name its system.
	 *
	 * Marketplaces want one size per item and no extra words, but shops
	 * often label sizes like "L - 42 - 46" (letter size plus an EU range).
	 * A leading letter size wins and is international (INT); anything else
	 * is returned untouched with an empty system, which the feed fills from
	 * its own setting (EU by default).
	 *
	 * @param string $raw
	 * @return array { value: string, system: string }
	 */
	public static function size( $raw ) {
		$value = trim( (string) $raw );
		if ( '' === $value ) {
			return array( 'value' => '', 'system' => '' );
		}

		if ( preg_match( '/^(\dXL|X{1,3}S|X{1,3}L|S|M|L)(?![A-Za-z0-9])/i', $value, $m ) ) {
			return array( 'value' => strtoupper( $m[1] ), 'system' => 'INT' );
		}

		return array( 'value' => $value, 'system' => '' );
	}
}
