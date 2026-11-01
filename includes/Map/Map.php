<?php
/**
 * Interactive Leaflet map: permanent places (post type with categories), upcoming events
 * and a public form to suggest places. Ported from "My Map Plugin".
 */

namespace CalendarPlugin\Map;

use CalendarPlugin\Events\Events;
use CalendarPlugin\Events\Format;
use CalendarPlugin\Events\Geo;
use CalendarPlugin\Events\Pages;
use CalendarPlugin\Events\Parser;
use CalendarPlugin\Plugin;
use CalendarPlugin\Review;
use CalendarPlugin\Settings;
use const CalendarPlugin\VERSION;

final class Map {

	public const TYPE = 'calp_place';
	public const TAX  = 'calp_place_cat';

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'        => array(
					'name'          => __( 'Map places', 'calendar-plugin' ),
					'singular_name' => __( 'Map place', 'calendar-plugin' ),
					'add_new_item'  => __( 'Add place', 'calendar-plugin' ),
					'add_new'       => __( 'Add place', 'calendar-plugin' ),
					'edit_item'     => __( 'Edit place', 'calendar-plugin' ),
					'not_found'     => __( 'No places yet.', 'calendar-plugin' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'menu_icon'     => 'dashicons-location-alt',
				'menu_position' => 22,
				'supports'      => array( 'title', 'editor', 'thumbnail' ),
				'map_meta_cap'  => true,
			)
		);
		register_taxonomy(
			self::TAX,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Place categories', 'calendar-plugin' ),
					'singular_name' => __( 'Place category', 'calendar-plugin' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
			)
		);
	}

	public static function start(): void {
		Review::area(
			'places',
			self::TYPE,
			__( 'place suggestion', 'calendar-plugin' ),
			static function ( int $id ): array {
				$lat   = get_post_meta( $id, '_calp_lat', true );
				$lng   = get_post_meta( $id, '_calp_lng', true );
				$terms = wp_get_object_terms( $id, self::TAX, array( 'fields' => 'names' ) );
				return array(
					__( 'Category', 'calendar-plugin' )    => is_array( $terms ) && $terms ? implode( ', ', $terms ) : '–',
					__( 'Position', 'calendar-plugin' )    => Geo::osm_link( (float) $lat, (float) $lng ),
					__( 'Description', 'calendar-plugin' ) => trim( wp_strip_all_tags( str_replace( array( '<br>', '</p>' ), "\n", (string) get_post_field( 'post_content', $id ) ) ) ),
				);
			}
		);
	}

	public static function register_assets(): void {
		// Own handles: other plugins (e.g. Otter Blocks) register "leaflet" with an older version.
		wp_register_style( 'calp-leaflet', Plugin::url( 'assets/leaflet/leaflet.css' ), array(), '1.9.4' );
		wp_register_style( 'calp-leaflet-cluster', Plugin::url( 'assets/leaflet/MarkerCluster.css' ), array( 'calp-leaflet' ), '1.5.3' );
		wp_register_style( 'calp-leaflet-cluster-default', Plugin::url( 'assets/leaflet/MarkerCluster.Default.css' ), array( 'calp-leaflet-cluster' ), '1.5.3' );
		wp_register_script( 'calp-leaflet', Plugin::url( 'assets/leaflet/leaflet.js' ), array(), '1.9.4', true );
		wp_register_script( 'calp-leaflet-cluster', Plugin::url( 'assets/leaflet/leaflet.markercluster.js' ), array( 'calp-leaflet' ), '1.5.3', true );
		wp_register_script( 'calp-map', Plugin::url( 'assets/map.js' ), array( 'calp-leaflet', 'calp-leaflet-cluster' ), VERSION, true );
	}

	private static function enqueue(): void {
		$s = Settings::get();
		wp_enqueue_style( 'calp' );
		wp_enqueue_style( 'calp-leaflet-cluster-default' );
		wp_enqueue_script( 'calp-map' );
		// No REST nonce: endpoints are public, and an outdated nonce (iOS keeps tabs open for days) would fail with 403.
		wp_localize_script(
			'calp-map',
			'calpMap',
			array(
				'rest'        => rest_url( 'calp/v1/' ),
				'lat'         => (float) $s['map_lat'],
				'lng'         => (float) $s['map_lng'],
				'zoom'        => (int) $s['map_zoom'],
				'suggestions' => ! empty( $s['map_suggestions'] ),
				'i18n'        => array(
					'categories'  => __( 'Places', 'calendar-plugin' ),
					'events'      => __( 'Events', 'calendar-plugin' ),
					'clickMap'    => __( '📍 Click on the map to mark the place', 'calendar-plugin' ),
					'coordsSet'   => __( '✅ Position set:', 'calendar-plugin' ),
					'yours'       => __( 'Your suggestion', 'calendar-plugin' ),
					'close'       => __( '✕ Close', 'calendar-plugin' ),
					'open'        => __( '📍 Suggest a place', 'calendar-plugin' ),
					'needTitle'   => __( 'Please enter a name.', 'calendar-plugin' ),
					'needText'    => __( 'Please add a description.', 'calendar-plugin' ),
					'needCoords'  => __( 'Please click on the map to choose the place.', 'calendar-plugin' ),
					'sending'     => __( 'Sending …', 'calendar-plugin' ),
					'send'        => __( 'Send suggestion', 'calendar-plugin' ),
					'thanks'      => __( '✅ Thank you! Your suggestion will be checked before it is published.', 'calendar-plugin' ),
					'error'       => __( 'Error:', 'calendar-plugin' ),
					'tryAgain'    => __( 'Please try again.', 'calendar-plugin' ),
					'offline'     => __( 'No connection to the server. Please try again.', 'calendar-plugin' ),
					'attribution' => __( '© <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> contributors', 'calendar-plugin' ),
				),
			)
		);
	}

	/** [calp_map height="550"] */
	public static function shortcode( $atts = array() ): string {
		$s    = Settings::get();
		$atts = shortcode_atts( array( 'height' => $s['map_height'] ), $atts, 'calp_map' );
		self::enqueue();
		$cats = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
			)
		);

		ob_start();
		?>
		<div class="calp-map-wrap">
			<div class="calp-map" style="height:<?php echo (int) $atts['height']; ?>px"></div>
			<?php if ( ! empty( $s['map_suggestions'] ) && is_array( $cats ) && $cats ) : ?>
			<div class="calp-map-actions"><button type="button" class="calp-btn calp-btn--secondary calp-suggest-toggle"><?php esc_html_e( '📍 Suggest a place', 'calendar-plugin' ); ?></button></div>
			<div class="calp-form calp-suggest-form" hidden>
				<h3><?php esc_html_e( 'Suggest a place', 'calendar-plugin' ); ?></h3>
				<p class="calp-form__hint"><?php esc_html_e( 'For places that are permanently there – tracks, meeting points, clubs. One-time events please via "Suggest an event". Your suggestion is checked before it is published.', 'calendar-plugin' ); ?></p>
				<p class="calp-hp" aria-hidden="true"><label>Website <input type="text" class="calp-s-hp" tabindex="-1" autocomplete="off"></label></p>
				<p><label><?php esc_html_e( 'Name of the place', 'calendar-plugin' ); ?> *<br><input type="text" class="calp-s-title" maxlength="100"></label></p>
				<p><label><?php esc_html_e( 'Category', 'calendar-plugin' ); ?> *<br><select class="calp-s-category">
					<?php foreach ( $cats as $t ) : ?>
						<option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
				<p><label><?php esc_html_e( 'Description', 'calendar-plugin' ); ?> *<br><textarea class="calp-s-content" rows="3"></textarea></label></p>
				<p class="calp-s-coords"><?php esc_html_e( '📍 Click on the map to mark the place', 'calendar-plugin' ); ?></p>
				<div class="calp-form__row">
					<p><label><?php esc_html_e( 'Your name (optional)', 'calendar-plugin' ); ?><br><input type="text" class="calp-s-name" maxlength="80"></label></p>
					<p><label><?php esc_html_e( 'E-mail (optional, not published)', 'calendar-plugin' ); ?><br><input type="email" class="calp-s-email"></label></p>
				</div>
				<p><button type="button" class="calp-btn calp-s-submit"><?php esc_html_e( 'Send suggestion', 'calendar-plugin' ); ?></button></p>
				<p class="calp-s-feedback" hidden></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ── REST ─────────────────────────────────────────────────────────

	public static function routes(): void {
		register_rest_route(
			'calp/v1',
			'/map',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'data' ),
			)
		);
		register_rest_route(
			'calp/v1',
			'/suggest-place',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'suggest' ),
			)
		);
	}

	public static function data(): \WP_REST_Response {
		$categories = array();
		$markers    = array();

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => true,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$categories[] = array(
				'id'    => 'place-' . $t->term_id,
				'name'  => $t->name,
				'color' => (string) get_term_meta( $t->term_id, 'calp_color', true ) ?: '#31525B',
				'group' => 'places',
			);
		}
		$ids = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			$terms = wp_get_object_terms( $id, self::TAX );
			$term  = is_array( $terms ) && $terms ? $terms[0] : null;
			$img   = get_the_post_thumbnail_url( $id, 'medium' );
			$markers[] = array(
				'title'    => get_the_title( $id ),
				'html'     => wp_kses_post( wpautop( (string) get_post_field( 'post_content', $id ) ) ),
				'lat'      => (float) get_post_meta( $id, '_calp_lat', true ),
				'lng'      => (float) get_post_meta( $id, '_calp_lng', true ),
				'color'    => $term ? ( (string) get_term_meta( $term->term_id, 'calp_color', true ) ?: '#31525B' ) : '#31525B',
				'category' => $term ? 'place-' . $term->term_id : '',
				'catName'  => $term ? $term->name : '',
				'images'   => $img ? array( $img ) : array(),
				'type'     => 'place',
			);
		}

		if ( ! empty( Settings::get()['map_events'] ) ) {
			[ $ecats, $emarkers ] = self::event_layer();
			$categories           = array_merge( $categories, $ecats );
			$markers              = array_merge( $markers, $emarkers );
		}

		return new \WP_REST_Response(
			array(
				'categories' => $categories,
				'markers'    => $markers,
			),
			200
		);
	}

	/** Upcoming, not cancelled events with coordinates; for recurring events only the next date. */
	private static function event_layer(): array {
		$cats    = Settings::event_categories();
		$ids     = get_posts(
			array(
				'post_type'      => Events::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_key'       => '_calp_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_calp_end',
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_calp_removed',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		update_meta_cache( 'post', $ids );
		$markers = array();
		$used    = array();
		$series  = array();
		foreach ( $ids as $id ) {
			$d   = Events::data( $id );
			$geo = Geo::of( $id );
			if ( ! $geo || 'cancelled' === $d['status'] || empty( $cats[ $d['category'] ]['on_map'] ) ) {
				continue;
			}
			$uid = strtok( (string) get_post_meta( $id, '_calp_key', true ), '|' ) ?: 'local-' . $id;
			if ( isset( $series[ $uid ] ) ) {
				continue;
			}
			$series[ $uid ]           = true;
			$used[ $d['category'] ] = true;

			$lines = array( '<strong>' . esc_html( Events::date_text( $id, $d ) ) . '</strong>' );
			if ( '' !== $d['location'] ) {
				$lines[] = ( ! empty( $d['fields']['country'] ) ? esc_html( Format::flag( $d['fields']['country'] ) ) . ' ' : '' ) . esc_html( $d['location'] );
			}
			if ( $d['status'] ) {
				$lines[] = '<em>' . esc_html( Parser::status_labels()[ $d['status'] ] ?? $d['status'] ) . '</em>';
			}
			$links = array();
			$info  = Pages::info_url( $id, $d['fields'] );
			if ( '' !== $info ) {
				$links[] = '<a href="' . esc_url( $info ) . '">' . esc_html__( 'Info', 'calendar-plugin' ) . '</a>';
			}
			if ( ! empty( $d['fields']['registration'] ) ) {
				$links[] = '<a href="' . esc_url( $d['fields']['registration'] ) . '">' . esc_html__( 'Register', 'calendar-plugin' ) . '</a>';
			}
			if ( $links ) {
				$lines[] = implode( ' · ', $links );
			}
			$markers[] = array(
				'title'    => get_the_title( $id ),
				'html'     => implode( '<br>', $lines ),
				'lat'      => $geo['lat'],
				'lng'      => $geo['lng'],
				'color'    => Events::category_color( $d['category'] ),
				'category' => 'event-' . $d['category'],
				'catName'  => Events::category_name( $d['category'] ),
				'images'   => array(),
				'type'     => 'event',
			);
		}
		$categories = array();
		foreach ( $cats as $slug => $c ) {
			if ( isset( $used[ $slug ] ) ) {
				$categories[] = array(
					'id'    => 'event-' . $slug,
					'name'  => $c['name'],
					'color' => $c['color'],
					'group' => 'events',
				);
			}
		}
		return array( $categories, $markers );
	}

	public static function suggest( \WP_REST_Request $r ) {
		if ( empty( Settings::get()['map_suggestions'] ) ) {
			return new \WP_Error( 'calp_closed', __( 'Suggestions are currently closed.', 'calendar-plugin' ), array( 'status' => 403 ) );
		}
		$p = (array) $r->get_json_params();
		if ( ! empty( $p['website'] ) ) {
			return new \WP_REST_Response( array( 'success' => true ), 201 ); // honeypot: pretend success
		}
		if ( Plugin::rate_limited( 'place' ) ) {
			return new \WP_Error( 'calp_rate', __( 'Too many suggestions from your connection. Please try again later.', 'calendar-plugin' ), array( 'status' => 429 ) );
		}
		$title   = sanitize_text_field( $p['title'] ?? '' );
		$content = wp_kses(
			(string) ( $p['content'] ?? '' ),
			array(
				'a'      => array( 'href' => array() ),
				'b'      => array(),
				'strong' => array(),
				'em'     => array(),
				'br'     => array(),
			)
		);
		$lat     = filter_var( $p['lat'] ?? null, FILTER_VALIDATE_FLOAT );
		$lng     = filter_var( $p['lng'] ?? null, FILTER_VALIDATE_FLOAT );
		$term    = get_term( absint( $p['category'] ?? 0 ), self::TAX );
		if ( '' === $title || mb_strlen( $title ) > 100 || '' === trim( $content ) || false === $lat || false === $lng || abs( $lat ) > 90 || abs( $lng ) > 180 || ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'calp_invalid', __( 'Please fill in all required fields and click on the map.', 'calendar-plugin' ), array( 'status' => 400 ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'pending',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'calp_save', __( 'Saving failed. Please try again.', 'calendar-plugin' ), array( 'status' => 500 ) );
		}
		wp_set_object_terms( $id, array( $term->term_id ), self::TAX );
		update_post_meta( $id, '_calp_lat', round( $lat, 6 ) );
		update_post_meta( $id, '_calp_lng', round( $lng, 6 ) );
		update_post_meta( $id, '_calp_submitter_name', sanitize_text_field( $p['name'] ?? '' ) );
		$email = sanitize_email( $p['email'] ?? '' );
		if ( is_email( $email ) ) {
			update_post_meta( $id, '_calp_submitter_email', $email );
		}
		Plugin::count_submission( 'place' );
		Review::submit( $id, 'places' );
		return new \WP_REST_Response( array( 'success' => true ), 201 );
	}

	// ── Admin ────────────────────────────────────────────────────────

	public static function admin(): void {
		add_action( 'add_meta_boxes_' . self::TYPE, array( self::class, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( self::class, 'save' ) );
		add_action( self::TAX . '_add_form_fields', array( self::class, 'term_add_field' ) );
		add_action( self::TAX . '_edit_form_fields', array( self::class, 'term_edit_field' ) );
		add_action( 'created_' . self::TAX, array( self::class, 'term_save' ) );
		add_action( 'edited_' . self::TAX, array( self::class, 'term_save' ) );
		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook ) {
				if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && self::TYPE === get_post_type() ) {
					wp_enqueue_style( 'calp-leaflet' );
					wp_enqueue_script( 'calp-admin-place', Plugin::url( 'assets/admin-place.js' ), array( 'calp-leaflet' ), VERSION, true );
					$s = Settings::get();
					wp_localize_script(
						'calp-admin-place',
						'calpPlace',
						array(
							'lat'  => (float) $s['map_lat'],
							'lng'  => (float) $s['map_lng'],
							'zoom' => (int) $s['map_zoom'],
						)
					);
				}
			}
		);
	}

	public static function meta_box(): void {
		add_meta_box(
			'calp-place',
			__( 'Position', 'calendar-plugin' ),
			static function ( \WP_Post $post ) {
				wp_nonce_field( 'calp_place_save', 'calp_place_nonce' );
				printf(
					'<p>%s</p><div id="calp-place-map" style="height:360px"></div><p><label>%s <input type="text" id="calp-lat" name="calp_lat" value="%s" size="12"></label> <label>%s <input type="text" id="calp-lng" name="calp_lng" value="%s" size="12"></label></p>',
					esc_html__( 'Click on the map or drag the marker. You can also paste coordinates.', 'calendar-plugin' ),
					esc_html__( 'Latitude', 'calendar-plugin' ),
					esc_attr( (string) get_post_meta( $post->ID, '_calp_lat', true ) ),
					esc_html__( 'Longitude', 'calendar-plugin' ),
					esc_attr( (string) get_post_meta( $post->ID, '_calp_lng', true ) )
				);
				$status = Review::status_text( $post->ID );
				if ( $status ) {
					echo '<p><em>' . esc_html( $status ) . '</em></p>';
				}
			},
			self::TYPE,
			'normal',
			'high'
		);
	}

	public static function save( int $id ): void {
		if ( ! isset( $_POST['calp_place_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['calp_place_nonce'] ), 'calp_place_save' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$lat = filter_var( wp_unslash( $_POST['calp_lat'] ?? '' ), FILTER_VALIDATE_FLOAT );
		$lng = filter_var( wp_unslash( $_POST['calp_lng'] ?? '' ), FILTER_VALIDATE_FLOAT );
		if ( false !== $lat && false !== $lng && abs( $lat ) <= 90 && abs( $lng ) <= 180 ) {
			update_post_meta( $id, '_calp_lat', round( $lat, 6 ) );
			update_post_meta( $id, '_calp_lng', round( $lng, 6 ) );
		}
	}

	public static function term_add_field(): void {
		echo '<div class="form-field"><label for="calp_color">' . esc_html__( 'Colour', 'calendar-plugin' ) . '</label><input type="color" name="calp_color" id="calp_color" value="#31525B"></div>';
		wp_nonce_field( 'calp_term', 'calp_term_nonce' );
	}

	public static function term_edit_field( \WP_Term $term ): void {
		printf( '<tr class="form-field"><th><label for="calp_color">%s</label></th><td><input type="color" name="calp_color" id="calp_color" value="%s"></td></tr>', esc_html__( 'Colour', 'calendar-plugin' ), esc_attr( (string) get_term_meta( $term->term_id, 'calp_color', true ) ?: '#31525B' ) );
		wp_nonce_field( 'calp_term', 'calp_term_nonce' );
	}

	public static function term_save( int $term_id ): void {
		if ( isset( $_POST['calp_term_nonce'], $_POST['calp_color'] ) && wp_verify_nonce( sanitize_key( $_POST['calp_term_nonce'] ), 'calp_term' ) && current_user_can( 'manage_categories' ) ) {
			update_term_meta( $term_id, 'calp_color', sanitize_hex_color( wp_unslash( $_POST['calp_color'] ) ) ?: '#31525B' );
		}
	}
}
