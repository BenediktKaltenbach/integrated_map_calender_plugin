<?php
/**
 * Two-way copy between a shared legacy Google Calendar and the category calendars.
 *
 * The legacy calendar is the one other websites have embedded for years. The category calendars
 * (e.g. Eurocups, International Championships, other international events) are the new sources:
 *
 *   legacy   → categories  every event entered in the legacy calendar is copied into one category
 *                          calendar, chosen by keyword rules ("eurocup = eurocup", "* = other").
 *   category → legacy      every event entered in a category calendar is copied into the legacy
 *                          calendar, so the old embeds keep showing everything.
 *
 * Copies carry the ID of their original in a private extended property, which Google keeps when
 * an event is moved to another calendar. So moving a copy to another category calendar in Google
 * is the way to change its category, and nothing is ever copied back and forth twice.
 * Changes at the original are passed on to its copy; deleting the original deletes the copy.
 * Edit an event where it was entered – changes made to a copy are overwritten by the next change
 * at the original.
 *
 * Without write access to the legacy calendar only the first direction runs.
 */

namespace CalendarPlugin\Events;

use CalendarPlugin\Settings;

final class Bridge {

	public const OPTION_STATUS = 'calp_bridge_status';
	public const LEGACY        = '_legacy';

	private const SRC        = 'calp_src';
	private const HASH       = 'calp_hash';
	private const PAST_DAYS  = 370; // The site keeps a year of past events (Sync), so copy those too.
	private const YEARS      = 3;
	private const MAX_WRITES = 100;
	private const FIELDS     = array( 'summary', 'description', 'location', 'start', 'end', 'recurrence' );

