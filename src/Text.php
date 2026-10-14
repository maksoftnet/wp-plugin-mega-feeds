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
	 * HTML to plain text. Block-level tags and <br> become a space first, so
	 * "<p>One</p><p>Two</p>" reads "One Two", not "OneTwo".
	 */
	public static function plain( $html ) {
		// Opening and closing block tags both separate words; <br> and <hr>
		// are void. <address> and <pre> matter: pasted product copy often
		// wraps every line in <address>.
		$blocks = 'p|div|li|ul|ol|dl|dt|dd|h[1-6]|tr|td|th|table|thead|tbody|tfoot|caption|blockquote|section|article|aside|header|footer|nav|main|figure|figcaption|address|pre|form|fieldset|details|summary';
		$html   = preg_replace( '#</?(?:' . $blocks . ')(?=[\s/>])[^>]*>|<(?:br|hr)(?=[\s/>])[^>]*>#i', ' ', (string) $html );
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
