<?php
/**
 * Write access to Google Calendars through a service account (the "technical user").
 *
 * The service account only reaches calendars that were shared with it ("Make changes to events").
 * Its key is set in wp-config.php – never in the database or the repository:
 *
 *   define( 'CALP_GOOGLE_KEY_FILE', '/path/outside/the/webroot/calendar-google.json' );
 *   // or the JSON itself: define( 'CALP_GOOGLE_KEY', '{"type":"service_account",…}' );
 *
 * Without a key the plugin only reads calendars (iCal), as before.
 */

namespace CalendarPlugin\Events;

final class Google {

	private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
	private const TOKEN = 'https://oauth2.googleapis.com/token';
	private const API   = 'https://www.googleapis.com/calendar/v3/calendars/';

	/** @return array{client_email: string, private_key: string}|null */
	private static function key(): ?array {
		return self::read()['key'];
	}

	/**
	 * Reads the key from wp-config.php and says what is wrong with it.
	 *
	 * @return array{key: array|null, problem: string}
	 */
	public static function read(): array {
		if ( defined( 'CALP_GOOGLE_KEY_FILE' ) ) {
			if ( ! is_readable( CALP_GOOGLE_KEY_FILE ) ) {
				return array(
					'key'     => null,
					'problem' => 'CALP_GOOGLE_KEY_FILE is set, but the file cannot be read: ' . CALP_GOOGLE_KEY_FILE,
				);
			}
			$json = (string) file_get_contents( CALP_GOOGLE_KEY_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( defined( 'CALP_GOOGLE_KEY' ) ) {
			$json = (string) CALP_GOOGLE_KEY;
		} else {
			return array(
				'key'     => null,
				'problem' => '',
			);
		}
		return self::check( $json );
	}

	/**
	 * Checks the JSON of a key. Repairs line breaks in the private key that turned into real
	 * newlines when copying. No WordPress functions, so it can be tested on its own.
	 *
	 * @return array{key: array|null, problem: string}
	 */
	public static function check( string $json ): array {
		$json = trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $json ) );
		$k    = json_decode( $json, true );
		if ( ! is_array( $k ) ) {
			$repaired = preg_replace_callback(
				'/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----\s*/s',
				static fn( $m ) => str_replace( array( "\r\n", "\n", "\r" ), '\\n', rtrim( $m[0] ) ) . '\\n',
				$json
			);
			$k = json_decode( (string) $repaired, true );
		}
		if ( ! is_array( $k ) ) {
			return array(
				'key'     => null,
				'problem' => 'The key is not valid JSON (' . json_last_error_msg() . '). Please paste the content of the .json file unchanged.',
			);
		}
		foreach ( array( 'client_email', 'private_key' ) as $field ) {
			if ( empty( $k[ $field ] ) ) {
				return array(
					'key'     => null,
					'problem' => 'The key has no "' . $field . '" – is it the JSON file of a service account?',
				);
			}
		}
		if ( ! openssl_pkey_get_private( $k['private_key'] ) ) {
			return array(
				'key'     => null,
				'problem' => 'The private key ("private_key") is damaged. Please paste the .json file again, unchanged.',
			);
		}
		return array(
			'key'     => $k,
			'problem' => '',
		);
	}

	public static function configured(): bool {
		return null !== self::key();
	}

	/** E-mail address of the service account (to share the calendars with). */
	public static function account(): string {
		$k = self::key();
		return $k ? (string) $k['client_email'] : '';
	}

	/** Calendar ID from an iCal address (…/ical/<ID>/public|private-…/basic.ics) or an ID typed in directly. */
	public static function calendar_id( string $url_or_id ): string {
		$url_or_id = trim( $url_or_id );
		if ( preg_match( '#/ical/([^/]+)/#', $url_or_id, $m ) ) {
			return rawurldecode( $m[1] );
		}
		return str_contains( $url_or_id, '@' ) && ! str_contains( $url_or_id, '/' ) ? $url_or_id : '';
	}

