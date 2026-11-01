<?php
/**
 * Member countries: round flag (featured image), organisation and contact person.
 * Shown separately from map and calendar via [calp_countries].
 */

namespace CalendarPlugin;

final class Countries {

	public const TYPE = 'calp_country';

	private const FIELDS = array( 'organization', 'contact_name', 'contact_email', 'website', 'seeking_rep', 'sort_order' );

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'        => array(
					'name'          => __( 'Countries', 'calendar-plugin' ),
					'singular_name' => __( 'Country', 'calendar-plugin' ),
					'add_new_item'  => __( 'Add country', 'calendar-plugin' ),
					'add_new'       => __( 'Add country', 'calendar-plugin' ),
					'edit_item'     => __( 'Edit country', 'calendar-plugin' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'menu_icon'     => 'dashicons-flag',
				'menu_position' => 23,
				'supports'      => array( 'title', 'thumbnail' ),
				'map_meta_cap'  => true,
			)
		);
	}

	public static function get( int $id, string $field ): string {
		return (string) get_post_meta( $id, '_calp_' . $field, true );
	}

	/** [calp_countries contacts="1"] */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'contacts' => '0' ), $atts, 'calp_countries' );
		wp_enqueue_style( 'calp' );
		$contacts = filter_var( $atts['contacts'], FILTER_VALIDATE_BOOLEAN );
		$ids      = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_calp_sort_order', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => array(
					'meta_value_num' => 'ASC',
					'title'          => 'ASC',
				),
			)
		);
		if ( ! $ids ) {
			return '';
		}
		$s    = Settings::get();
		$html = '<div class="calp-countries' . ( $contacts ? ' calp-countries--contacts' : '' ) . '">';
		foreach ( $ids as $id ) {
			$html .= '<div class="calp-country"><div class="calp-country__flag">' . get_the_post_thumbnail( $id, 'thumbnail', array( 'alt' => '' ) ) . '</div>';
			$html .= '<div class="calp-country__name">' . esc_html( get_the_title( $id ) ) . '</div>';
			if ( $contacts ) {
				$html .= '<div class="calp-country__contact">';
				if ( self::get( $id, 'seeking_rep' ) ) {
					$html .= '<em>' . esc_html( $s['seeking_rep_text'] ) . '</em>';
					if ( is_email( $s['contact_email'] ) ) {
						$html .= '<br><a href="mailto:' . esc_attr( $s['contact_email'] ) . '">' . esc_html( $s['contact_email'] ) . '</a>';
					}
				} else {
					$lines = array();
					if ( self::get( $id, 'organization' ) ) {
						$lines[] = '<strong>' . esc_html( self::get( $id, 'organization' ) ) . '</strong>';
					}
					if ( self::get( $id, 'contact_name' ) ) {
						$lines[] = esc_html( self::get( $id, 'contact_name' ) );
					}
					$mail = self::get( $id, 'contact_email' );
					if ( is_email( $mail ) ) {
						$lines[] = '<a href="mailto:' . esc_attr( $mail ) . '">' . esc_html( $mail ) . '</a>';
					}
					$web = self::get( $id, 'website' );
					if ( $web ) {
						$lines[] = '<a href="' . esc_url( $web ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://(www\.)?|/$#', '', $web ) ) . '</a>';
					}
					$html .= implode( '<br>', $lines );
				}
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	// ── Admin ────────────────────────────────────────────────────────

	public static function admin(): void {
		add_action(
			'add_meta_boxes_' . self::TYPE,
			static function () {
				add_meta_box( 'calp-country', __( 'Contact', 'calendar-plugin' ), array( self::class, 'box' ), self::TYPE, 'normal', 'high' );
			}
		);
		add_action( 'save_post_' . self::TYPE, array( self::class, 'save' ) );
	}

	public static function box( \WP_Post $post ): void {
		wp_nonce_field( 'calp_country', 'calp_country_nonce' );
		$labels = array(
			'organization'  => __( 'Organisation', 'calendar-plugin' ),
			'contact_name'  => __( 'Contact person', 'calendar-plugin' ),
			'contact_email' => __( 'E-mail', 'calendar-plugin' ),
			'website'       => __( 'Website', 'calendar-plugin' ),
			'sort_order'    => __( 'Sort order', 'calendar-plugin' ),
		);
		echo '<table class="form-table">';
		foreach ( $labels as $key => $label ) {
			printf( '<tr><th>%s</th><td><input type="text" class="regular-text" name="calp_country[%s]" value="%s"></td></tr>', esc_html( $label ), esc_attr( $key ), esc_attr( self::get( $post->ID, $key ) ) );
		}
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="calp_country[seeking_rep]" value="1"%s> %s</label></td></tr>', esc_html__( 'Representative', 'calendar-plugin' ), checked( '' !== self::get( $post->ID, 'seeking_rep' ), true, false ), esc_html__( 'We are looking for a representative (shows the text from the settings instead of contact details)', 'calendar-plugin' ) );
		echo '</table><p class="description">' . esc_html__( 'Upload the flag as featured image; it is shown as a circle.', 'calendar-plugin' ) . '</p>';
	}

	public static function save( int $id ): void {
		if ( ! isset( $_POST['calp_country_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['calp_country_nonce'] ), 'calp_country' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$in = wp_unslash( (array) ( $_POST['calp_country'] ?? array() ) );
		update_post_meta( $id, '_calp_organization', sanitize_text_field( $in['organization'] ?? '' ) );
		update_post_meta( $id, '_calp_contact_name', sanitize_text_field( $in['contact_name'] ?? '' ) );
		update_post_meta( $id, '_calp_contact_email', sanitize_email( $in['contact_email'] ?? '' ) );
		update_post_meta( $id, '_calp_website', esc_url_raw( $in['website'] ?? '' ) );
		update_post_meta( $id, '_calp_sort_order', (int) ( $in['sort_order'] ?? 0 ) );
		update_post_meta( $id, '_calp_seeking_rep', empty( $in['seeking_rep'] ) ? '' : '1' );
	}

	/** Copies countries from the IKSA Site Kit (post type iksa_country, meta _iksa_*). */
	public static function import_from_site_kit(): string {
		$old = get_posts(
			array(
				'post_type'      => 'iksa_country',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$n = 0;
		foreach ( $old as $p ) {
			$exists = get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'any',
					'title'          => $p->post_title,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $exists ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'   => self::TYPE,
					'post_status' => $p->post_status,
					'post_title'  => $p->post_title,
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			foreach ( self::FIELDS as $f ) {
				update_post_meta( $id, '_calp_' . $f, get_post_meta( $p->ID, '_iksa_' . $f, true ) );
			}
			$thumb = get_post_thumbnail_id( $p->ID );
			if ( $thumb ) {
				set_post_thumbnail( $id, $thumb );
			}
			++$n;
		}
		/* translators: %d: number */
		return sprintf( _n( '%d country imported.', '%d countries imported.', $n, 'calendar-plugin' ), $n );
	}
}
