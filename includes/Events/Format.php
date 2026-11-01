<?php
/**
 * Date formatting in the site language (month names via wp_date / date_i18n).
 */

namespace CalendarPlugin\Events;

final class Format {

	public static function tz(): \DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Europe/Berlin' );
	}

	/**
	 * Formats a range, e.g. "2–5 April 2027", "30 May – 2 June 2027" or "23 May 2027, 10:00–14:00".
	 * German sites get the same structure with German month names ("2.–5. April 2027").
	 *
	 * @param int  $start   Unix timestamp.
	 * @param int  $end     Unix timestamp (all-day: exclusive, as in iCal).
	 * @param bool $all_day All-day event.
	 */
	public static function range( int $start, int $end, bool $all_day ): string {
		$tz = self::tz();
		$s  = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $tz );
		$e  = ( new \DateTimeImmutable( '@' . max( $start, $end ) ) )->setTimezone( $tz );
		$de = str_starts_with( (string) get_locale(), 'de' );
		$d  = $de ? 'j.' : 'j';
		$f  = static fn( \DateTimeImmutable $t, string $fmt ) => wp_date( $fmt, $t->getTimestamp(), $tz );

		if ( $all_day ) {
			$e = $e->modify( '-1 day' );
			if ( $e < $s || $s->format( 'Y-m-d' ) === $e->format( 'Y-m-d' ) ) {
				return $f( $s, $d . ' F Y' );
			}
			if ( $s->format( 'Y-m' ) === $e->format( 'Y-m' ) ) {
				return $f( $s, $d ) . '–' . $f( $e, $d . ' F Y' );
			}
			if ( $s->format( 'Y' ) === $e->format( 'Y' ) ) {
				return $f( $s, $d . ' F' ) . ' – ' . $f( $e, $d . ' F Y' );
			}
			return $f( $s, $d . ' F Y' ) . ' – ' . $f( $e, $d . ' F Y' );
		}

		$time = $de ? 'G:i' : 'H:i';
		if ( $s->format( 'Y-m-d' ) === $e->format( 'Y-m-d' ) ) {
			return $f( $s, $d . ' F Y' ) . ', ' . $f( $s, $time ) . ( $e > $s ? '–' . $f( $e, $time ) : '' );
		}
		return $f( $s, $d . ' F Y, ' . $time ) . ' – ' . $f( $e, $d . ' F Y, ' . $time );
	}

	/** Flag emoji from an ISO country code ("NL" → 🇳🇱). */
	public static function flag( string $country ): string {
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return '';
		}
		return mb_chr( 0x1F1E6 + ord( $country[0] ) - 65 ) . mb_chr( 0x1F1E6 + ord( $country[1] ) - 65 );
	}

	/** Country name for screen readers in the site language ("NL" → "Netherlands"). */
	public static function country_name( string $country ): string {
		if ( class_exists( '\Locale' ) ) {
			$name = \Locale::getDisplayRegion( '-' . $country, (string) get_locale() );
			if ( $name && $name !== $country ) {
				return $name;
			}
		}
		return $country;
	}
}
