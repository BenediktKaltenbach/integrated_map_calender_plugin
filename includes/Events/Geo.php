<?php
/**
 * Coordinates for events.
 *
 * Order (first match wins):
 *   1. chosen by hand in the admin (kept on every sync)
 *   2. key line "Coordinates:" – numbers or a Google Maps / OpenStreetMap link
 *   3. GEO field of the calendar entry
 *   4. location text, looked up at OpenStreetMap (Nominatim)
 *
 * Nominatim policy: max. 1 request per second, own user agent, cache results,
 * no search-as-you-type (hence the "Find place" button).
 */

namespace CalendarPlugin\Events;

use const CalendarPlugin\VERSION;

final class Geo {

	private const NOMINATIM     = 'https://nominatim.openstreetmap.org/search';
	private const CACHE_DAYS    = 30;
	private const MAX_PER_SYNC  = 15;

	private static int $requests = 0;

	/**
	 * Coordinates from text: "50.7151, 7.1393" or Google Maps / OpenStreetMap / Apple links.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	public static function parse( string $text ): ?array {
		$text     = trim( rawurldecode( $text ) );
		$patterns = array(
			'/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',
			'/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',
			'/[?&](?:q|query|ll|sll|center|destination|coordinate)=(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/',
			'/[?&]mlat=(-?\d{1,2}\.\d+)&mlon=(-?\d{1,3}\.\d+)/',
			'/#map=\d+\/(-?\d{1,2}\.\d+)\/(-?\d{1,3}\.\d+)/',
			'/^(-?\d{1,2}\.\d+)\s*[,; ]\s*(-?\d{1,3}\.\d+)$/',
		);
		foreach ( $patterns as $re ) {
			if ( preg_match( $re, $text, $m ) ) {
				$lat = (float) $m[1];
				$lng = (float) $m[2];
				if ( abs( $lat ) <= 90 && abs( $lng ) <= 180 && ( 0.0 !== $lat || 0.0 !== $lng ) ) {
					return array(
						'lat' => round( $lat, 6 ),
						'lng' => round( $lng, 6 ),
					);
				}
			}
		}
		return null;
	}

	/** Like parse(), but also understands shared text with a link and resolves Google/Apple short links once. */
	public static function parse_with_short_link( string $text ): ?array {
		$geo = self::parse( $text );
		if ( $geo ) {
			return $geo;
		}
		if ( ! preg_match( '#https?://\S+#i', $text, $m ) ) {
			return null;
		}
		$url = rtrim( $m[0], '.,;)' );
		$geo = self::parse( $url );
		if ( $geo || ! preg_match( '#^https://(maps\.app\.goo\.gl|goo\.gl/maps|maps\.apple(\.com)?/p)/#i', $url ) ) {
			return $geo;
		}
		$cache  = 'calp_geo_link_' . md5( $url );
		$target = get_transient( $cache );
		if ( false === $target ) {
			$response = wp_remote_head(
				$url,
				array(
					'redirection' => 0,
					'timeout'     => 10,
				)
			);
			$target   = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'location' );
			set_transient( $cache, $target, '' !== $target ? self::CACHE_DAYS * DAY_IN_SECONDS : HOUR_IN_SECONDS );
		}
		return '' !== $target ? self::parse( $target ) : null;
	}

	private static function cached( string $query, int $limit ): bool {
		$query = trim( preg_replace( '/\s+/', ' ', $query ) );
		return is_array( get_transient( 'calp_geo_' . md5( mb_strtolower( $query ) . '|' . $limit ) ) );
	}

	/**
	 * Place search at OpenStreetMap, cached.
	 *
	 * @return array<int, array{lat: float, lng: float, name: string}>|\WP_Error
	 */
	public static function search( string $query, int $limit = 5 ) {
		$query = trim( preg_replace( '/\s+/', ' ', $query ) );
		if ( mb_strlen( $query ) < 3 ) {
			return array();
		}
		$cache = 'calp_geo_' . md5( mb_strtolower( $query ) . '|' . $limit );
		$hits  = get_transient( $cache );
		if ( is_array( $hits ) ) {
			return $hits;
		}

		// At most 1 request per second (across all visitors and the sync).
		$last = (float) get_transient( 'calp_geo_last' );
		$wait = $last + 1.1 - microtime( true );
		if ( $wait > 0 ) {
			usleep( (int) ( min( $wait, 2 ) * 1000000 ) );
		}
		set_transient( 'calp_geo_last', microtime( true ), MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			add_query_arg(
				array(
					'q'               => $query,
					'format'          => 'jsonv2',
					'limit'           => $limit,
					'accept-language' => substr( (string) get_locale(), 0, 2 ),
				),
				self::NOMINATIM
			),
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'CalendarPlugin/' . VERSION . ' (' . home_url( '/' ) . ')' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'calp_geo', __( 'OpenStreetMap is not reachable right now.', 'calendar-plugin' ) );
		}

		$hits = array();
		foreach ( (array) json_decode( wp_remote_retrieve_body( $response ), true ) as $t ) {
			if ( isset( $t['lat'], $t['lon'] ) ) {
				$hits[] = array(
					'lat'  => round( (float) $t['lat'], 6 ),
					'lng'  => round( (float) $t['lon'], 6 ),
					'name' => (string) ( $t['display_name'] ?? $query ),
				);
			}
		}
		set_transient( $cache, $hits, self::CACHE_DAYS * DAY_IN_SECONDS );
		return $hits;
	}

	/** Determines and stores the coordinates of an event (during sync and after saving). */
	public static function check( int $id ): void {
		if ( ! $id ) {
			return;
		}
		$manual = get_post_meta( $id, '_calp_geo_manual', true );
		if ( is_array( $manual ) && isset( $manual['lat'], $manual['lng'] ) ) {
			self::store( $id, $manual + array( 'source' => 'manual' ) );
			return;
		}

		$fields = (array) get_post_meta( $id, '_calp_fields', true );
		$error  = '';
		if ( ! empty( $fields['coordinates'] ) ) {
			$geo = self::parse_with_short_link( $fields['coordinates'] );
			if ( $geo ) {
				self::store( $id, $geo + array( 'source' => 'line' ) );
				return;
			}
			$error = __( 'Line "Coordinates:" not readable – use numbers like "50.7151, 7.1393" or a map link.', 'calendar-plugin' );
			update_post_meta( $id, '_calp_geo_error', $error );
		}

		$ics = get_post_meta( $id, '_calp_geo_ics', true );
		if ( is_array( $ics ) && isset( $ics['lat'], $ics['lng'] ) ) {
			self::store( $id, $ics + array( 'source' => 'calendar' ), $error );
			return;
		}

		// OpenStreetMap only for upcoming events and limited per sync (Nominatim policy).
		$location = trim( (string) get_post_meta( $id, '_calp_location', true ) );
		if ( '' === $location || (int) get_post_meta( $id, '_calp_end', true ) < time() ) {
			return;
		}
		$old = get_post_meta( $id, '_calp_geo', true );
		if ( is_array( $old ) && 'osm' === ( $old['source'] ?? '' ) && ( $old['query'] ?? '' ) === $location ) {
			return;
		}
		if ( ! self::cached( $location, 1 ) ) {
			if ( self::$requests >= self::MAX_PER_SYNC ) {
				return;
			}
			++self::$requests;
		}
		$hits = self::search( $location, 1 );
		if ( is_wp_error( $hits ) ) {
			return;
		}
		if ( ! $hits ) {
			delete_post_meta( $id, '_calp_geo' );
			/* translators: %s: location text */
			update_post_meta( $id, '_calp_geo_error', trim( $error . ' ' . sprintf( __( 'Place "%s" not found at OpenStreetMap – choose it in the event or add a line "Coordinates:".', 'calendar-plugin' ), $location ) ) );
			return;
		}
		self::store(
			$id,
			array(
				'lat'    => $hits[0]['lat'],
				'lng'    => $hits[0]['lng'],
				'name'   => $hits[0]['name'],
				'source' => 'osm',
				'query'  => $location,
			),
			$error
		);
	}

	private static function store( int $id, array $geo, string $error = '' ): void {
		update_post_meta( $id, '_calp_geo', $geo );
		if ( '' === $error ) {
			delete_post_meta( $id, '_calp_geo_error' );
		}
	}

	/** @return array{lat: float, lng: float, source: string, name?: string}|null */
	public static function of( int $id ): ?array {
		$geo = get_post_meta( $id, '_calp_geo', true );
		return is_array( $geo ) && isset( $geo['lat'], $geo['lng'] ) ? $geo : null;
	}

	public static function osm_link( float $lat, float $lng ): string {
		return sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=15/%1$s/%2$s', $lat, $lng );
	}
}
