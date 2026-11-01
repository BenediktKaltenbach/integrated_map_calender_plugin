<?php
/**
 * Splits the description of a Google Calendar event into free text and key lines.
 *
 * Layout: free text, then a line "---", then lines "Key: value". Keys are understood in
 * English and German ("Registration:" / "Anmeldung:"), case- and whitespace-insensitive.
 * No WordPress dependency except the translation function, so it stays testable.
 */

namespace CalendarPlugin\Events;

final class Parser {

	/** Accepted spellings (normalised) → field name. */
	private const KEYS = array(
		'registration' => 'registration',
		'register'     => 'registration',
		'anmeldung'    => 'registration',
		'anmelden'     => 'registration',
		'info'         => 'info',
		'infos'        => 'info',
		'website'      => 'info',
		'class'        => 'class',
		'klasse'       => 'class',
		'country'      => 'country',
		'land'         => 'country',
		'status'       => 'status',
		'image'        => 'image',
		'picture'      => 'image',
		'bild'         => 'image',
		'foto'         => 'image',
		'coordinates'  => 'coordinates',
		'koordinaten'  => 'coordinates',
		'geo'          => 'coordinates',
		'map'          => 'coordinates',
		'karte'        => 'coordinates',
		'display'      => 'display',
		'anzeige'      => 'display',
	);

	private const STATUS = array(
		'cancelled'   => 'cancelled',
		'canceled'    => 'cancelled',
		'abgesagt'    => 'cancelled',
		'postponed'   => 'postponed',
		'verschoben'  => 'postponed',
		'provisional' => 'provisional',
		'tentative'   => 'provisional',
		'vorlaeufig'  => 'provisional',
		'vorlaufig'   => 'provisional',
	);

	/** Status texts for lists and popups. */
	public static function status_labels(): array {
		return array(
			'cancelled'   => __( 'cancelled', 'calendar-plugin' ),
			'postponed'   => __( 'postponed', 'calendar-plugin' ),
			'provisional' => __( 'provisional', 'calendar-plugin' ),
		);
	}

	/**
	 * @return array{text: string, fields: array<string, string>, warnings: string[]}
	 */
	public static function description( string $raw ): array {
		$lines     = preg_split( '/\R/u', self::html_to_text( $raw ) );
		$separator = null;
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^\s*[-–—]{3,}\s*$/u', $line ) ) {
				$separator = $i;
			}
		}

		if ( null !== $separator ) {
			$free  = array_slice( $lines, 0, $separator );
			$block = array_slice( $lines, $separator + 1 );
		} else {
			// Without separator: only known key lines at the very end count.
			$i = count( $lines );
			while ( $i > 0 && ( '' === trim( $lines[ $i - 1 ] ) || null !== self::known_key( $lines[ $i - 1 ] ) ) ) {
				--$i;
			}
			$free  = array_slice( $lines, 0, $i );
			$block = array_slice( $lines, $i );
		}

		$fields   = array();
		$warnings = array();
		foreach ( $block as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( ! preg_match( '/^\s*([^:]+?)\s*:\s*(.*?)\s*$/u', $line, $m ) ) {
				$warnings[] = sprintf( 'Line not understood: "%s"', trim( $line ) );
				continue;
			}
			$field = self::KEYS[ self::normalise( $m[1] ) ] ?? null;
			if ( null === $field ) {
				$warnings[] = sprintf( 'Unknown key "%s"', $m[1] );
				continue;
			}
			$value = self::value( $field, $m[2] );
			if ( null === $value ) {
				$warnings[] = sprintf( 'Invalid value for "%1$s": "%2$s"', $m[1], $m[2] );
				continue;
			}
			$fields[ $field ] = $value;
		}

		return array(
			'text'     => trim( implode( "\n", $free ) ),
			'fields'   => $fields,
			'warnings' => $warnings,
		);
	}

	/** Google often stores descriptions as HTML. Links become their address. */
	public static function html_to_text( string $raw ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		if ( str_contains( $text, '<' ) ) {
			$text = preg_replace_callback(
				'#<a\s[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>(.*?)</a>#is',
				static function ( $m ) {
					$label = trim( strip_tags( $m[2] ) );
					$href  = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( '' === $label || $label === $href || preg_match( '#^(https?://|www\.)#i', $label ) ) {
						return $href;
					}
					return $label . ' (' . $href . ')';
				},
				$text
			);
			$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
			$text = preg_replace( '#</(p|div|li|h[1-6])>#i', "\n", $text );
			$text = strip_tags( $text );
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_replace( "\u{00A0}", ' ', $text );
	}

	private static function known_key( string $line ): ?string {
		if ( ! preg_match( '/^\s*([^:]+?)\s*:/u', $line, $m ) ) {
			return null;
		}
		return self::KEYS[ self::normalise( $m[1] ) ] ?? null;
	}

	private static function normalise( string $s ): string {
		$s = mb_strtolower( trim( $s ), 'UTF-8' );
		$s = strtr( $s, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		return preg_replace( '/[^a-z]/', '', $s );
	}

	private static function value( string $field, string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		switch ( $field ) {
			case 'registration':
			case 'info':
			case 'image':
				if ( preg_match( '/^www\./i', $value ) ) {
					$value = 'https://' . $value;
				}
				$ok = filter_var( $value, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $value );
				return $ok ? $value : null;

			case 'display':
				// Free text shown instead of the date, e.g. "every Tuesday" (keep it short).
				return mb_substr( $value, 0, 60, 'UTF-8' );

			case 'coordinates':
				// Numbers or a map link; resolved in Geo (short links need a request).
				return $value;

			case 'class':
				return mb_substr( $value, 0, 30, 'UTF-8' );

			case 'country':
				$value = strtoupper( $value );
				return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : null;

			case 'status':
				return self::STATUS[ self::normalise( $value ) ] ?? null;
		}
		return null;
	}
}
