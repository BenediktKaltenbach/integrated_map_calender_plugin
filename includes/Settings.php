<?php
/**
 * Settings page (Settings › Calendar Plugin) with one tab per area.
 * Everything lives in one option array; each tab only saves its own keys.
 */

namespace CalendarPlugin;

use CalendarPlugin\Events\Bridge;
use CalendarPlugin\Events\Google;
use CalendarPlugin\Events\Sync;

final class Settings {

	public const OPTION          = 'calp_settings';
	public const OPTION_CRON_KEY = 'calp_cron_key';
	private const PAGE           = 'calendar-plugin';

	/** Review areas: key => label. */
	public static function review_areas(): array {
		return array(
			'events'   => __( 'Event suggestions', 'calendar-plugin' ),
			'places'   => __( 'Place suggestions (map)', 'calendar-plugin' ),
			'articles' => __( 'Article submissions', 'calendar-plugin' ),
		);
	}

	public static function defaults(): array {
		return array(
			'event_categories' => array(
				array(
					'slug'   => 'eurocup',
					'name'   => 'Eurocup',
					'color'  => '#31525B',
					'ical'   => '',
					'on_map' => 1,
				),
				array(
					'slug'   => 'championship',
					'name'   => 'Championship',
					'color'  => '#E08A00',
					'ical'   => '',
					'on_map' => 1,
				),
				array(
					'slug'   => 'other',
					'name'   => 'Other events',
					'color'  => '#4C8C99',
					'ical'   => '',
					'on_map' => 1,
				),
			),
			'legacy_calendar'  => '',
			'bridge'           => 0,
			'bridge_rules'     => "eurocup = eurocup
* = other",
			'event_form'       => 1,
			'event_pages'      => 1,
			'event_page_parent' => 0,
			'map_lat'          => 50.0,
			'map_lng'          => 12.0,
			'map_zoom'         => 4,
			'map_height'       => 550,
			'map_suggestions'  => 1,
			'map_events'       => 1,
			'reviewers'        => array(
				'events'   => '',
				'places'   => '',
				'articles' => '',
			),
			'mail_from_name'   => '',
			'contact_recipients' => '',
			'article_categories' => array(
				'news'    => 'News',
				'history' => 'History',
				'blog'    => 'Blog',
			),
			'seeking_rep_text' => 'We are looking for a representative! Can you be this person? Please contact us.',
			'contact_email'    => '',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		$s['reviewers'] = array_merge( self::defaults()['reviewers'], (array) $s['reviewers'] );
		return $s;
	}

	/** @return array<string, array{slug: string, name: string, color: string, ical: string, on_map: int}> keyed by slug */
	public static function event_categories(): array {
		$out = array();
		foreach ( (array) self::get()['event_categories'] as $c ) {
			if ( ! empty( $c['slug'] ) ) {
				$out[ $c['slug'] ] = $c;
			}
		}
		return $out;
	}

	/** Reviewer addresses for an area; falls back to the site admin address. */
	public static function reviewers( string $area ): array {
		return self::mail_list( (string) ( self::get()['reviewers'][ $area ] ?? '' ) );
	}

	/** Recipients of the contact form; falls back to the site admin address. */
	public static function contact_recipients(): array {
		return self::mail_list( (string) self::get()['contact_recipients'] );
	}

	private static function mail_list( string $raw ): array {
		$mails = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ), 'is_email' ) );
		return $mails ? array_values( array_unique( $mails ) ) : array( (string) get_option( 'admin_email' ) );
	}

	public static function cron_url(): string {
		return add_query_arg(
			array(
				'action' => 'calp_cron',
				'key'    => (string) get_option( self::OPTION_CRON_KEY ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	// ── Admin ────────────────────────────────────────────────────────

	public static function admin(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_post_calp_sync_now', array( self::class, 'sync_now' ) );
		add_action( 'admin_post_calp_bridge', array( self::class, 'bridge_now' ) );
		add_action( 'admin_post_calp_import_iksa', array( self::class, 'import_iksa' ) );
	}

	public static function menu(): void {
		add_options_page( 'Calendar Plugin', 'Calendar Plugin', 'manage_options', self::PAGE, array( self::class, 'page' ) );
	}

	public static function register(): void {
		register_setting(
			'calp',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	private static function tabs(): array {
		return array(
			'events'   => __( 'Events', 'calendar-plugin' ),
			'bridge'   => __( 'Legacy calendar', 'calendar-plugin' ),
			'map'      => __( 'Map', 'calendar-plugin' ),
			'review'   => __( 'Review', 'calendar-plugin' ),
			'content'  => __( 'Countries & articles', 'calendar-plugin' ),
			'help'     => __( 'Shortcodes & tools', 'calendar-plugin' ),
		);
	}

	/** Merges the submitted tab into the stored settings. */
	public static function sanitize( $input ): array {
		$s   = self::get();
		$in  = is_array( $input ) ? $input : array();
		$tab = sanitize_key( $in['_tab'] ?? '' );

		if ( 'events' === $tab ) {
			$cats = array();
			foreach ( (array) ( $in['event_categories'] ?? array() ) as $c ) {
				$name = sanitize_text_field( $c['name'] ?? '' );
				$slug = sanitize_key( ( $c['slug'] ?? '' ) ?: $name );
				if ( '' === $name || '' === $slug || isset( $cats[ $slug ] ) ) {
					continue;
				}
				$ical          = trim( (string) ( $c['ical'] ?? '' ) );
				$cats[ $slug ] = array(
					'slug'   => $slug,
					'name'   => $name,
					'color'  => sanitize_hex_color( $c['color'] ?? '' ) ?: '#31525B',
					// The secret iCal address is a URL; keep it exactly (esc_url_raw would strip nothing relevant).
					'ical'   => preg_match( '#^(https?|webcal)://#i', $ical ) ? preg_replace( '#^webcal://#i', 'https://', esc_url_raw( $ical, array( 'http', 'https', 'webcal' ) ) ) : '',
					'on_map' => empty( $c['on_map'] ) ? 0 : 1,
				);
			}
			$s['event_categories'] = array_values( $cats );
			$s['event_form']        = empty( $in['event_form'] ) ? 0 : 1;
			$s['event_pages']       = empty( $in['event_pages'] ) ? 0 : 1;
			$s['event_page_parent'] = absint( $in['event_page_parent'] ?? 0 );
		}

		if ( 'bridge' === $tab ) {
			$legacy               = trim( (string) ( $in['legacy_calendar'] ?? '' ) );
			$s['legacy_calendar'] = '' !== Google::calendar_id( $legacy ) ? preg_replace( '#^webcal://#i', 'https://', $legacy ) : '';
			$s['bridge']          = empty( $in['bridge'] ) ? 0 : 1;
			$s['bridge_rules']    = sanitize_textarea_field( $in['bridge_rules'] ?? '' );
		}

		if ( 'map' === $tab ) {
			$s['map_lat']         = max( -90, min( 90, (float) ( $in['map_lat'] ?? 50 ) ) );
			$s['map_lng']         = max( -180, min( 180, (float) ( $in['map_lng'] ?? 12 ) ) );
			$s['map_zoom']        = max( 1, min( 18, (int) ( $in['map_zoom'] ?? 4 ) ) );
			$s['map_height']      = max( 250, min( 1200, (int) ( $in['map_height'] ?? 550 ) ) );
			$s['map_suggestions'] = empty( $in['map_suggestions'] ) ? 0 : 1;
			$s['map_events']      = empty( $in['map_events'] ) ? 0 : 1;
		}

		if ( 'review' === $tab ) {
			foreach ( array_keys( self::review_areas() ) as $area ) {
				$mails                     = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) ( $in['reviewers'][ $area ] ?? '' ) ) ), 'is_email' );
				$s['reviewers'][ $area ] = implode( "\n", array_unique( $mails ) );
			}
			$mails                   = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) ( $in['contact_recipients'] ?? '' ) ) ), 'is_email' );
			$s['contact_recipients'] = implode( "\n", array_unique( $mails ) );
			$s['mail_from_name']     = sanitize_text_field( $in['mail_from_name'] ?? '' );
		}

		if ( 'content' === $tab ) {
			$cats = array();
			foreach ( preg_split( '/\R/', (string) ( $in['article_categories'] ?? '' ) ) as $line ) {
				$parts = array_map( 'trim', explode( '=', $line, 2 ) );
				$name  = sanitize_text_field( $parts[1] ?? $parts[0] );
				$slug  = sanitize_key( $parts[0] );
				if ( '' !== $slug && '' !== $name ) {
					$cats[ $slug ] = $name;
				}
			}
			if ( $cats ) {
				$s['article_categories'] = $cats;
			}
			$s['seeking_rep_text'] = sanitize_textarea_field( $in['seeking_rep_text'] ?? '' );
			$s['contact_email']    = sanitize_email( $in['contact_email'] ?? '' );
		}

		return $s;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = sanitize_key( $_GET['tab'] ?? 'events' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'events';
		}
		$s = self::get();

		echo '<div class="wrap"><h1>Calendar Plugin</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=' . $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( isset( $_GET['calp_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['calp_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( 'help' === $tab ) {
			self::page_help();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'calp' );
		printf( '<input type="hidden" name="%s[_tab]" value="%s">', esc_attr( self::OPTION ), esc_attr( $tab ) );
		$n = static fn( string $key ) => esc_attr( self::OPTION . '[' . $key . ']' );

		if ( 'events' === $tab ) {
			echo '<h2>' . esc_html__( 'Event categories', 'calendar-plugin' ) . '</h2>';
			echo '<p>' . esc_html__( 'Each category can have its own Google Calendar (secret address in iCal format, from the calendar settings › "Integrate calendar"). Categories without an address only hold events entered here on the website or accepted suggestions. Leave the name empty to remove a row.', 'calendar-plugin' ) . '</p>';
			echo '<table class="widefat striped calp-cats"><thead><tr><th>' . esc_html__( 'Name', 'calendar-plugin' ) . '</th><th>' . esc_html__( 'Slug', 'calendar-plugin' ) . '</th><th>' . esc_html__( 'Colour', 'calendar-plugin' ) . '</th><th>' . esc_html__( 'Google Calendar (iCal address)', 'calendar-plugin' ) . '</th><th>' . esc_html__( 'On map', 'calendar-plugin' ) . '</th></tr></thead><tbody>';
			$rows   = array_values( (array) $s['event_categories'] );
			$rows[] = array( 'slug' => '', 'name' => '', 'color' => '#31525B', 'ical' => '', 'on_map' => 1 );
			$status = (array) get_option( Sync::OPTION_STATUS, array() );
			foreach ( $rows as $i => $c ) {
				$base = self::OPTION . '[event_categories][' . $i . ']';
				$info = '';
				if ( ! empty( $c['slug'] ) && isset( $status[ $c['slug'] ] ) ) {
					$st   = $status[ $c['slug'] ];
					$info = '<br><small>' . ( ! empty( $st['ok'] )
						? esc_html( sprintf( /* translators: 1: calendar name, 2: number of events */ __( '✅ "%1$s", %2$d events', 'calendar-plugin' ), $st['name'] ?? '', $st['count'] ?? 0 ) )
						: '⚠ ' . esc_html( $st['message'] ?? '' ) ) . '</small>';
				}
				printf(
					'<tr><td><input type="text" name="%1$s[name]" value="%2$s"></td><td><input type="text" name="%1$s[slug]" value="%3$s" size="12"></td><td><input type="color" name="%1$s[color]" value="%4$s"></td><td><input type="url" class="large-text" name="%1$s[ical]" value="%5$s" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics">%7$s</td><td><input type="checkbox" name="%1$s[on_map]" value="1"%6$s></td></tr>',
					esc_attr( $base ),
					esc_attr( $c['name'] ),
					esc_attr( $c['slug'] ),
					esc_attr( $c['color'] ),
					esc_attr( $c['ical'] ),
					checked( ! empty( $c['on_map'] ), true, false ),
					$info // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				);
			}
			echo '</tbody></table>';
			printf( '<p><label><input type="checkbox" name="%s" value="1"%s> %s</label></p>', $n( 'event_form' ), checked( ! empty( $s['event_form'] ), true, false ), esc_html__( 'Allow visitors to suggest events (form [calp_event_form])', 'calendar-plugin' ) );
			printf( '<p><label><input type="checkbox" name="%s" value="1"%s> %s</label></p>', $n( 'event_pages' ), checked( ! empty( $s['event_pages'] ), true, false ), esc_html__( 'Create a page for every upcoming event (info box with date, place, picture and buttons, then the description – edit it like any page). "Info" leads to the external info link if there is one, otherwise to this page.', 'calendar-plugin' ) );
			echo '<p><label>' . esc_html__( 'Event pages below:', 'calendar-plugin' ) . ' ';
			wp_dropdown_pages(
				array(
					'name'              => self::OPTION . '[event_page_parent]', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by wp_dropdown_pages.
					'selected'          => (int) $s['event_page_parent'],
					'show_option_none'  => __( '(top level)', 'calendar-plugin' ),
					'option_none_value' => 0,
				)
			);
			echo '</label></p><p class="description">' . esc_html__( 'E.g. the calendar page, so event addresses read /calendar/event-name/ and new pages are not added to menus automatically.', 'calendar-plugin' ) . '</p>';
		}

		if ( 'bridge' === $tab ) {
			echo '<p>' . esc_html__( 'Keeps a shared legacy Google Calendar (the one other websites have embedded) and the category calendars in step: events entered in the legacy calendar are copied into one category calendar by the rules below, and events entered in a category calendar are copied into the legacy calendar. To change the category of an event, move it to another calendar in Google ("Calendar" field of the event). Edit events where they were entered; deleting there deletes the copy.', 'calendar-plugin' ) . '</p>';
			echo '<table class="form-table">';
			printf( '<tr><th>%s</th><td><input type="text" class="large-text" name="%s" value="%s" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"><p class="description">%s</p></td></tr>', esc_html__( 'Legacy calendar', 'calendar-plugin' ), $n( 'legacy_calendar' ), esc_attr( (string) $s['legacy_calendar'] ), esc_html__( 'iCal address or calendar ID. Do not also enter it as the calendar of a category – the categories get their own calendars.', 'calendar-plugin' ) );
			printf( '<tr><th>%s</th><td><textarea rows="4" cols="40" name="%s">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Rules', 'calendar-plugin' ), $n( 'bridge_rules' ), esc_textarea( (string) $s['bridge_rules'] ), esc_html__( 'Category for events from the legacy calendar: "word in the title = category slug", one per line, the first fitting rule wins. "* = slug" for everything else.', 'calendar-plugin' ) );
			printf( '<tr><th>%s</th><td><label><input type="checkbox" name="%s" value="1"%s> %s</label></td></tr>', esc_html__( 'Automatic', 'calendar-plugin' ), $n( 'bridge' ), checked( ! empty( $s['bridge'] ), true, false ), esc_html__( 'Copy with every sync (hourly). Check the preview first.', 'calendar-plugin' ) );
			echo '</table>';

			echo '<h2>' . esc_html__( 'Google service account', 'calendar-plugin' ) . '</h2>';
			$key = Google::read();
			if ( Google::configured() ) {
				printf( '<p>✅ %s <code>%s</code></p>', esc_html__( 'Set up. Share the legacy calendar and every category calendar with', 'calendar-plugin' ), esc_html( Google::account() ) );
				echo '<p class="description">' . esc_html__( 'Google Calendar › settings of the calendar › "Share with specific people" › add the address › "Make changes to events". Without write access to the legacy calendar only legacy → categories runs.', 'calendar-plugin' ) . '</p>';
			} elseif ( '' !== $key['problem'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $key['problem'] ) . '</p></div>';
			} else {
				echo '<p>' . esc_html__( 'Not set up yet. Create a service account in a Google Cloud project with the Google Calendar API switched on, download its key (JSON) and add this line to wp-config.php, with the file outside the web folder:', 'calendar-plugin' ) . '</p>';
				echo "<pre>define( 'CALP_GOOGLE_KEY_FILE', '/path/outside/the/webroot/calendar-google.json' );</pre>";
			}
		}

		if ( 'map' === $tab ) {
			echo '<table class="form-table">';
			printf( '<tr><th>%s</th><td><input type="number" step="0.0001" name="%s" value="%s"> / <input type="number" step="0.0001" name="%s" value="%s"></td></tr>', esc_html__( 'Map centre (lat / lng)', 'calendar-plugin' ), $n( 'map_lat' ), esc_attr( $s['map_lat'] ), $n( 'map_lng' ), esc_attr( $s['map_lng'] ) );
			printf( '<tr><th>%s</th><td><input type="number" min="1" max="18" name="%s" value="%s"></td></tr>', esc_html__( 'Default zoom', 'calendar-plugin' ), $n( 'map_zoom' ), esc_attr( $s['map_zoom'] ) );
			printf( '<tr><th>%s</th><td><input type="number" min="250" max="1200" name="%s" value="%s"> px</td></tr>', esc_html__( 'Map height', 'calendar-plugin' ), $n( 'map_height' ), esc_attr( $s['map_height'] ) );
			printf( '<tr><th>%s</th><td><label><input type="checkbox" name="%s" value="1"%s> %s</label></td></tr>', esc_html__( 'Suggestions', 'calendar-plugin' ), $n( 'map_suggestions' ), checked( ! empty( $s['map_suggestions'] ), true, false ), esc_html__( 'Visitors can suggest places on the map', 'calendar-plugin' ) );
			printf( '<tr><th>%s</th><td><label><input type="checkbox" name="%s" value="1"%s> %s</label></td></tr>', esc_html__( 'Events', 'calendar-plugin' ), $n( 'map_events' ), checked( ! empty( $s['map_events'] ), true, false ), esc_html__( 'Show upcoming events on the map', 'calendar-plugin' ) );
			echo '</table>';
			echo '<p>' . esc_html__( 'Places and their categories are managed under Map places in the admin menu.', 'calendar-plugin' ) . '</p>';
		}

		if ( 'review' === $tab ) {
			echo '<p>' . esc_html__( 'Every submission is mailed to all reviewers of its area. The mail has links to approve, reject or edit – they work without logging in and open a confirmation page first, so mail scanners cannot trigger them. Once someone decides, the others are informed. One address per line; empty = the site admin address.', 'calendar-plugin' ) . '</p><table class="form-table">';
			foreach ( self::review_areas() as $area => $label ) {
				printf(
					'<tr><th>%s</th><td><textarea rows="4" cols="50" name="%s">%s</textarea></td></tr>',
					esc_html( $label ),
					esc_attr( self::OPTION . '[reviewers][' . $area . ']' ),
					esc_textarea( (string) $s['reviewers'][ $area ] )
				);
			}
			printf(
				'<tr><th>%s</th><td><textarea rows="4" cols="50" name="%s">%s</textarea><p class="description">%s</p></td></tr>',
				esc_html__( 'Contact form recipients', 'calendar-plugin' ),
				$n( 'contact_recipients' ),
				esc_textarea( (string) $s['contact_recipients'] ),
				esc_html__( 'Messages from [calp_contact_form] go to every address here (reply goes straight to the sender). One per line; empty = the site admin address.', 'calendar-plugin' )
			);
			printf( '<tr><th>%s</th><td><input type="text" class="regular-text" name="%s" value="%s" placeholder="%s"></td></tr>', esc_html__( 'Sender name in mails', 'calendar-plugin' ), $n( 'mail_from_name' ), esc_attr( $s['mail_from_name'] ), esc_attr( get_bloginfo( 'name' ) ) );
			echo '</table>';
		}

		if ( 'content' === $tab ) {
			$lines = array();
			foreach ( (array) $s['article_categories'] as $slug => $name ) {
				$lines[] = $slug . ' = ' . $name;
			}
			echo '<table class="form-table">';
			printf( '<tr><th>%s</th><td><textarea rows="4" cols="40" name="%s">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Article categories', 'calendar-plugin' ), $n( 'article_categories' ), esc_textarea( implode( "\n", $lines ) ), esc_html__( 'One per line: slug = name', 'calendar-plugin' ) );
			printf( '<tr><th>%s</th><td><input type="email" class="regular-text" name="%s" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Public contact address', 'calendar-plugin' ), $n( 'contact_email' ), esc_attr( $s['contact_email'] ), esc_html__( 'Shown for countries without a representative.', 'calendar-plugin' ) );
			printf( '<tr><th>%s</th><td><textarea rows="3" cols="60" name="%s">%s</textarea></td></tr>', esc_html__( 'Text for countries without a representative', 'calendar-plugin' ), $n( 'seeking_rep_text' ), esc_textarea( $s['seeking_rep_text'] ) );
			echo '</table>';
		}

		submit_button();
		echo '</form>';
		if ( 'bridge' === $tab ) {
			self::bridge_tools();
		}
		echo '</div>';
	}

	/** Preview / copy buttons and the result of the last run (outside the settings form). */
	private static function bridge_tools(): void {
		echo '<hr><h2>' . esc_html__( 'Run', 'calendar-plugin' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="calp_bridge">';
		wp_nonce_field( 'calp_bridge' );
		submit_button( __( 'Preview (changes nothing)', 'calendar-plugin' ), 'secondary', 'dry', false );
		echo ' ';
		submit_button( __( 'Copy now', 'calendar-plugin' ), 'primary', 'run', false );
		echo '</form>';

		$shown  = array(
			__( 'Last preview', 'calendar-plugin' ) => get_transient( 'calp_bridge_preview' ),
			__( 'Last run', 'calendar-plugin' )     => get_option( Bridge::OPTION_STATUS ),
		);
		$labels = array(
			'insert' => __( 'new', 'calendar-plugin' ),
			'patch'  => __( 'update', 'calendar-plugin' ),
			'delete' => __( 'delete', 'calendar-plugin' ),
			'skip'   => __( 'skip', 'calendar-plugin' ),
		);
		foreach ( $shown as $title => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			printf( '<h3>%s · %s</h3><p>%s %s</p>', esc_html( $title ), esc_html( wp_date( 'd.m.Y H:i', (int) $r['time'] ) ), ! empty( $r['ok'] ) ? '✅' : '⚠', esc_html( $r['message'] ) );
			if ( empty( $r['actions'] ) ) {
				continue;
			}
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Action', 'calendar-plugin' ) . '</th><th>' . esc_html__( 'Calendar', 'calendar-plugin' ) . '</th><th>' . esc_html__( 'Event', 'calendar-plugin' ) . '</th><th>' . esc_html__( 'Note', 'calendar-plugin' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $r['actions'], 0, 200 ) as $a ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $labels[ $a['do'] ] ?? $a['do'] ),
					esc_html( Bridge::LEGACY === $a['calendar'] ? __( 'legacy calendar', 'calendar-plugin' ) : $a['calendar'] ),
					esc_html( $a['title'] ),
					esc_html( $a['note'] ?? '' )
				);
			}
			echo '</tbody></table>';
		}
	}

	public static function bridge_now(): void {
		check_admin_referer( 'calp_bridge' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		$dry = isset( $_POST['dry'] );
		$r   = Bridge::run( $dry );
		if ( $dry ) {
			set_transient( 'calp_bridge_preview', $r, DAY_IN_SECONDS );
		} else {
			update_option( Bridge::OPTION_STATUS, $r, false );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=bridge&calp_msg=' . rawurlencode( $r['message'] ) ) );
		exit;
	}

	private static function page_help(): void {
		$last = (int) get_option( Sync::OPTION_LAST, 0 );
		echo '<h2>' . esc_html__( 'Calendar sync', 'calendar-plugin' ) . '</h2><p>';
		echo esc_html( $last ? sprintf( /* translators: %s: date and time */ __( 'Last sync: %s', 'calendar-plugin' ), wp_date( 'j F Y, H:i', $last ) ) : __( 'Not synced yet.', 'calendar-plugin' ) );
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="calp_sync_now">';
		wp_nonce_field( 'calp_sync_now' );
		submit_button( __( 'Sync now', 'calendar-plugin' ), 'secondary', 'submit', false );
		echo '</form><p>' . esc_html__( 'For reliable hourly syncing let your host call this address (e.g. All-Inkl: KAS › Tools › Cronjobs). WP-Cron is only a fallback.', 'calendar-plugin' ) . '</p>';
		echo '<p><code>' . esc_html( self::cron_url() ) . '</code></p>';

		echo '<h2>' . esc_html__( 'Shortcodes', 'calendar-plugin' ) . '</h2><table class="widefat striped"><tbody>';
		$codes = array(
			'[calp_explorer tabs="map,calendar" default="map"]'      => __( 'Map and calendar with tabs to switch between them.', 'calendar-plugin' ),
			'[calp_map]'                                              => __( 'The map alone (places, events, suggestion form).', 'calendar-plugin' ),
			'[calp_events category="eurocup" count="10" months="12"]' => __( 'Event list. All attributes optional; past="1" includes past events.', 'calendar-plugin' ),
			'[calp_event_form]'                                       => __( 'Public form to suggest an event.', 'calendar-plugin' ),
			'[calp_countries contacts="1"]'                           => __( 'Member countries as round flags, optionally with contact details.', 'calendar-plugin' ),
			'[calp_articles category="news" count="6"]'               => __( 'Article cards.', 'calendar-plugin' ),
			'[calp_article_form]'                                     => __( 'Public form to send in an article.', 'calendar-plugin' ),
			'[calp_event_info id="123"]'                              => __( 'Info box of an event (date, place, picture, buttons). Put on event pages automatically.', 'calendar-plugin' ),
			'[calp_contact_form]'                                     => __( 'General contact form, mailed to the contact recipients (tab Review).', 'calendar-plugin' ),
		);
		foreach ( $codes as $code => $text ) {
			printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $code ), esc_html( $text ) );
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Import from IKSA Site Kit', 'calendar-plugin' ) . '</h2><p>' . esc_html__( 'Copies countries (with flags and contacts) and articles from the old "IKSA Site Kit" plugin. Existing entries with the same title are skipped, so it is safe to run twice.', 'calendar-plugin' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="calp_import_iksa">';
		wp_nonce_field( 'calp_import_iksa' );
		submit_button( __( 'Import now', 'calendar-plugin' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function sync_now(): void {
		check_admin_referer( 'calp_sync_now' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		Sync::all();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=help&calp_msg=' . rawurlencode( __( 'Sync finished.', 'calendar-plugin' ) ) ) );
		exit;
	}

	public static function import_iksa(): void {
		check_admin_referer( 'calp_import_iksa' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		$msg = Countries::import_from_site_kit() . ' ' . Articles::import_from_site_kit();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=help&calp_msg=' . rawurlencode( $msg ) ) );
		exit;
	}
}
