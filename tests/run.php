<?php
/**
 * Minimal tests without WordPress: php tests/run.php
 */

namespace {
	function __( $text, $domain = null ) {
		return $text;
	}
	require __DIR__ . '/../vendor/autoload.php';
	require __DIR__ . '/../includes/Events/Format.php';
	require __DIR__ . '/../includes/Events/Parser.php';
	require __DIR__ . '/../includes/Events/Feed.php';
	require __DIR__ . '/../includes/Events/Bridge.php';
}

namespace CalendarPlugin\Events {
	function wp_timezone() {
		return new \DateTimeZone( 'Europe/Berlin' );
	}
}

namespace {
	use CalendarPlugin\Events\Bridge;
	use CalendarPlugin\Events\Feed;
	use CalendarPlugin\Events\Parser;

	$fails = 0;
	$check = static function ( string $name, $got, $want ) use ( &$fails ) {
		if ( $got === $want ) {
			echo "ok   $name\n";
			return;
		}
		++$fails;
		echo "FAIL $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n";
	};

	// Parser: English and German keys, separator, status.
	$p = Parser::description( "Nice tour.\n---\nRegistration: https://example.org/reg\nLand: cz\nStatus: abgesagt\nFoo: bar" );
	$check( 'free text', $p['text'], 'Nice tour.' );
	$check( 'registration', $p['fields']['registration'] ?? '', 'https://example.org/reg' );
	$check( 'country upper-cased', $p['fields']['country'] ?? '', 'CZ' );
	$check( 'status german', $p['fields']['status'] ?? '', 'cancelled' );
	$check( 'unknown key warns', count( $p['warnings'] ), 1 );

	$p = Parser::description( "Race\n---\nTyp: Weltmeisterschaft 2027" );
	$check( 'category slug', $p['fields']['category'] ?? '', 'weltmeisterschaft-2027' );

	$p = Parser::description( "Text\nInfo: www.footbikesport.eu" );
	$check( 'without separator', $p['fields']['info'] ?? '', 'https://www.footbikesport.eu' );
	$check( 'html links', Parser::html_to_text( '<a href="https://a.b/c">https://a.b/c</a><br>x' ), "https://a.b/c\nx" );

	// Feed: all-day event, recurring event expanded.
	$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nX-WR-CALNAME:IKSA\r\nBEGIN:VEVENT\r\nUID:a1\r\nDTSTART;VALUE=DATE:20270807\r\nDTEND;VALUE=DATE:20270810\r\nSUMMARY:World Championships\r\nLOCATION:Lipnik\r\nDESCRIPTION:Big race\\n---\\nCountry: CZ\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:r1\r\nDTSTART:20270105T180000Z\r\nDTEND:20270105T190000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Training\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	$f   = Feed::read( $ics, new \DateTimeImmutable( '2027-01-01' ), new \DateTimeImmutable( '2028-01-01' ) );
	$check( 'calendar name', $f['name'], 'IKSA' );
	$check( 'event count', count( $f['events'] ), 4 );
	$wm = array_values( array_filter( $f['events'], static fn( $e ) => 'World Championships' === $e['title'] ) )[0];
	$check( 'all day', $wm['all_day'], true );
	$check( 'feed country', $wm['fields']['country'] ?? '', 'CZ' );


	// Bridge: legacy calendar <-> category calendars.
	$ev   = static fn( string $id, string $title, string $day, array $more = array() ) => array( 'id' => $id, 'summary' => $title, 'start' => array( 'date' => $day ), 'end' => array( 'date' => $day ) ) + $more;
	$copy = static fn( array $e, string $id, string $src, ?string $hash = null ) => array_merge( $e, array( 'id' => $id, 'extendedProperties' => array( 'private' => array( 'calp_src' => $src, 'calp_hash' => $hash ?? Bridge::hash( $e ) ) ) ) );
	$rules = Bridge::rules( "eurocup = eurocup\nworld = nowhere\n* = other" );
	$safe  = array( strtotime( '2026-01-01' ), strtotime( '2029-01-01' ) );
	$do    = static fn( array $actions ) => array_map( static fn( $a ) => $a['do'] . ' ' . $a['calendar'] . ' ' . $a['title'], $actions );

