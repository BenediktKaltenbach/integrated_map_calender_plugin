<?php
/**
 * Review by mail for all submissions (events, places, articles).
 *
 * A submission is stored as "pending" and mailed to every reviewer of its area. Each reviewer
 * gets personal links (approve / reject / edit) signed with a per-submission secret, so they
 * work without a WordPress login. GET only shows a confirmation page with a button – mail
 * security scanners open links automatically. Once someone decides, the secret is deleted
 * (all links stop working) and the other reviewers get a short note.
 *
 * Areas register a handler with label, permalink and what to show in the mail.
 */

namespace CalendarPlugin;

final class Review {

	/** @var array<string, array{post_type: string, label: string, details: callable, approved?: callable}> */
	private static array $areas = array();

	public static function start(): void {
		add_filter( 'wp_mail_from_name', array( self::class, 'from_name' ) );
	}

	public static function from_name( string $name ): string {
		$custom = (string) Settings::get()['mail_from_name'];
		return '' !== $custom ? $custom : $name;
	}

	/**
	 * @param string   $area      Key from Settings::review_areas().
	 * @param string   $post_type Post type of the submissions.
	 * @param string   $label     Singular label used in mails, e.g. "event".
	 * @param callable $details   fn(int $id): array<string, string> – rows for mail and confirmation page (plain text).
	 * @param callable $approved  fn(int $id): void – after publishing (thank-you mail etc.).
	 */
	public static function area( string $area, string $post_type, string $label, callable $details, ?callable $approved = null ): void {
		self::$areas[ $area ] = compact( 'post_type', 'label', 'details', 'approved' );
	}

	private static function area_of( int $id ): ?string {
		$type = get_post_type( $id );
		foreach ( self::$areas as $key => $a ) {
			if ( $a['post_type'] === $type ) {
				return $key;
			}
		}
		return null;
	}

	/** New pending submission: create the secret and mail all reviewers of the area. */
	public static function submit( int $id, string $area ): void {
		if ( ! isset( self::$areas[ $area ] ) ) {
			return;
		}
		update_post_meta( $id, '_calp_review_secret', wp_generate_password( 40, false ) );

		$a       = self::$areas[ $area ];
		$title   = get_post_field( 'post_title', $id );
		$rows    = ( $a['details'] )( $id );
		$name    = (string) get_post_meta( $id, '_calp_submitter_name', true );
		$email   = (string) get_post_meta( $id, '_calp_submitter_email', true );
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = sprintf( /* translators: 1: site host, 2: kind of submission, 3: title */ __( '[%1$s] New %2$s to review: %3$s', 'calendar-plugin' ), $host, $a['label'], $title );

		$headers = array();
		if ( is_email( $email ) ) {
			$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>', '"' ), '', $name ?: $email ) . ' <' . $email . '>';
		}

		$sent = 0;
		foreach ( Settings::reviewers( $area ) as $reviewer ) {
			$lines = array(
				sprintf( /* translators: 1: kind of submission, 2: site host */ __( 'A new %1$s was submitted on %2$s.', 'calendar-plugin' ), $a['label'], $host ),
				'',
			);
			foreach ( $rows as $label => $value ) {
				$lines[] = $label . ': ' . $value;
			}
			$lines[] = '';
			$lines[] = __( 'Submitted by', 'calendar-plugin' ) . ': ' . ( $name ?: '–' ) . ( $email ? ' <' . $email . '>' : '' );
			$lines[] = '';
			$lines[] = __( 'Approve', 'calendar-plugin' ) . ': ' . self::link( $id, 'approve', $reviewer );
			$lines[] = __( 'Edit', 'calendar-plugin' ) . ':    ' . self::link( $id, 'edit', $reviewer );
			$lines[] = __( 'Reject', 'calendar-plugin' ) . ':  ' . self::link( $id, 'reject', $reviewer );
			$lines[] = '';
			$lines[] = __( 'All reviewers got this mail. The first decision counts; the others will be informed.', 'calendar-plugin' );
			if ( wp_mail( $reviewer, $subject, implode( "\n", $lines ), $headers ) ) {
				++$sent;
			}
		}
		update_post_meta( $id, '_calp_review_mail', $sent );
	}

	/** Personal link for one reviewer: the signature binds post, action and reviewer to the secret. */
	public static function link( int $id, string $action, string $reviewer ): string {
		return add_query_arg(
			array(
				'calp_review' => $id,
				'do'          => $action,
				'r'           => rawurlencode( $reviewer ),
				'sig'         => self::signature( $id, $action, $reviewer ),
			),
			home_url( '/' )
		);
	}

