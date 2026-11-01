<?php
/**
 * Reads an iCal feed (sabre/vobject) and returns single events.
 * Recurring events (RRULE) are expanded within the time window.
 */

namespace CalendarPlugin\Events;

use Sabre\VObject\Reader;

final class Feed {

	/**
	 * @return array{name: string, events: array<int, array<string, mixed>>}
	 * @throws \Throwable On invalid iCal content.
	 */
	public static function read( string $ics, \DateTimeInterface $from, \DateTimeInterface $to ): array {
		$tz       = Format::tz();
		$calendar = Reader::read( $ics, Reader::OPTION_FORGIVING );
		$name     = trim( (string) ( $calendar->{'X-WR-CALNAME'} ?? '' ) );
		$calendar = $calendar->expand( $from, $to, $tz );

		$events = array();
		foreach ( $calendar->select( 'VEVENT' ) as $ev ) {
			if ( ! isset( $ev->DTSTART ) ) {
				continue;
			}
			$all_day = ! $ev->DTSTART->hasTime();
			$start   = $ev->DTSTART->getDateTime( $tz );

			if ( isset( $ev->DTEND ) ) {
				$end = $ev->DTEND->getDateTime( $tz );
			} elseif ( isset( $ev->DURATION ) ) {
				$end = $start->add( $ev->DURATION->getDateInterval() );
			} else {
				$end = $all_day ? $start->modify( '+1 day' ) : $start;
			}

			$desc   = Parser::description( (string) ( $ev->DESCRIPTION ?? '' ) );
			$status = $desc['fields']['status'] ?? '';
			if ( '' === $status && 'CANCELLED' === strtoupper( (string) ( $ev->STATUS ?? '' ) ) ) {
				$status = 'cancelled';
			}

			$uid = trim( (string) ( $ev->UID ?? '' ) );
			if ( '' === $uid ) {
				$uid = md5( (string) $ev->SUMMARY . $start->format( 'c' ) );
			}
			$key = $uid . '|' . ( isset( $ev->{'RECURRENCE-ID'} ) ? $ev->{'RECURRENCE-ID'}->getDateTime( $tz )->format( 'Ymd\THis' ) : '' );
			if ( isset( $events[ $key ] ) ) {
				$key .= '|' . $start->getTimestamp();
			}

			$geo = null;
			if ( isset( $ev->GEO ) ) {
				$parts = $ev->GEO->getParts();
				if ( 2 === count( $parts ) && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
					$geo = array(
						'lat' => (float) $parts[0],
						'lng' => (float) $parts[1],
					);
				}
			}

			$events[ $key ] = array(
				'key'      => $key,
				'title'    => trim( (string) ( $ev->SUMMARY ?? '' ) ),
				'location' => trim( (string) ( $ev->LOCATION ?? '' ) ),
				'start'    => $start->getTimestamp(),
				'end'      => $end->getTimestamp(),
				'all_day'  => $all_day,
				'text'     => $desc['text'],
				'fields'   => $desc['fields'],
				'warnings' => $desc['warnings'],
				'status'   => $status,
				'geo'      => $geo,
			);
		}

		usort( $events, static fn( $a, $b ) => $a['start'] <=> $b['start'] );
		return array(
			'name'   => $name,
			'events' => $events,
		);
	}
}
