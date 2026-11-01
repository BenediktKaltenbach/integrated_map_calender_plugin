<?php
/**
 * A normal WordPress page per event: info box at the top (date, place, picture, buttons –
 * always current, it reads the event), the description below. The page is created once
 * and then belongs to the editors; a deleted page does not come back.
 *
 * "Info" in lists and on the map leads to the external info link if there is one,
 * otherwise to this page.
 */

namespace CalendarPlugin\Events;

use CalendarPlugin\Settings;

final class Pages {

	/** At most this many new pages per sync (e.g. weekly series). */
	private const MAX_PER_RUN = 20;
	/** At most this many image downloads per sync, so a run does not time out on shared hosting. */
	private const MAX_DOWNLOADS = 5;
	private const RETRY         = 6 * HOUR_IN_SECONDS;
	private const MAX_MB        = 8;

	private static int $created   = 0;
	private static int $downloads = 0;

	public static function admin(): void {
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_calp_event_page', array( self::class, 'create_button' ) );
	}

	public static function enabled(): bool {
		return ! empty( Settings::get()['event_pages'] );
	}

	/** Page of an event (draft or published), 0 if none or deleted. */
	public static function page_id( int $event ): int {
		$page = (int) get_post_meta( $event, '_calp_page', true );
		$st   = $page ? get_post_status( $page ) : false;
		return $st && 'trash' !== $st ? $page : 0;
	}

	public static function page_url( int $event ): string {
		$page = self::page_id( $event );
		return $page && 'publish' === get_post_status( $page ) ? (string) get_permalink( $page ) : '';
	}

	/** Target of "Info": the external info link has priority, otherwise the event page. */
	public static function info_url( int $event, array $fields ): string {
		$info = (string) ( $fields['info'] ?? '' );
		return '' !== $info ? $info : self::page_url( $event );
	}

	/** After sync, approval or saving: create the page once (upcoming events only) and keep its picture current. */
	public static function check( int $event ): void {
		if ( ! $event || 'publish' !== get_post_status( $event ) ) {
			return;
		}
		$page = self::page_id( $event );
		if ( ! $page && self::enabled() && ! get_post_meta( $event, '_calp_page_auto', true ) && self::$created < self::MAX_PER_RUN ) {
			$d = Events::data( $event );
			if ( ! $d['removed'] && $d['end'] >= time() && 'cancelled' !== $d['status'] ) {
				++self::$created;
				update_post_meta( $event, '_calp_page_auto', 1 );
				$new  = self::create( $event );
				$page = is_wp_error( $new ) ? 0 : $new;
			}
		}
		if ( $page ) {
			self::picture( $event, $page );
		}
	}