	private static function signature( int $id, string $action, string $reviewer ): string {
		$secret = (string) get_post_meta( $id, '_calp_review_secret', true );
		if ( '' === $secret ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', $id . '|' . $action . '|' . strtolower( $reviewer ), $secret . wp_salt( 'auth' ) ), 0, 32 );
	}

	/** Mail link: GET shows a confirmation page, POST decides. */
	public static function handle_link(): void {
		if ( ! isset( $_GET['calp_review'], $_GET['do'], $_GET['r'], $_GET['sig'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		$id       = absint( $_GET['calp_review'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$action   = sanitize_key( $_GET['do'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$reviewer = sanitize_email( rawurldecode( wp_unslash( $_GET['r'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$sig      = (string) wp_unslash( $_GET['sig'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$area     = self::area_of( $id );
		$title    = __( 'Review', 'calendar-plugin' );

		$expected = self::signature( $id, $action, $reviewer );
		if ( ! $area || '' === $expected || ! hash_equals( $expected, $sig ) || ! in_array( $action, array( 'approve', 'reject', 'edit' ), true ) ) {
			$who = (string) get_post_meta( $id, '_calp_review_by', true );
			wp_die(
				esc_html( $who ? sprintf( /* translators: %s: reviewer address */ __( 'This submission was already handled by %s.', 'calendar-plugin' ), $who ) : __( 'This link is invalid or the submission was already handled.', 'calendar-plugin' ) ),
				esc_html( $title ),
				array( 'response' => 403 )
			);
		}

		$a      = self::$areas[ $area ];
		$post   = get_post( $id );
		$isPost = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );

		if ( $isPost && 'edit' === $action ) {
			$new_title   = sanitize_text_field( wp_unslash( $_POST['calp_title'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$new_content = wp_kses_post( wp_unslash( $_POST['calp_content'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' !== $new_title ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_title'   => $new_title,
						'post_content' => $new_content,
					)
				);
			}
			$action = ! empty( $_POST['calp_and_approve'] ) ? 'approve' : 'edit'; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'edit' === $action ) {
				wp_die( '<h1>' . esc_html__( 'Saved', 'calendar-plugin' ) . '</h1><p>' . esc_html__( 'Your changes were saved. The submission is still waiting for approval; you can use the approve link from the mail.', 'calendar-plugin' ) . '</p>', esc_html( $title ), array( 'response' => 200 ) );
			}
		}

		if ( $isPost && in_array( $action, array( 'approve', 'reject' ), true ) ) {
			self::decide( $id, $action, $reviewer );
			if ( 'approve' === $action ) {
				$link = get_permalink( $id );
				$html = '<h1>' . esc_html__( 'Approved', 'calendar-plugin' ) . '</h1><p>' . esc_html( sprintf( /* translators: %s: title */ __( '"%s" is now published.', 'calendar-plugin' ), get_the_title( $id ) ) ) . '</p>'
					. ( $link && is_post_type_viewable( $a['post_type'] ) ? '<p><a class="button" href="' . esc_url( $link ) . '">' . esc_html__( 'View', 'calendar-plugin' ) . '</a></p>' : '' );
			} else {
				$html = '<h1>' . esc_html__( 'Rejected', 'calendar-plugin' ) . '</h1><p>' . esc_html__( 'The submission was moved to the trash; the submitter’s e-mail address was deleted.', 'calendar-plugin' ) . '</p>';
			}
			wp_die( $html, esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		// Confirmation page.
		$rows = '';
		foreach ( ( $a['details'] )( $id ) as $label => $value ) {
			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding-right:1em">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		$already = 'publish' === $post->post_status ? '<p><em>' . esc_html__( 'This submission is already published.', 'calendar-plugin' ) . '</em></p>' : '';

		if ( 'edit' === $action ) {
			$html = '<h1>' . esc_html__( 'Edit submission', 'calendar-plugin' ) . '</h1>' . $already
				. '<form method="post"><p><label><strong>' . esc_html__( 'Title', 'calendar-plugin' ) . '</strong><br><input type="text" name="calp_title" value="' . esc_attr( $post->post_title ) . '" style="width:100%"></label></p>'
				. '<p><label><strong>' . esc_html__( 'Text', 'calendar-plugin' ) . '</strong><br><textarea name="calp_content" rows="12" style="width:100%">' . esc_textarea( $post->post_content ) . '</textarea></label></p>'
				. '<table>' . $rows . '</table>'
				. '<p><button type="submit" class="button" name="calp_and_approve" value="1">' . esc_html__( 'Save and approve', 'calendar-plugin' ) . '</button> '
				. '<button type="submit" class="button">' . esc_html__( 'Only save', 'calendar-plugin' ) . '</button></p></form>';
		} else {
			$question = 'approve' === $action ? __( 'Approve this submission?', 'calendar-plugin' ) : __( 'Reject this submission?', 'calendar-plugin' );
			$button   = 'approve' === $action ? __( 'Yes, approve', 'calendar-plugin' ) : __( 'Yes, reject', 'calendar-plugin' );
			$html     = '<h1>' . esc_html( $question ) . '</h1>' . $already
				. '<h2>' . esc_html( $post->post_title ) . '</h2><table>' . $rows . '</table>'
				. ( '' !== trim( $post->post_content ) ? '<div style="margin-top:1em;padding:1em;background:#f6f7f7">' . wp_kses_post( wpautop( $post->post_content ) ) . '</div>' : '' )
				. '<form method="post"><p><button type="submit" class="button">' . esc_html( $button ) . '</button></p></form>';
		}
		wp_die( $html, esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Approve or reject (from a mail link or the admin), then inform the other reviewers. */
	public static function decide( int $id, string $action, string $by ): void {
		$area = self::area_of( $id );
		if ( ! $area ) {
			return;
		}
		$a = self::$areas[ $area ];
		delete_post_meta( $id, '_calp_review_secret' );
		update_post_meta( $id, '_calp_review_by', $by );
		update_post_meta( $id, '_calp_review_time', time() );

		if ( 'approve' === $action ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
			self::thank_submitter( $id, $a );
			if ( $a['approved'] ) {
				( $a['approved'] )( $id );
			}
		} else {
			delete_post_meta( $id, '_calp_submitter_email' );
			wp_trash_post( $id );
		}

		$verb = 'approve' === $action ? __( 'approved', 'calendar-plugin' ) : __( 'rejected', 'calendar-plugin' );
		foreach ( Settings::reviewers( $area ) as $reviewer ) {
			if ( 0 === strcasecmp( $reviewer, $by ) ) {
				continue;
			}
			wp_mail(
				$reviewer,
				sprintf( /* translators: 1: title, 2: approved/rejected */ __( '"%1$s" was %2$s', 'calendar-plugin' ), get_the_title( $id ), $verb ),
				sprintf( /* translators: 1: kind, 2: title, 3: approved/rejected, 4: reviewer */ __( "The %1\$s \"%2\$s\" was %3\$s by %4\$s.\nNo action needed – the links in the earlier mail no longer work.", 'calendar-plugin' ), $a['label'], get_the_title( $id ), $verb, $by )
			);
		}
	}

	/** Thank-you mail once published; the submitter's address is deleted afterwards. */
	private static function thank_submitter( int $id, array $a ): void {
		$email = (string) get_post_meta( $id, '_calp_submitter_email', true );
		if ( is_email( $email ) ) {
			$name = (string) get_post_meta( $id, '_calp_submitter_name', true );
			$link = is_post_type_viewable( $a['post_type'] ) ? get_permalink( $id ) : home_url( '/' );
			wp_mail(
				$email,
				sprintf( /* translators: %s: title */ __( 'Your submission "%s" is online', 'calendar-plugin' ), get_the_title( $id ) ),
				sprintf( /* translators: 1: name, 2: title, 3: link, 4: site name */ __( "Hello %1\$s,\n\nthank you for \"%2\$s\". It is now published:\n%3\$s\n\nBest regards\n%4\$s", 'calendar-plugin' ), $name ?: __( 'there', 'calendar-plugin' ), get_the_title( $id ), $link, self::from_name( get_bloginfo( 'name' ) ) )
			);
		}
		delete_post_meta( $id, '_calp_submitter_email' );
	}

	/** Admin row action / status column helper. */
	public static function status_text( int $id ): string {
		$by = (string) get_post_meta( $id, '_calp_review_by', true );
		if ( $by ) {
			return sprintf( /* translators: %s: reviewer */ __( 'decided by %s', 'calendar-plugin' ), $by );
		}
		if ( 'pending' === get_post_status( $id ) ) {
			$n = (int) get_post_meta( $id, '_calp_review_mail', true );
			return sprintf( /* translators: %d: number of reviewers */ _n( 'waiting – mailed to %d reviewer', 'waiting – mailed to %d reviewers', $n, 'calendar-plugin' ), $n );
		}
		return '';
	}

	/** Admin: "Approve" on pending posts of a review area (row action in the post list). */
	public static function admin_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'pending' === $post->post_status && self::area_of( $post->ID ) && current_user_can( 'publish_post', $post->ID ) ) {
			$url                   = wp_nonce_url( admin_url( 'admin-post.php?action=calp_approve&post=' . $post->ID ), 'calp_approve_' . $post->ID );
			$actions['calp_approve'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Approve', 'calendar-plugin' ) . '</a>';
		}
		return $actions;
	}

	public static function admin_approve(): void {
		$id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'calp_approve_' . $id );
		if ( ! current_user_can( 'publish_post', $id ) ) {
			wp_die( '', 403 );
		}
		self::decide( $id, 'approve', wp_get_current_user()->user_email );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public static function admin(): void {
		add_filter( 'post_row_actions', array( self::class, 'admin_row_actions' ), 10, 2 );
		add_action( 'admin_post_calp_approve', array( self::class, 'admin_approve' ) );
	}
}