	$l1 = $ev( 'L1', 'Eurocup Lyon', '2027-05-08' );
	$l2 = $ev( 'L2', 'Czech cup Pilsen', '2027-06-01' );
	$check( 'bridge: legacy events by rule', $do( Bridge::plan( array( $l1, $l2 ), array( 'eurocup' => array(), 'other' => array() ), $rules, false, $safe ) ), array( 'insert eurocup Eurocup Lyon', 'insert other Czech cup Pilsen' ) );
	$check( 'bridge: rule without calendar is skipped', Bridge::plan( array( $ev( 'L3', 'World Cup', '2027-07-01' ) ), array( 'other' => array() ), $rules, false, $safe )[0]['do'], 'skip' );

	// Copy already there (and moved by hand to "championship"): nothing, or an update where it is now.
	$c1 = $copy( $l1, 'C1', 'L1' );
	$check( 'bridge: unchanged copy', Bridge::plan( array( $l1 ), array( 'eurocup' => array(), 'championship' => array( $c1 ) ), $rules, false, $safe ), array() );
	$l1b = array_merge( $l1, array( 'location' => 'Lyon, France' ) );
	$check( 'bridge: changed original updates the moved copy', $do( Bridge::plan( array( $l1b ), array( 'eurocup' => array(), 'championship' => array( $c1 ) ), $rules, false, $safe ) ), array( 'patch championship Eurocup Lyon' ) );

	// Categories -> legacy only with write access; no loop for copies.
	$k1 = $ev( 'K1', 'Eurocup Liberec', '2027-08-28' );
	$check( 'bridge: category -> legacy', $do( Bridge::plan( array(), array( 'eurocup' => array( $k1 ) ), $rules, true, $safe ) ), array( 'insert _legacy Eurocup Liberec' ) );
	$check( 'bridge: legacy read-only', Bridge::plan( array(), array( 'eurocup' => array( $k1 ) ), $rules, false, $safe ), array() );
	$check( 'bridge: no ping-pong', Bridge::plan( array( $copy( $k1, 'X1', 'K1' ) ), array( 'eurocup' => array( $k1 ) ), $rules, true, $safe ), array() );

	// Deleting.
	$check( 'bridge: deleted original deletes copy', $do( Bridge::plan( array( array( 'id' => 'L1', 'status' => 'cancelled' ) ), array( 'eurocup' => array( $copy( $l1, 'C1', 'L1' ) ) ), $rules, false, $safe ) ), array( 'delete eurocup Eurocup Lyon' ) );
	$check( 'bridge: missing original inside safe period', $do( Bridge::plan( array( $copy( $k1, 'X1', 'K1' ) ), array( 'eurocup' => array() ), $rules, true, $safe ) ), array( 'delete _legacy Eurocup Liberec' ) );
	$old = $ev( 'K9', 'Old race', '2025-06-01' );
	$check( 'bridge: missing original outside safe period kept', Bridge::plan( array( $copy( $old, 'X9', 'K9' ) ), array( 'eurocup' => array() ), $rules, true, $safe ), array() );
	$check( 'bridge: legacy copies not deleted without write access', Bridge::plan( array( $copy( $k1, 'X1', 'K1' ) ), array( 'eurocup' => array() ), $rules, false, $safe ), array() );

	// Entered by hand in both places: no duplicate.
	$check( 'bridge: same title same day is skipped', Bridge::plan( array( $l1 ), array( 'eurocup' => array( $ev( 'K2', ' eurocup  LYON', '2027-05-08' ) ) ), $rules, false, $safe )[0]['do'], 'skip' );

	echo $fails ? "\n$fails failed\n" : "\nall passed\n";
	exit( $fails ? 1 : 0 );
}