	private static function b64( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** Access token (valid one hour, cached). @return string|\WP_Error */
	private static function token() {
		$token = get_transient( 'calp_google_token' );
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}
		$k = self::key();
		if ( ! $k ) {
			return new \WP_Error( 'calp_google', 'No Google key set up.' );
		}
		$now = time();
		$jwt = self::b64( (string) wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.'
			. self::b64(
				(string) wp_json_encode(
					array(
						'iss'   => $k['client_email'],
						'scope' => self::SCOPE,
						'aud'   => self::TOKEN,
						'iat'   => $now,
						'exp'   => $now + 3600,
					)
				)
			);
		if ( ! openssl_sign( $jwt, $signature, $k['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( 'calp_google', 'The Google key is invalid (signing failed).' );
		}
		$response = wp_remote_post(
			self::TOKEN,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt . '.' . self::b64( $signature ),
				),
			)
		);
		$data = is_wp_error( $response ) ? array() : (array) json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$reason = is_wp_error( $response ) ? $response->get_error_message() : ( $data['error_description'] ?? $data['error'] ?? 'unknown' );
			return new \WP_Error( 'calp_google', 'Google sign-in failed: ' . $reason );
		}
		set_transient( 'calp_google_token', $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 ) );
		return $data['access_token'];
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function request( string $method, string $path, ?array $body = null ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_request(
			self::API . $path,
			array(
				'method'  => $method,
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => null !== $body ? wp_json_encode( $body ) : null,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'calp_google', 'Google not reachable: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = (array) json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 300 ) {
			$message = $data['error']['message'] ?? ( 'HTTP ' . $code );
			if ( 403 === $code || 404 === $code ) {
				$message .= ' – is the calendar shared with ' . self::account() . ' ("Make changes to events")?';
			}
			return new \WP_Error( 'calp_google', 'Google: ' . $message, array( 'status' => $code ) );
		}
		return $data;
	}

	/**
	 * All events of a calendar in a period, including deleted ones (status "cancelled"), series
	 * as one event with their rule.
	 *
	 * @return array{role: string, items: array<int, array>}|\WP_Error
	 */
	public static function events( string $calendar, int $from, int $to ) {
		$items = array();
		$role  = '';
		$page  = '';
		do {
			$query = array(
				'timeMin'      => gmdate( 'c', $from ),
				'timeMax'      => gmdate( 'c', $to ),
				'showDeleted'  => 'true',
				'singleEvents' => 'false',
				'maxResults'   => 2500,
			);
			if ( '' !== $page ) {
				$query['pageToken'] = $page;
			}
			$data = self::request( 'GET', rawurlencode( $calendar ) . '/events?' . http_build_query( $query ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$role  = (string) ( $data['accessRole'] ?? $role );
			$items = array_merge( $items, (array) ( $data['items'] ?? array() ) );
			$page  = (string) ( $data['nextPageToken'] ?? '' );
		} while ( '' !== $page && count( $items ) < 10000 );
		return array(
			'role'  => $role,
			'items' => $items,
		);
	}

	/** @return array<string, mixed>|\WP_Error the new event */
	public static function insert( string $calendar, array $event ) {
		return self::request( 'POST', rawurlencode( $calendar ) . '/events', $event );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function patch( string $calendar, string $event_id, array $changes ) {
		return self::request( 'PATCH', rawurlencode( $calendar ) . '/events/' . rawurlencode( $event_id ), $changes );
	}

	/** @return true|\WP_Error */
	public static function delete( string $calendar, string $event_id ) {
		$r = self::request( 'DELETE', rawurlencode( $calendar ) . '/events/' . rawurlencode( $event_id ) );
		if ( is_wp_error( $r ) && in_array( $r->get_error_data()['status'] ?? 0, array( 404, 410 ), true ) ) {
			return true; // Already gone.
		}
		return is_wp_error( $r ) ? $r : true;
	}

	/** Whether the service account may write to a calendar. */
	public static function can_write( string $role ): bool {
		return in_array( $role, array( 'writer', 'owner' ), true );
	}
}