	/**
	 * Rules from the settings text: "keyword = category slug" per line, "* = slug" for everything else.
	 *
	 * @return array{rules: array<int, array{0: string, 1: string}>, default: string}
	 */
	public static function rules( string $text ): array {
		$rules   = array();
		$default = '';
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
				continue;
			}
			if ( '*' === $parts[0] ) {
				$default = $parts[1];
			} else {
				$rules[] = array( mb_strtolower( $parts[0] ), $parts[1] );
			}
		}
		return array(
			'rules'   => $rules,
			'default' => $default,
		);
	}

	/** Category for a legacy event by its title; '' if no rule fits and there is no default. */
	public static function target( string $title, array $rules ): string {
		$title = mb_strtolower( $title );
		foreach ( $rules['rules'] as [ $word, $slug ] ) {
			if ( str_contains( $title, $word ) ) {
				return $slug;
			}
		}
		return $rules['default'];
	}

	/** The fields that are copied, and their fingerprint. */
	public static function content( array $event ): array {
		$out = array();
		foreach ( self::FIELDS as $f ) {
			if ( isset( $event[ $f ] ) && '' !== $event[ $f ] && array() !== $event[ $f ] ) {
				$out[ $f ] = $event[ $f ];
			}
		}
		return $out;
	}

	public static function hash( array $event ): string {
		return md5( (string) json_encode( self::content( $event ) ) );
	}

	private static function prop( array $event, string $name ): string {
		return (string) ( $event['extendedProperties']['private'][ $name ] ?? '' );
	}

	/** Same title on the same day – to find events entered by hand in both places. */
	private static function twin( array $event ): string {
		$day = substr( (string) ( $event['start']['dateTime'] ?? $event['start']['date'] ?? '' ), 0, 10 );
		return mb_strtolower( trim( (string) preg_replace( '/\s+/', ' ', (string) ( $event['summary'] ?? '' ) ) ) ) . '|' . $day;
	}

	private static function start( array $event ): int {
		$s = $event['start']['dateTime'] ?? $event['start']['date'] ?? '';
		return '' !== $s ? (int) strtotime( (string) $s ) : 0;
	}

	/**
	 * What has to be done. Pure: no WordPress, no Google – the lists come in, the actions go out.
	 *
	 * @param array<int, array>                $legacy          events of the legacy calendar
	 * @param array<string, array<int, array>> $categories      slug → events of that category calendar
	 * @param array                            $rules           from rules()
	 * @param bool                             $legacy_writable copy category events into the legacy calendar
	 * @param array{0: int, 1: int}            $safe            period in which a missing original counts as deleted
	 * @return array<int, array{do: string, calendar: string, id?: string, event?: array, title: string, note?: string}>
	 */
	public static function plan( array $legacy, array $categories, array $rules, bool $legacy_writable, array $safe ): array {
		$sides = array( self::LEGACY => $legacy ) + $categories;

		// Originals, copies (by the ID of their original) and deleted events, per side.
		$originals = array();
		$copies    = array();
		$deleted   = array();
		$twins     = array();
		foreach ( $sides as $cal => $items ) {
			foreach ( $items as $e ) {
				$id = (string) ( $e['id'] ?? '' );
				if ( '' === $id || ! empty( $e['recurringEventId'] ) ) {
					continue; // Changed single dates of a series travel with the series.
				}
				$src  = self::prop( $e, self::SRC );
				$side = self::LEGACY === $cal ? 'legacy' : 'categories';
				if ( 'cancelled' === ( $e['status'] ?? '' ) ) {
					if ( '' === $src ) {
						$deleted[ $side ][ $id ] = true;
					}
					continue;
				}
				$twins[ $side ][ self::twin( $e ) ] = true;
				if ( '' === $src ) {
					$originals[ $side ][ $id ] = array( $cal, $e );
				} else {
					$copies[ $side ][ $src ] = array( $cal, $e );
				}
			}
		}

		$actions = array();
		$copy    = static function ( array $e, string $to_side, string $to_cal ) use ( &$actions, &$copies, $twins ) {
			$id   = (string) $e['id'];
			$hash = self::hash( $e );
			$have = $copies[ $to_side ][ $id ] ?? null;
			$body = self::content( $e ) + array( 'extendedProperties' => array( 'private' => array( self::SRC => $id, self::HASH => $hash ) ) );
			if ( null === $have && isset( $twins[ $to_side ][ self::twin( $e ) ] ) ) {
				$actions[] = array( 'do' => 'skip', 'calendar' => $to_cal, 'title' => (string) ( $e['summary'] ?? '' ), 'note' => 'same title on the same day is already there' );
			} elseif ( null === $have ) {
				$actions[] = array( 'do' => 'insert', 'calendar' => $to_cal, 'event' => $body, 'title' => (string) ( $e['summary'] ?? '' ) );
			} elseif ( self::prop( $have[1], self::HASH ) !== $hash ) {
				// The copy stays in the calendar it is in now (it may have been moved to another category).
				$actions[] = array( 'do' => 'patch', 'calendar' => $have[0], 'id' => (string) $have[1]['id'], 'event' => $body, 'title' => (string) ( $e['summary'] ?? '' ) );
			}
		};

		// Legacy → categories.
		foreach ( $originals['legacy'] ?? array() as [ , $e ] ) {
			$slug = self::target( (string) ( $e['summary'] ?? '' ), $rules );
			if ( ! isset( $categories[ $slug ] ) && ! isset( $copies['categories'][ (string) $e['id'] ] ) ) {
				$actions[] = array( 'do' => 'skip', 'calendar' => '', 'title' => (string) ( $e['summary'] ?? '' ), 'note' => '' === $slug ? 'no rule fits' : 'no calendar for category "' . $slug . '"' );
				continue;
			}
			$copy( $e, 'categories', $slug );
		}

		// Categories → legacy.
		if ( $legacy_writable ) {
			foreach ( $originals['categories'] ?? array() as [ , $e ] ) {
				$copy( $e, 'legacy', self::LEGACY );
			}
		}

		// Copies whose original is gone. An original that is simply not in the list counts as gone
		// only inside the safe period, so date changes near the edges of the period delete nothing.
		$gone = static function ( string $src, array $c, string $from_side ) use ( $originals, $deleted, $safe ): bool {
			if ( isset( $originals[ $from_side ][ $src ] ) ) {
				return false;
			}
			if ( isset( $deleted[ $from_side ][ $src ] ) ) {
				return true;
			}
			$t = self::start( $c );
			return $t >= $safe[0] && $t <= $safe[1];
		};
		foreach ( $copies['categories'] ?? array() as $src => [ $cal, $c ] ) {
			if ( $gone( (string) $src, $c, 'legacy' ) ) {
				$actions[] = array( 'do' => 'delete', 'calendar' => $cal, 'id' => (string) $c['id'], 'title' => (string) ( $c['summary'] ?? '' ) );
			}
		}
		if ( $legacy_writable ) {
			foreach ( $copies['legacy'] ?? array() as $src => [ $cal, $c ] ) {
				if ( $gone( (string) $src, $c, 'categories' ) ) {
					$actions[] = array( 'do' => 'delete', 'calendar' => $cal, 'id' => (string) $c['id'], 'title' => (string) ( $c['summary'] ?? '' ) );
				}
			}
		}
		return $actions;
	}

	/** Legacy calendar ID and category slug → calendar ID (only categories with a Google Calendar). */
	private static function calendars(): array {
		$s      = Settings::get();
		$legacy = Google::calendar_id( (string) ( $s['legacy_calendar'] ?? '' ) );
		$cats   = array();
		foreach ( Settings::event_categories() as $slug => $c ) {
			$id = Google::calendar_id( (string) $c['ical'] );
			if ( '' !== $id && $id !== $legacy ) {
				$cats[ $slug ] = $id;
			}
		}
		return array( $legacy, $cats );
	}

	public static function enabled(): bool {
		return ! empty( Settings::get()['bridge'] );
	}

	/**
	 * Runs the copy. $dry = only report what would be done.
	 *
	 * @return array{ok: bool, time: int, dry: bool, message: string, legacy_writable?: bool, actions?: array, errors?: array}
	 */
	public static function run( bool $dry = false ): array {
		$fail = static fn( string $m ) => array( 'ok' => false, 'time' => time(), 'dry' => $dry, 'message' => $m );
		if ( ! Google::configured() ) {
			return $fail( __( 'No Google service account set up (see below).', 'calendar-plugin' ) );
		}
		[ $legacy, $cats ] = self::calendars();
		if ( '' === $legacy ) {
			return $fail( __( 'No legacy calendar entered.', 'calendar-plugin' ) );
		}
		if ( ! $cats ) {
			return $fail( __( 'None of the event categories has its own Google Calendar yet.', 'calendar-plugin' ) );
		}

		$from = time() - self::PAST_DAYS * DAY_IN_SECONDS;
		$to   = time() + self::YEARS * YEAR_IN_SECONDS;
		$safe = array( $from + 14 * DAY_IN_SECONDS, $to - 30 * DAY_IN_SECONDS );

		// Every list must be complete, otherwise missing events would look deleted.
		$l = Google::events( $legacy, $from, $to );
		if ( is_wp_error( $l ) ) {
			return $fail( __( 'Legacy calendar:', 'calendar-plugin' ) . ' ' . $l->get_error_message() );
		}
		$lists = array();
		foreach ( $cats as $slug => $id ) {
			$r = Google::events( $id, $from, $to );
			if ( is_wp_error( $r ) ) {
				return $fail( $slug . ': ' . $r->get_error_message() );
			}
			if ( ! Google::can_write( $r['role'] ) && ! $dry ) {
				return $fail( sprintf( /* translators: 1: category, 2: service account */ __( 'No write access to the calendar of "%1$s" – share it with %2$s ("Make changes to events").', 'calendar-plugin' ), $slug, Google::account() ) );
			}
			$lists[ $slug ] = $r['items'];
		}
		$writable = Google::can_write( $l['role'] );
		$actions  = self::plan( $l['items'], $lists, self::rules( (string) ( Settings::get()['bridge_rules'] ?? '' ) ), $writable, $safe );

		$errors = array();
		$writes = 0;
		foreach ( $actions as $i => $a ) {
			if ( $dry || 'skip' === $a['do'] ) {
				continue;
			}
			if ( ++$writes > self::MAX_WRITES ) {
				$actions[ $i ]['note'] = __( 'postponed to the next run', 'calendar-plugin' );
				continue;
			}
			$cal = self::LEGACY === $a['calendar'] ? $legacy : ( $cats[ $a['calendar'] ] ?? '' );
			$r   = match ( $a['do'] ) {
				'insert' => Google::insert( $cal, $a['event'] ),
				'patch'  => Google::patch( $cal, $a['id'], $a['event'] ),
				'delete' => Google::delete( $cal, $a['id'] ),
			};
			if ( is_wp_error( $r ) ) {
				$errors[]              = $a['title'] . ': ' . $r->get_error_message();
				$actions[ $i ]['note'] = $r->get_error_message();
			}
		}

		$count = static fn( string $do ) => count( array_filter( $actions, static fn( $a ) => $a['do'] === $do ) );
		return array(
			'ok'              => ! $errors,
			'time'            => time(),
			'dry'             => $dry,
			'legacy_writable' => $writable,
			'message'         => sprintf(
				/* translators: 1: new, 2: updated, 3: deleted, 4: skipped */
				__( '%1$d new, %2$d updated, %3$d deleted, %4$d skipped', 'calendar-plugin' ),
				$count( 'insert' ),
				$count( 'patch' ),
				$count( 'delete' ),
				$count( 'skip' )
			) . ( $writable ? '' : ' · ' . __( 'legacy calendar read-only: only legacy → categories', 'calendar-plugin' ) ),
			'actions'         => array_map( static fn( $a ) => array_diff_key( $a, array( 'event' => 1 ) ), $actions ),
			'errors'          => $errors,
		);
	}

	/** Part of every sync (cron), before the category calendars are read. */
	public static function scheduled(): void {
		if ( ! self::enabled() ) {
			return;
		}
		try {
			update_option( self::OPTION_STATUS, self::run(), false );
		} catch ( \Throwable $e ) {
			update_option( self::OPTION_STATUS, array( 'ok' => false, 'time' => time(), 'dry' => false, 'message' => $e->getMessage() ), false );
		}
	}
}
