<?php
/**
 * Articles (news, blog, history …): public submission form, review by mail, card lists.
 */

namespace CalendarPlugin;

final class Articles {

	public const TYPE = 'calp_article';
	public const TAX  = 'calp_article_cat';

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'        => array(
					'name'          => __( 'Articles', 'calendar-plugin' ),
					'singular_name' => __( 'Article', 'calendar-plugin' ),
					'add_new_item'  => __( 'Add article', 'calendar-plugin' ),
					'add_new'       => __( 'Add article', 'calendar-plugin' ),
					'edit_item'     => __( 'Edit article', 'calendar-plugin' ),
				),
				'public'        => true,
				'has_archive'   => false,
				'rewrite'       => array( 'slug' => 'article' ),
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-media-document',
				'menu_position' => 24,
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
			)
		);
		register_taxonomy(
			self::TAX,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Article categories', 'calendar-plugin' ),
					'singular_name' => __( 'Article category', 'calendar-plugin' ),
				),
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => array( 'slug' => 'articles' ),
			)
		);
		foreach ( (array) Settings::get()['article_categories'] as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX ) ) {
				wp_insert_term( $name, self::TAX, array( 'slug' => $slug ) );
			}
		}
		Review::area(
			'articles',
			self::TYPE,
			__( 'article', 'calendar-plugin' ),
			static function ( int $id ): array {
				$terms = wp_get_object_terms( $id, self::TAX, array( 'fields' => 'names' ) );
				return array(
					__( 'Category', 'calendar-plugin' ) => is_array( $terms ) && $terms ? implode( ', ', $terms ) : '–',
					__( 'Summary', 'calendar-plugin' )  => (string) get_post_field( 'post_excerpt', $id ) ?: '–',
					__( 'Image', 'calendar-plugin' )    => has_post_thumbnail( $id ) ? (string) get_the_post_thumbnail_url( $id, 'large' ) : '–',
				);
			}
		);
	}

	/** [calp_articles category="news" count="6"] */
	public static function shortcode_list( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'count'    => 6,
			),
			$atts,
			'calp_articles'
		);
		wp_enqueue_style( 'calp' );
		$args = array(
			'post_type'      => self::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $atts['count'] ),
		);
		if ( '' !== $atts['category'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => self::TAX,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_key', explode( ',', $atts['category'] ) ),
				),
			);
		}
		$posts = get_posts( $args );
		if ( ! $posts ) {
			return '<p class="calp-empty">' . esc_html__( 'No articles yet.', 'calendar-plugin' ) . '</p>';
		}
		$html = '<div class="calp-cards">';
		foreach ( $posts as $p ) {
			$html .= '<article class="calp-card"><a class="calp-card__link" href="' . esc_url( get_permalink( $p ) ) . '">';
			if ( has_post_thumbnail( $p ) ) {
				$html .= get_the_post_thumbnail( $p, 'medium_large', array( 'class' => 'calp-card__img' ) );
			}
			$html .= '<h3 class="calp-card__title">' . esc_html( get_the_title( $p ) ) . '</h3></a>';
			$html .= '<p class="calp-card__date">' . esc_html( get_the_date( '', $p ) ) . '</p>';
			$html .= '<p class="calp-card__text">' . esc_html( wp_trim_words( $p->post_excerpt ?: wp_strip_all_tags( $p->post_content ), 28 ) ) . '</p>';
			$html .= '<a class="calp-card__more" href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html__( 'Read more', 'calendar-plugin' ) . ' »</a></article>';
		}
		return $html . '</div>';
	}

	/** [calp_article_form] */
	public static function shortcode_form(): string {
		wp_enqueue_style( 'calp' );
		$sent  = 'article' === sanitize_key( $_GET['calp_sent'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$error = sanitize_text_field( wp_unslash( $_GET['calp_error'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$time  = (string) time();
		ob_start();
		?>
		<div class="calp-form calp-article-form" id="calp-article-form">
			<?php if ( $sent ) : ?>
				<p class="calp-notice calp-notice--ok"><?php esc_html_e( 'Thank you! Your article was sent to the editors and will be checked before it is published.', 'calendar-plugin' ); ?></p>
			<?php elseif ( '' !== $error ) : ?>
				<p class="calp-notice calp-notice--error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( remove_query_arg( array( 'calp_sent', 'calp_error' ) ) ); ?>#calp-article-form">
				<input type="hidden" name="calp_form" value="article">
				<input type="hidden" name="calp_t" value="<?php echo esc_attr( $time . '.' . self::sign( $time ) ); ?>">
				<?php echo Plugin::form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				<div class="calp-form__row">
					<p><label><?php esc_html_e( 'Your name', 'calendar-plugin' ); ?> *<br><input type="text" name="calp[name]" required maxlength="80"></label></p>
					<p><label><?php esc_html_e( 'Your e-mail', 'calendar-plugin' ); ?> *<br><input type="email" name="calp[email]" required></label></p>
				</div>
				<p><label><?php esc_html_e( 'Category', 'calendar-plugin' ); ?><br><select name="calp[category]">
					<?php foreach ( (array) Settings::get()['article_categories'] as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
				<p><label><?php esc_html_e( 'Title', 'calendar-plugin' ); ?> *<br><input type="text" name="calp[title]" required maxlength="150"></label></p>
				<p><label><?php esc_html_e( 'Summary', 'calendar-plugin' ); ?><br><textarea name="calp[summary]" rows="2" maxlength="400"></textarea></label></p>
				<p><label><?php esc_html_e( 'Text', 'calendar-plugin' ); ?> *<br><textarea name="calp[content]" rows="10" required maxlength="20000"></textarea></label></p>
				<p><label><?php esc_html_e( 'Picture (JPG, PNG or WebP, max. 8 MB)', 'calendar-plugin' ); ?><br><input type="file" name="calp_image" accept="image/jpeg,image/png,image/webp"></label></p>
				<p><label><input type="checkbox" name="calp[consent]" value="1" required> <?php esc_html_e( 'I agree that my details are stored to process this article and that I have the rights to text and picture.', 'calendar-plugin' ); ?></label></p>
				<p><button type="submit" class="calp-btn"><?php esc_html_e( 'Send for review', 'calendar-plugin' ); ?></button></p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function sign( string $time ): string {
		return substr( hash_hmac( 'sha256', 'article|' . $time, wp_salt( 'nonce' ) ), 0, 16 );
	}

	public static function handle_submission(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'article' !== ( $_POST['calp_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$back = Plugin::back_url();
		$fail = static function ( string $msg ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'calp_error', rawurlencode( $msg ), $back ) . '#calp-article-form' );
			exit;
		};
		// phpcs:disable WordPress.Security.NonceVerification -- public form, protected by time token and honeypot.
		$token = explode( '.', (string) ( $_POST['calp_t'] ?? '' ) );
		$in    = wp_unslash( (array) ( $_POST['calp'] ?? array() ) );
		if ( Plugin::is_bot() || 2 !== count( $token ) || ! hash_equals( self::sign( $token[0] ), $token[1] ) || time() - (int) $token[0] < 5 ) {
			wp_safe_redirect( add_query_arg( 'calp_sent', 'article', $back ) . '#calp-article-form' );
			exit;
		}
		// phpcs:enable
		if ( Plugin::rate_limited( 'article', 3 ) ) {
			$fail( __( 'Too many submissions from your connection. Please try again later.', 'calendar-plugin' ) );
		}
		$name  = sanitize_text_field( $in['name'] ?? '' );
		$email = sanitize_email( $in['email'] ?? '' );
		$title = sanitize_text_field( $in['title'] ?? '' );
		$text  = wp_kses_post( $in['content'] ?? '' );
		if ( '' === $name || ! is_email( $email ) || '' === $title || '' === trim( $text ) || empty( $in['consent'] ) ) {
			$fail( __( 'Please fill in all required fields.', 'calendar-plugin' ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'pending',
				'post_title'   => $title,
				'post_content' => wpautop( $text ),
				'post_excerpt' => sanitize_textarea_field( $in['summary'] ?? '' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$fail( __( 'Saving failed. Please try again.', 'calendar-plugin' ) );
		}
		$cat = sanitize_key( $in['category'] ?? '' );
		if ( isset( Settings::get()['article_categories'][ $cat ] ) ) {
			wp_set_object_terms( $id, array( $cat ), self::TAX );
		}
		update_post_meta( $id, '_calp_submitter_name', $name );
		update_post_meta( $id, '_calp_submitter_email', $email );

		if ( ! empty( $_FILES['calp_image']['name'] ) && UPLOAD_ERR_OK === (int) $_FILES['calp_image']['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$size = (int) $_FILES['calp_image']['size']; // phpcs:ignore WordPress.Security.NonceVerification
			$type = wp_check_filetype_and_ext( $_FILES['calp_image']['tmp_name'], $_FILES['calp_image']['name'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			if ( $size <= 8 * MB_IN_BYTES && in_array( $type['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				$att = media_handle_upload( 'calp_image', $id );
				if ( ! is_wp_error( $att ) ) {
					set_post_thumbnail( $id, $att );
				}
			}
		}
		Plugin::count_submission( 'article' );
		Review::submit( $id, 'articles' );
		wp_safe_redirect( add_query_arg( 'calp_sent', 'article', $back ) . '#calp-article-form' );
		exit;
	}

	public static function admin(): void {
		add_filter(
			'manage_' . self::TYPE . '_posts_columns',
			static function ( array $cols ): array {
				$cols['calp_review'] = __( 'Review', 'calendar-plugin' );
				return $cols;
			}
		);
		add_action(
			'manage_' . self::TYPE . '_posts_custom_column',
			static function ( string $col, int $id ) {
				if ( 'calp_review' === $col ) {
					echo esc_html( Review::status_text( $id ) );
				}
			},
			10,
			2
		);
	}

	/** Copies articles from the IKSA Site Kit (post type iksa_article, taxonomy iksa_article_category). */
	public static function import_from_site_kit(): string {
		$old = get_posts(
			array(
				'post_type'      => 'iksa_article',
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'posts_per_page' => -1,
			)
		);
		$n = 0;
		foreach ( $old as $p ) {
			$id = wp_insert_post(
				array(
					'post_type'    => self::TYPE,
					'post_status'  => $p->post_status,
					'post_title'   => $p->post_title,
					'post_content' => $p->post_content,
					'post_excerpt' => $p->post_excerpt,
					'post_date'    => $p->post_date,
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			$cats = wp_get_object_terms( $p->ID, 'iksa_article_category', array( 'fields' => 'slugs' ) );
			if ( is_array( $cats ) && $cats ) {
				wp_set_object_terms( $id, $cats, self::TAX );
			}
			$thumb = get_post_thumbnail_id( $p->ID );
			if ( $thumb ) {
				set_post_thumbnail( $id, $thumb );
			}
			++$n;
		}
		/* translators: %d: number */
		return sprintf( _n( '%d article imported.', '%d articles imported.', $n, 'calendar-plugin' ), $n );
	}
}
