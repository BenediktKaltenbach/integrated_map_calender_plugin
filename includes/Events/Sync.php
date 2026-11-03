<?php
/**
 * Sync: fetches the iCal feeds of all categories that have one and mirrors their events.
 * The plugin only reads, it never writes to Google Calendar. Local events are not touched.
 */

namespace CalendarPlugin\Events;

use CalendarPlugin\Plugin;
use CalendarPlugin\Settings;

final class Sync {

	private const LOCK         = 'calp_sync_lock';
	public const OPTION_STATUS = 'calp_sync_status';
	public const OPTION_LAST   = 'calp_sync_last';
	private const OPTION_EMPTY = 'calp_sync_empty';

	/**
	 * All mirrored feed events: key → [id, category]. Across categories, so an event moved to
	 * another calendar in Google (same UID) only changes its category.
	 *
	 * @var array<string, array{id: int, category: string, twin?: string}>
	 */
	private static array $index = array();

	/** Called by the host's cron job via admin-post.php?action=calp_cron&key=… */
	public static function cron_url(): void {
		$key = (string) wp_unslash( $_GET['key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $key || ! hash_equals( (string) get_option( Settings::OPTION_CRON_KEY ), $key ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}
		$status = self::all();
		header( 'Content-Type: text/plain; charset=utf-8' );
		foreach ( $status as $slug => $s ) {
			echo esc_html( $slug . ': ' . ( ! empty( $s['ok'] ) ? 'ok, ' . (int) ( $s['count'] ?? 0 ) : ( $s['message'] ?? '' ) ) ) . "\n";
		}
		exit;
	}

	/** WP-Cron as fallback: only sync if the real cron job has not run for a while. */
	public static function when_due(): void {
		if ( time() - (int) get_option( self::OPTION_LAST, 0 ) >= 50 * MINUTE_IN_SECONDS ) {
			self::all();
		}
	}

	/** @return array<string, array<string, mixed>> status per category */
	public static function all(): array {
		if ( ! self::lock() ) {
			return array(
				'_' => array(
					'ok'      => false,
					'message' => __( 'A sync is already running.', 'calendar-plugin' ),
				),
			);
		}
		update_option( self::OPTION_LAST, time(), false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true );

		$status = array();
		try {
			// First the copy between legacy and category calendars, so the site reads the result.
			Bridge::scheduled();
			self::$index = self::index();
			foreach ( Settings::event_categories() as $slug => $c ) {
				if ( '' === $c['ical'] ) {
					continue;
				}
				$status[ $slug ] = self::category( $slug, $c['ical'] );
			}
			update_option( self::OPTION_STATUS, $status, false );
		} finally {
			delete_option( self::LOCK );
		}
		return $status;
	}

	/** Atomic lock; a lock older than 15 minutes counts as stuck. */
	private static function lock(): bool {
		global $wpdb;
		$now = (string) time();
		if ( Plugin::once( self::LOCK, $now ) ) {
			return true;
		}
		$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		if ( time() - (int) $old < 15 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, self::LOCK, $old ) );
		wp_cache_delete( self::LOCK, 'options' );
		return 1 === $ok;
	}

	private static function category( string $slug, string $url ): array {
		$fail = static fn( string $message ) => array(
			'ok'      => false,
			'time'    => time(),
			'message' => $message,
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return $fail( sprintf( __( 'Fetching failed: %s', 'calendar-plugin' ), $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return $fail( sprintf( __( 'The calendar answers with HTTP %d. Is the iCal address still valid?', 'calendar-plugin' ), $code ) );
		}

		$tz = Format::tz();
		try {
			$window_start = new \DateTimeImmutable( '-1 year', $tz );
			$feed         = Feed::read( wp_remote_retrieve_body( $response ), $window_start, new \DateTimeImmutable( '+3 years', $tz ) );
		} catch ( \Throwable $e ) {
			/* translators: %s: error message */
			return $fail( sprintf( __( 'iCal data not readable: %s', 'calendar-plugin' ), $e->getMessage() ) );
		}
		$events = $feed['events'];
		$count  = array(
			'new'     => 0,
			'changed' => 0,
			'removed' => 0,
		);
		$seen   = array();
		$here   = array_flip( array_column( $events, 'key' ) );

		foreach ( $events as $e ) {
			$seen[ $e['key'] ] = true;
			$entry             = self::$index[ $e['key'] ] ?? null;
			if ( null === $entry ) {
				$entry = self::adopt( $e, $here );
			}
			$id                = $entry['id'] ?? 0;
			$hash              = md5( wp_json_encode( $e ) );

			if ( $id && $entry['category'] === $slug && get_post_meta( $id, '_calp_hash', true ) === $hash && ! get_post_meta( $id, '_calp_removed', true ) ) {
				continue;
			}

			// "Category:" in the event moves it into another category of this site; the feed stays its source.
			$category = self::category_of( (string) ( $e['fields']['category'] ?? '' ), $slug );

			$result = wp_insert_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_type'    => Events::TYPE,
						'post_status'  => 'publish',
						'post_title'   => $e['title'],
						'post_content' => $e['text'],
						'meta_input'   => array(
							'_calp_source'   => 'feed',
							'_calp_key'      => $e['key'],
							'_calp_category' => $category,
							'_calp_feed'     => $slug,
							'_calp_start'    => $e['start'],
							'_calp_end'      => $e['end'],
							'_calp_all_day'  => $e['all_day'] ? 1 : 0,
							'_calp_location' => $e['location'],
							'_calp_status'   => $e['status'],
							'_calp_fields'   => $e['fields'],
							'_calp_warnings' => $e['warnings'],
							'_calp_geo_ics'  => $e['geo'],
							'_calp_hash'     => $hash,
						),
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				continue;
			}
			delete_post_meta( $result, '_calp_removed' );
			self::$index[ $e['key'] ] = array(
				'id'       => $result,
				'category' => $slug,
				'twin'     => self::twin( (string) $e['title'], (int) $e['start'] ),
			);
			++$count[ $id ? 'changed' : 'new' ];
		}

		// Events deleted in the calendar are only flagged. Events older than the window are kept.
		$gone = array();
		foreach ( self::$index as $key => $entry ) {
			if ( $entry['category'] === $slug && ! isset( $seen[ $key ] )
				&& (int) get_post_meta( $entry['id'], '_calp_end', true ) >= $window_start->getTimestamp() ) {
				$gone[] = $entry['id'];
			}
		}

		// An empty feed can be a Google hiccup: only flag after the second empty fetch in a row.
		$message = '';
		$empty   = (array) get_option( self::OPTION_EMPTY, array() );
		if ( ! $events && $gone && empty( $empty[ $slug ] ) ) {
			$empty[ $slug ] = 1;
			/* translators: %d: number of events */
			$message = sprintf( __( 'The calendar is empty. If it still is at the next sync, %d events will be flagged as deleted.', 'calendar-plugin' ), count( $gone ) );
			$gone    = array();
		} else {
			unset( $empty[ $slug ] );
		}
		update_option( self::OPTION_EMPTY, $empty, false );

		foreach ( $gone as $id ) {
			if ( ! get_post_meta( $id, '_calp_removed', true ) ) {
				update_post_meta( $id, '_calp_removed', time() );
				++$count['removed'];
			}
		}

		foreach ( array_keys( $seen ) as $key ) {
			Geo::check( (int) ( self::$index[ $key ]['id'] ?? 0 ) );
			Pages::check( (int) ( self::$index[ $key ]['id'] ?? 0 ) );
		}

		return array(
			'ok'      => true,
			'time'    => time(),
			'name'    => $feed['name'],
			'count'   => count( $events ),
			'message' => $message,
		) + $count;
	}

	/**
	 * A new calendar event with the same title and start as an event already on the site (that is
	 * not in this feed) is the same event under a new ID – e.g. copied into another calendar by the
	 * legacy calendar copy. It takes over the existing post, so its page, picture and place stay.
	 */
	private static function adopt( array $e, array $here ): ?array {
		$twin = self::twin( (string) $e['title'], (int) $e['start'] );
		foreach ( self::$index as $key => $entry ) {
			if ( ( $entry['twin'] ?? '' ) === $twin && ! isset( $here[ $key ] ) ) {
				unset( self::$index[ $key ] );
				return $entry;
			}
		}
		return null;
	}

	private static function twin( string $title, int $start ): string {
		return mb_strtolower( trim( (string) preg_replace( '/\s+/', ' ', $title ) ) ) . '|' . $start;
	}

	/** Category slug from a "Category:" line – matched by slug or by name – else the feed's own category. */
	private static function category_of( string $wanted, string $feed ): string {
		if ( '' === $wanted ) {
			return $feed;
		}
		foreach ( Settings::event_categories() as $slug => $c ) {
			if ( $slug === $wanted || sanitize_title( $c['name'] ) === $wanted ) {
				return $slug;
			}
		}
		return $feed;
	}

	/**
	 * Index of all mirrored feed events; removes duplicates (same key twice, newest kept).
	 *
	 * @return array<string, array{id: int, category: string, twin: string}>
	 */
	private static function index(): array {
		$ids = get_posts(
			array(
				'post_type'      => Events::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => '_calp_source', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => 'feed', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		update_meta_cache( 'post', $ids );

		$index = array();
		foreach ( $ids as $id ) {
			$key = (string) get_post_meta( $id, '_calp_key', true );
			if ( isset( $index[ $key ] ) ) {
				wp_delete_post( $id, true );
				continue;
			}
			$index[ $key ] = array(
				'id'       => $id,
				'twin'     => self::twin( (string) get_post_field( 'post_title', $id ), (int) get_post_meta( $id, '_calp_start', true ) ),
				// The feed the event came from (before 0.3: the category, which was the same).
				'category' => (string) get_post_meta( $id, '_calp_feed', true ) ?: (string) get_post_meta( $id, '_calp_category', true ),
			);
		}
		return $index;
	}
}
