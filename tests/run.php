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
}

namespace CalendarPlugin\Events {
	function wp_timezone() {
		return new \DateTimeZone( 'Europe/Berlin' );
	}
}

namespace {
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

	echo $fails ? "\n$fails failed\n" : "\nall passed\n";
	exit( $fails ? 1 : 0 );
}
