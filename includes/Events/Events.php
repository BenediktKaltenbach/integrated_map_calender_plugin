<?php
/**
 * Post type "event". Two sources:
 *   - feed:  mirrored from a category's Google Calendar (read-only here, changes belong in the calendar)
 *   - local: entered on the website or created from an accepted suggestion (editable here)
 */

namespace CalendarPlugin\Events;

use CalendarPlugin\Plugin;
use CalendarPlugin\Review;
use CalendarPlugin\Settings;

final class Events {

	public const TYPE = 'calp_event';

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'        => array(
					'name'          => __( 'Events', 'calendar-plugin' ),
					'singular_name' => __( 'Event', 'calendar-plugin' ),
					'menu_name'     => __( 'Events', 'calendar-plugin' ),
					'all_items'     => __( 'All events', 'calendar-plugin' ),
					'add_new_item'  => __( 'Add event', 'calendar-plugin' ),
					'add_new'       => __( 'Add event', 'calendar-plugin' ),
					'edit_item'     => __( 'Edit event', 'calendar-plugin' ),
					'search_items'  => __( 'Search events', 'calendar-plugin' ),
					'not_found'     => __( 'No events yet.', 'calendar-plugin' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'show_in_rest'  => false,
				'menu_icon'     => 'dashicons-calendar-alt',
				'menu_position' => 21,
				'supports'      => array( 'title', 'editor' ),
				'map_meta_cap'  => true,
			)
		);
	}

	public static function start(): void {
		Review::area(
			'events',
			self::TYPE,
			__( 'event suggestion', 'calendar-plugin' ),
			array( self::class, 'review_details' ),
			static function ( int $id ) {
				Geo::check( $id );
			}
		);
	}

	public static function routes(): void {
		register_rest_route(
			'calp/v1',
			'/places-search',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $r ) {
					$q    = (string) $r->get_param( 'q' );
					$geo  = Geo::parse_with_short_link( $q );
					if ( $geo ) {
						return array( $geo + array( 'name' => $q ) );
					}
					$hits = Geo::search( $q, 5 );
					return is_wp_error( $hits ) ? new \WP_REST_Response( array( 'message' => $hits->get_error_message() ), 503 ) : $hits;
				},
			)
		);
	}

	/** Stored data of an event. */
	public static function data( int $id ): array {
		return array(
			'source'   => (string) get_post_meta( $id, '_calp_source', true ) ?: 'local',
			'category' => (string) get_post_meta( $id, '_calp_category', true ),
			'start'    => (int) get_post_meta( $id, '_calp_start', true ),
			'end'      => (int) get_post_meta( $id, '_calp_end', true ),
			'all_day'  => (bool) get_post_meta( $id, '_calp_all_day', true ),
			'location' => (string) get_post_meta( $id, '_calp_location', true ),
			'status'   => (string) get_post_meta( $id, '_calp_status', true ),
			'fields'   => (array) get_post_meta( $id, '_calp_fields', true ),
			'warnings' => (array) get_post_meta( $id, '_calp_warnings', true ),
			'removed'  => (int) get_post_meta( $id, '_calp_removed', true ),
		);
	}

	public static function category_name( string $slug ): string {
		return Settings::event_categories()[ $slug ]['name'] ?? $slug;
	}

	public static function category_color( string $slug ): string {
		return Settings::event_categories()[ $slug ]['color'] ?? '#31525B';
	}

	/** "display:" text or the formatted date. */
	public static function date_text( int $id, ?array $d = null ): string {
		$d = $d ?? self::data( $id );
		return '' !== ( $d['fields']['display'] ?? '' ) ? $d['fields']['display'] : Format::range( $d['start'], $d['end'], $d['all_day'] );
	}

	public static function review_details( int $id ): array {
		$d    = self::data( $id );
		$rows = array(
			__( 'Date', 'calendar-plugin' )     => Format::range( $d['start'], $d['end'], $d['all_day'] ),
			__( 'Category', 'calendar-plugin' ) => self::category_name( $d['category'] ),
			__( 'Location', 'calendar-plugin' ) => $d['location'] ?: '–',
		);
		$labels = array(
			'country'      => __( 'Country', 'calendar-plugin' ),
			'info'         => __( 'Info', 'calendar-plugin' ),
			'registration' => __( 'Registration', 'calendar-plugin' ),
			'coordinates'  => __( 'Coordinates', 'calendar-plugin' ),
		);
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $d['fields'][ $key ] ) ) {
				$rows[ $label ] = $d['fields'][ $key ];
			}
		}
		$text = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ) );
		if ( '' !== $text ) {
			$rows[ __( 'Description', 'calendar-plugin' ) ] = $text;
		}
		return $rows;
	}

	// ── Admin ────────────────────────────────────────────────────────

	public static function admin(): void {
		Review::admin();
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'filter_select' ) );
		add_action( 'pre_get_posts', array( self::class, 'admin_query' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( self::class, 'meta_boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_assets' ) );
	}

	public static function admin_assets( string $hook ): void {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && self::TYPE === get_post_type() ) {
			wp_enqueue_script( 'calp-event-form' );
		}
	}

	public static function columns( array $cols ): array {
		return array(
			'cb'            => $cols['cb'],
			'title'         => __( 'Title', 'calendar-plugin' ),
			'calp_date'     => __( 'Date', 'calendar-plugin' ),
			'calp_category' => __( 'Category', 'calendar-plugin' ),
			'calp_location' => __( 'Location', 'calendar-plugin' ),
			'calp_source'   => __( 'Source', 'calendar-plugin' ),
			'calp_map'      => __( 'Map', 'calendar-plugin' ),
			'calp_notes'    => __( 'Notes', 'calendar-plugin' ),
		);
	}

	public static function column( string $col, int $id ): void {
		$d = self::data( $id );
		switch ( $col ) {
			case 'calp_date':
				echo esc_html( $d['start'] ? self::date_text( $id, $d ) : '–' );
				break;
			case 'calp_category':
				printf( '<span style="display:inline-block;width:.8em;height:.8em;border-radius:2px;background:%s"></span> %s', esc_attr( self::category_color( $d['category'] ) ), esc_html( self::category_name( $d['category'] ) ) );
				break;
			case 'calp_location':
				echo esc_html( $d['location'] );
				break;
			case 'calp_source':
				echo esc_html( 'feed' === $d['source'] ? __( 'Google Calendar', 'calendar-plugin' ) : __( 'Website', 'calendar-plugin' ) );
				break;
			case 'calp_map':
				$geo = Geo::of( $id );
				if ( $geo ) {
					printf( '<a href="%s" target="_blank" rel="noopener">%s, %s</a>', esc_url( Geo::osm_link( $geo['lat'], $geo['lng'] ) ), esc_html( number_format( $geo['lat'], 3 ) ), esc_html( number_format( $geo['lng'], 3 ) ) );
				} else {
					echo '–';
				}
				$err = (string) get_post_meta( $id, '_calp_geo_error', true );
				if ( $err ) {
					echo '<br><span style="color:#b32d2e">⚠ ' . esc_html( $err ) . '</span>';
				}
				break;
			case 'calp_notes':
				$notes = array();
				if ( $d['removed'] ) {
					$notes[] = '<strong style="color:#b32d2e">' . esc_html__( 'Deleted in Google Calendar', 'calendar-plugin' ) . '</strong>';
				}
				if ( $d['status'] ) {
					$notes[] = esc_html( Parser::status_labels()[ $d['status'] ] ?? $d['status'] );
				}
				$review = Review::status_text( $id );
				if ( $review ) {
					$notes[] = esc_html( $review );
				}
				foreach ( $d['warnings'] as $w ) {
					$notes[] = '<span style="color:#996800">⚠ ' . esc_html( $w ) . '</span>';
				}
				echo wp_kses_post( implode( '<br>', $notes ) );
				break;
		}
	}

	public static function filter_select( string $post_type ): void {
		if ( self::TYPE !== $post_type ) {
			return;
		}
		$active = sanitize_key( $_GET['calp_category'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="calp_category"><option value="">' . esc_html__( 'All categories', 'calendar-plugin' ) . '</option>';
		foreach ( Settings::event_categories() as $slug => $c ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $active, $slug, false ), esc_html( $c['name'] ) );
		}
		echo '</select>';
	}

	public static function admin_query( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || self::TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		$cat = sanitize_key( $_GET['calp_category'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $cat ) {
			$q->set(
				'meta_query',
				array(
					array(
						'key'   => '_calp_category',
						'value' => $cat,
					),
				)
			);
		}
		if ( ! $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', '_calp_start' );
			$q->set( 'orderby', 'meta_value_num' );
			$q->set( 'order', 'DESC' );
		}
	}

	public static function meta_boxes( \WP_Post $post ): void {
		add_meta_box( 'calp-event', __( 'Event details', 'calendar-plugin' ), array( self::class, 'box' ), self::TYPE, 'normal', 'high' );
	}

	public static function box( \WP_Post $post ): void {
		$d  = self::data( $post->ID );
		$tz = Format::tz();
		if ( 'feed' === $d['source'] ) {
			echo '<p><em>' . esc_html__( 'This event is mirrored from Google Calendar. Please change it in the calendar; changes appear after the next sync. Only the map position can be set here.', 'calendar-plugin' ) . '</em></p>';
		}
		wp_nonce_field( 'calp_event_save', 'calp_event_nonce' );
		$start = $d['start'] ? ( new \DateTimeImmutable( '@' . $d['start'] ) )->setTimezone( $tz ) : null;
		$end   = $d['end'] ? ( new \DateTimeImmutable( '@' . $d['end'] ) )->setTimezone( $tz ) : null;
		if ( $end && $d['all_day'] ) {
			$end = $end->modify( '-1 day' );
		}
		$f        = $d['fields'];
		$readonly = 'feed' === $d['source'] ? ' disabled' : '';
		$geo      = Geo::of( $post->ID );

		echo '<table class="form-table calp-event-box"><tbody>';
		echo '<tr><th>' . esc_html__( 'Category', 'calendar-plugin' ) . '</th><td><select name="calp[category]"' . $readonly . '>';
		foreach ( Settings::event_categories() as $slug => $c ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $d['category'], $slug, false ), esc_html( $c['name'] ) );
		}
		echo '</select></td></tr>';
		printf(
			'<tr><th>%s</th><td><input type="date" name="calp[start_date]" value="%s"%s> <input type="time" name="calp[start_time]" value="%s"%s> – <input type="date" name="calp[end_date]" value="%s"%s> <input type="time" name="calp[end_time]" value="%s"%s><p class="description">%s</p></td></tr>',
			esc_html__( 'From – to', 'calendar-plugin' ),
			esc_attr( $start ? $start->format( 'Y-m-d' ) : '' ),
			$readonly, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_attr( $start && ! $d['all_day'] ? $start->format( 'H:i' ) : '' ),
			$readonly, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_attr( $end ? $end->format( 'Y-m-d' ) : '' ),
			$readonly, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_attr( $end && ! $d['all_day'] ? $end->format( 'H:i' ) : '' ),
			$readonly, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html__( 'Without times the event counts as all-day.', 'calendar-plugin' )
		);
		$inputs = array(
			'location'     => array( __( 'Location', 'calendar-plugin' ), $d['location'], 'text' ),
			'country'      => array( __( 'Country (ISO code, e.g. CZ)', 'calendar-plugin' ), $f['country'] ?? '', 'text' ),
			'info'         => array( __( 'Info link', 'calendar-plugin' ), $f['info'] ?? '', 'url' ),
			'registration' => array( __( 'Registration link', 'calendar-plugin' ), $f['registration'] ?? '', 'url' ),
			'display'      => array( __( 'Show instead of date (optional)', 'calendar-plugin' ), $f['display'] ?? '', 'text' ),
		);
		foreach ( $inputs as $key => [ $label, $value, $type ] ) {
			printf( '<tr><th>%s</th><td><input type="%s" class="regular-text" name="calp[%s]" value="%s"%s></td></tr>', esc_html( $label ), esc_attr( $type ), esc_attr( $key ), esc_attr( $value ), $readonly ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><th>' . esc_html__( 'Status', 'calendar-plugin' ) . '</th><td><select name="calp[status]"' . $readonly . '><option value="">' . esc_html__( 'normal', 'calendar-plugin' ) . '</option>';
		foreach ( Parser::status_labels() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $d['status'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		$manual = get_post_meta( $post->ID, '_calp_geo_manual', true );
		printf(
			'<tr><th>%s</th><td><div class="calp-place-search" data-target="calp-geo"><input type="search" class="regular-text calp-place-q" value="%s"> <button type="button" class="button calp-place-btn">%s</button><div class="calp-place-hits"></div></div><input type="text" id="calp-geo" name="calp[geo]" value="%s" placeholder="50.7151, 7.1393" class="regular-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Position on the map', 'calendar-plugin' ),
			esc_attr( $d['location'] ),
			esc_html__( 'Find place', 'calendar-plugin' ),
			esc_attr( is_array( $manual ) ? $manual['lat'] . ', ' . $manual['lng'] : '' ),
			esc_html(
				$geo
					? sprintf( /* translators: 1: lat, 2: lng, 3: source */ __( 'Current: %1$s, %2$s (%3$s). Leave empty to determine it automatically from the location.', 'calendar-plugin' ), $geo['lat'], $geo['lng'], $geo['source'] )
					: __( 'Leave empty to determine it automatically from the location.', 'calendar-plugin' )
			)
		);
		echo '</tbody></table>';
	}

	public static function save( int $id, \WP_Post $post ): void {
		if ( ! isset( $_POST['calp_event_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['calp_event_nonce'] ), 'calp_event_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$in = wp_unslash( (array) ( $_POST['calp'] ?? array() ) );

		// Map position works for both sources.
		$geo = Geo::parse( (string) ( $in['geo'] ?? '' ) );
		if ( $geo ) {
			update_post_meta( $id, '_calp_geo_manual', $geo );
		} else {
			delete_post_meta( $id, '_calp_geo_manual' );
		}

		if ( 'feed' !== self::data( $id )['source'] ) {
			self::store_local( $id, $in );
		}
		Geo::check( $id );
	}

	/**
	 * Stores the fields of a local event (admin box and suggestion form share the format).
	 *
	 * @return true|\WP_Error
	 */
	public static function store_local( int $id, array $in ) {
		$tz       = Format::tz();
		$cats     = Settings::event_categories();
		$category = sanitize_key( $in['category'] ?? '' );
		if ( ! isset( $cats[ $category ] ) ) {
			$category = (string) array_key_first( $cats );
		}
		$sd = (string) ( $in['start_date'] ?? '' );
		$ed = (string) ( $in['end_date'] ?? '' ) ?: $sd;
		$st = (string) ( $in['start_time'] ?? '' );
		$et = (string) ( $in['end_time'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sd ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ed ) ) {
			return new \WP_Error( 'calp_date', __( 'Please enter a valid date.', 'calendar-plugin' ) );
		}
		$all_day = ! preg_match( '/^\d{2}:\d{2}$/', $st );
		try {
			if ( $all_day ) {
				$start = new \DateTimeImmutable( $sd . ' 00:00', $tz );
				$end   = ( new \DateTimeImmutable( $ed . ' 00:00', $tz ) )->modify( '+1 day' );
			} else {
				$start = new \DateTimeImmutable( $sd . ' ' . $st, $tz );
				$end   = new \DateTimeImmutable( $ed . ' ' . ( preg_match( '/^\d{2}:\d{2}$/', $et ) ? $et : $st ), $tz );
			}
		} catch ( \Exception $e ) {
			return new \WP_Error( 'calp_date', __( 'Please enter a valid date.', 'calendar-plugin' ) );
		}
		if ( $end < $start ) {
			$end = $all_day ? $start->modify( '+1 day' ) : $start;
		}

		$fields = array();
		foreach ( array( 'info', 'registration' ) as $key ) {
			$url = esc_url_raw( trim( (string) ( $in[ $key ] ?? '' ) ) );
			if ( '' !== $url ) {
				$fields[ $key ] = $url;
			}
		}
		$country = strtoupper( trim( (string) ( $in['country'] ?? '' ) ) );
		if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$fields['country'] = $country;
		}
		$display = sanitize_text_field( $in['display'] ?? '' );
		if ( '' !== $display ) {
			$fields['display'] = mb_substr( $display, 0, 60 );
		}
		$status = sanitize_key( $in['status'] ?? '' );

		update_post_meta( $id, '_calp_source', 'local' );
		update_post_meta( $id, '_calp_category', $category );
		update_post_meta( $id, '_calp_start', $start->getTimestamp() );
		update_post_meta( $id, '_calp_end', $end->getTimestamp() );
		update_post_meta( $id, '_calp_all_day', $all_day ? 1 : 0 );
		update_post_meta( $id, '_calp_location', sanitize_text_field( $in['location'] ?? '' ) );
		update_post_meta( $id, '_calp_status', isset( Parser::status_labels()[ $status ] ) ? $status : '' );
		update_post_meta( $id, '_calp_fields', $fields );
		return true;
	}
}