	/**
	 * Creates the (published) page: info box, then the description as paragraphs.
	 *
	 * @return int|\WP_Error page ID
	 */
	public static function create( int $event, int $author = 0 ) {
		$post = get_post( $event );
		if ( ! $post || Events::TYPE !== $post->post_type ) {
			return new \WP_Error( 'calp', __( 'Event not found.', 'calendar-plugin' ) );
		}
		$content = "<!-- wp:shortcode -->\n[calp_event_info id=\"" . $event . "\"]\n<!-- /wp:shortcode -->";
		$text    = trim( (string) $post->post_content );
		if ( '' === $text ) {
			$text = __( 'More details about this event will be published here.', 'calendar-plugin' );
		}
		foreach ( preg_split( '/\n\s*\n/', $text ) as $para ) {
			if ( '' !== trim( $para ) ) {
				$content .= "\n\n<!-- wp:paragraph -->\n<p>" . nl2br( make_clickable( esc_html( trim( $para ) ) ) ) . "</p>\n<!-- /wp:paragraph -->";
			}
		}

		// Event pages must not land in menus that add new top-level pages automatically.
		$auto_menu = has_action( 'transition_post_status', '_wp_auto_add_pages_to_menu' );
		if ( false !== $auto_menu ) {
			remove_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', $auto_menu );
		}
		$page = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $post->post_title,
					'post_content' => $content,
					'post_parent'  => (int) Settings::get()['event_page_parent'],
					'post_author'  => $author ?: self::author(),
					'meta_input'   => array( '_calp_event' => $event ),
				)
			),
			true
		);
		if ( false !== $auto_menu ) {
			add_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', $auto_menu, 3 );
		}
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		update_post_meta( $event, '_calp_page', $page );
		return $page;
	}

	/** Author of automatic pages: the first administrator. */
	private static function author(): int {
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		return (int) ( $ids[0] ?? 0 );
	}

	// ── Picture ──────────────────────────────────────────────────────

	/** Picture of the event (upload from the suggestion form or "Image:" line) as featured image of the page. */
	private static function picture( int $event, int $page ): void {
		$att = (int) get_post_meta( $event, '_calp_image_upload', true );
		if ( ! $att ) {
			$url = (string) ( Events::data( $event )['fields']['image'] ?? '' );
			$att = '' !== $url ? self::attachment( $event, $url, $page ) : 0;
		}
		if ( ! $att ) {
			return;
		}
		// An image the editors chose themselves stays.
		$current = (int) get_post_thumbnail_id( $page );
		if ( $current && (int) get_post_meta( $page, '_calp_auto_image', true ) !== $current ) {
			return;
		}
		set_post_thumbnail( $page, $att );
		update_post_meta( $page, '_calp_auto_image', $att );
	}

	/** Google Drive file ID from the usual link forms, else ''. */
	public static function drive_id( string $url ): string {
		if ( ! preg_match( '#^https?://(drive|docs)\.google\.com/#i', $url ) ) {
			return '';
		}
		if ( preg_match( '#/file/d/([A-Za-z0-9_-]{10,})#', $url, $m ) || preg_match( '#[?&]id=([A-Za-z0-9_-]{10,})#', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** Media library ID for the image address, downloads it if needed; 0 on error (message in _calp_image_error). */
	private static function attachment( int $event, string $url, int $page ): int {
		$local = attachment_url_to_postid( $url );
		if ( $local ) {
			return $local;
		}
		$drive = self::drive_id( $url );
		$key   = '' !== $drive ? 'drive:' . $drive : 'url:' . md5( $url );
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_calp_image_source', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
		if ( self::$downloads >= self::MAX_DOWNLOADS ) {
			return 0;
		}
		$try = (array) get_post_meta( $event, '_calp_image_try', true );
		if ( ( $try['key'] ?? '' ) === $key && time() - (int) ( $try['time'] ?? 0 ) < self::RETRY ) {
			return 0;
		}
		update_post_meta(
			$event,
			'_calp_image_try',
			array(
				'key'  => $key,
				'time' => time(),
			)
		);
		++self::$downloads;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$fail = static function ( string $msg ) use ( $event ): int {
			update_post_meta( $event, '_calp_image_error', $msg );
			return 0;
		};
		$file = download_url( '' !== $drive ? 'https://drive.google.com/uc?export=download&id=' . $drive : $url, 30 );
		if ( is_wp_error( $file ) ) {
			/* translators: %s: error message */
			return $fail( sprintf( __( 'Picture could not be loaded: %s', 'calendar-plugin' ), $file->get_error_message() ) );
		}
		$ext = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		)[ (string) wp_get_image_mime( $file ) ] ?? '';
		if ( '' === $ext || filesize( $file ) > self::MAX_MB * MB_IN_BYTES ) {
			wp_delete_file( $file );
			return $fail(
				'' !== $drive
					? __( 'No usable picture: the Drive file must be shared with "anyone with the link", and be a JPG, PNG or WebP of max. 8 MB.', 'calendar-plugin' )
					: __( 'The address does not lead to a JPG, PNG or WebP picture of max. 8 MB.', 'calendar-plugin' )
			);
		}
		$title = get_the_title( $event );
		$att   = media_handle_sideload(
			array(
				'name'     => sanitize_title( $title ) . '.' . $ext,
				'tmp_name' => $file,
			),
			$page,
			$title
		);
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $file );
			/* translators: %s: error message */
			return $fail( sprintf( __( 'Picture could not be saved: %s', 'calendar-plugin' ), $att->get_error_message() ) );
		}
		update_post_meta( $att, '_calp_image_source', $key );
		delete_post_meta( $event, '_calp_image_error' );
		return (int) $att;
	}

	// ── Info box ─────────────────────────────────────────────────────

	/** [calp_event_info id="…"] – without id: the event this page belongs to. */
	public static function shortcode( $atts ): string {
		$atts  = shortcode_atts( array( 'id' => 0 ), $atts, 'calp_event_info' );
		$event = (int) $atts['id'] ?: (int) get_post_meta( (int) get_the_ID(), '_calp_event', true );
		$post  = $event ? get_post( $event ) : null;
		wp_enqueue_style( 'calp' );
		if ( ! $post || Events::TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return current_user_can( 'edit_posts' ) ? '<p class="calp-empty">' . esc_html__( 'No event linked to this box.', 'calendar-plugin' ) . '</p>' : '';
		}
		$d      = Events::data( $event );
		$f      = $d['fields'];
		$status = $d['status'];
		$past   = $d['end'] < time();

		$rows = array();
		$date = esc_html( Events::date_text( $event, $d ) );
		if ( '' !== ( $f['display'] ?? '' ) && $d['start'] ) {
			$date .= '<br><small>' . esc_html( Format::range( $d['start'], $d['end'], $d['all_day'] ) ) . '</small>';
		}
		$rows[ __( 'Date', 'calendar-plugin' ) ] = $date;
		if ( '' !== $d['location'] || ! empty( $f['country'] ) ) {
			$flag  = ! empty( $f['country'] ) ? '<span class="calp-event__flag" role="img" aria-label="' . esc_attr( Format::country_name( $f['country'] ) ) . '">' . esc_html( Format::flag( $f['country'] ) ) . '</span> ' : '';
			$place = '' !== $d['location'] ? esc_html( $d['location'] ) : esc_html( Format::country_name( $f['country'] ) );
			$geo   = Geo::of( $event );
			if ( $geo ) {
				$place .= ' · <a href="' . esc_url( Geo::osm_link( $geo['lat'], $geo['lng'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'calendar-plugin' ) . '</a>';
			}
			$rows[ __( 'Location', 'calendar-plugin' ) ] = $flag . $place;
		}
		$rows[ __( 'Category', 'calendar-plugin' ) ] = '<span class="calp-event__cat" style="--calp-cat:' . esc_attr( Events::category_color( $d['category'] ) ) . '">' . esc_html( Events::category_name( $d['category'] ) ) . '</span>';

		$notice = '';
		if ( $d['removed'] ) {
			$notice = __( 'This event was cancelled or removed from the calendar.', 'calendar-plugin' );
		} elseif ( $status ) {
			$notice = Parser::status_labels()[ $status ] ?? $status;
		} elseif ( $past ) {
			$notice = __( 'This event has taken place.', 'calendar-plugin' );
		}

		$buttons = '';
		if ( ! empty( $f['registration'] ) && 'cancelled' !== $status && ! $d['removed'] && ! $past ) {
			$buttons .= self::button( $f['registration'], __( 'Register', 'calendar-plugin' ), false );
		}
		$here = untrailingslashit( (string) get_permalink() );
		if ( ! empty( $f['info'] ) && untrailingslashit( $f['info'] ) !== $here ) {
			$buttons .= self::button( $f['info'], __( 'Event website', 'calendar-plugin' ), '' !== $buttons );
		}

		$img = (int) get_post_thumbnail_id();
		$out = '<div class="calp-eventinfo' . ( $img ? ' has-image' : '' ) . ( $status ? ' is-' . esc_attr( $status ) : '' ) . ( $d['removed'] ? ' is-removed' : '' ) . '" style="--calp-cat:' . esc_attr( Events::category_color( $d['category'] ) ) . '">';
		if ( $img ) {
			$out .= '<figure class="calp-eventinfo__image">' . wp_get_attachment_image( $img, 'large' ) . '</figure>';
		}
		$out .= '<div class="calp-eventinfo__body">';
		if ( '' !== $notice ) {
			$out .= '<p class="calp-eventinfo__notice">' . esc_html( $notice ) . '</p>';
		}
		$out .= '<dl class="calp-eventinfo__list">';
		foreach ( $rows as $label => $value ) {
			$out .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
		}
		$out .= '</dl>';
		if ( '' !== $buttons ) {
			$out .= '<p class="calp-eventinfo__actions">' . $buttons . '</p>';
		}
		return $out . '</div></div>';
	}

	private static function button( string $url, string $text, bool $secondary ): string {
		$external = ! str_starts_with( $url, home_url() );
		return sprintf(
			'<a class="calp-btn%s" href="%s"%s>%s</a>',
			$secondary ? ' calp-btn--secondary' : '',
			esc_url( $url ),
			$external ? ' target="_blank" rel="noopener"' : '',
			esc_html( $text )
		);
	}

	// ── Admin ────────────────────────────────────────────────────────

	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( Events::TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $actions;
		}
		$page = self::page_id( $post->ID );
		if ( $page ) {
			$actions['calp_page'] = '<a href="' . esc_url( (string) get_edit_post_link( $page ) ) . '">' . esc_html__( 'Edit page', 'calendar-plugin' ) . '</a>';
		} elseif ( current_user_can( 'publish_pages' ) ) {
			$url                  = wp_nonce_url( admin_url( 'admin-post.php?action=calp_event_page&event=' . $post->ID ), 'calp_event_page_' . $post->ID );
			$actions['calp_page'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Create page', 'calendar-plugin' ) . '</a>';
		}
		return $actions;
	}

	public static function create_button(): void {
		$event = absint( $_GET['event'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'calp_event_page_' . $event );
		if ( ! current_user_can( 'publish_pages' ) ) {
			wp_die( '', 403 );
		}
		$page = self::page_id( $event ) ?: self::create( $event, get_current_user_id() );
		if ( is_wp_error( $page ) ) {
			wp_die( esc_html( $page->get_error_message() ) );
		}
		update_post_meta( $event, '_calp_page_auto', 1 );
		self::picture( $event, $page );
		wp_safe_redirect( admin_url( 'post.php?post=' . $page . '&action=edit' ) );
		exit;
	}

	/** Column "Page" in the event list. */
	public static function column( int $event ): string {
		$page = self::page_id( $event );
		if ( ! $page ) {
			return get_post_meta( $event, '_calp_page_auto', true ) ? '<span style="color:#996800">' . esc_html__( 'deleted', 'calendar-plugin' ) . '</span>' : '–';
		}
		$html = 'publish' === get_post_status( $page )
			? '<a href="' . esc_url( (string) get_permalink( $page ) ) . '">' . esc_html__( 'view', 'calendar-plugin' ) . '</a>'
			: esc_html__( 'draft', 'calendar-plugin' );
		$err = (string) get_post_meta( $event, '_calp_image_error', true );
		if ( '' !== $err ) {
			$html .= '<br><span style="color:#b32d2e">⚠ ' . esc_html( $err ) . '</span>';
		}
		return $html;
	}
}
