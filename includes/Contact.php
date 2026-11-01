<?php
/**
 * General contact form: [calp_contact_form]. Messages are mailed to the contact recipients
 * (Settings › Review) and not stored on the website.
 */

namespace CalendarPlugin;

final class Contact {

	/** [calp_contact_form] */
	public static function shortcode(): string {
		wp_enqueue_style( 'calp' );
		$sent  = 'contact' === sanitize_key( $_GET['calp_sent'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$error = sanitize_text_field( wp_unslash( $_GET['calp_error'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$time  = (string) time();
		ob_start();
		?>
		<div class="calp-form calp-contact-form" id="calp-contact-form">
			<?php if ( $sent ) : ?>
				<p class="calp-notice calp-notice--ok"><?php esc_html_e( 'Thank you! Your message was sent. We will get back to you by e-mail.', 'calendar-plugin' ); ?></p>
			<?php elseif ( '' !== $error ) : ?>
				<p class="calp-notice calp-notice--error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( remove_query_arg( array( 'calp_sent', 'calp_error' ) ) ); ?>#calp-contact-form">
				<input type="hidden" name="calp_form" value="contact">
				<input type="hidden" name="calp_t" value="<?php echo esc_attr( $time . '.' . self::sign( $time ) ); ?>">
				<?php echo Plugin::form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				<div class="calp-form__row">
					<p><label><?php esc_html_e( 'Your name', 'calendar-plugin' ); ?> *<br><input type="text" name="calp[name]" required maxlength="80" autocomplete="name"></label></p>
					<p><label><?php esc_html_e( 'Your e-mail', 'calendar-plugin' ); ?> *<br><input type="email" name="calp[email]" required autocomplete="email"></label></p>
				</div>
				<p><label><?php esc_html_e( 'Subject', 'calendar-plugin' ); ?><br><input type="text" name="calp[subject]" maxlength="150"></label></p>
				<p><label><?php esc_html_e( 'Message', 'calendar-plugin' ); ?> *<br><textarea name="calp[message]" rows="7" required maxlength="5000"></textarea></label></p>
				<p><label><input type="checkbox" name="calp[consent]" value="1" required> <?php esc_html_e( 'I agree that my details are used to answer my message. They are only sent by e-mail and not stored on the website.', 'calendar-plugin' ); ?></label></p>
				<p><button type="submit" class="calp-btn"><?php esc_html_e( 'Send message', 'calendar-plugin' ); ?></button></p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function sign( string $time ): string {
		return substr( hash_hmac( 'sha256', 'contact|' . $time, wp_salt( 'nonce' ) ), 0, 16 );
	}

	public static function handle_submission(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'contact' !== ( $_POST['calp_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$back = Plugin::back_url();
		$done = static function ( string $arg, string $value ) use ( $back ) {
			wp_safe_redirect( add_query_arg( $arg, rawurlencode( $value ), $back ) . '#calp-contact-form' );
			exit;
		};
		// phpcs:disable WordPress.Security.NonceVerification -- public form, protected by time token and honeypot.
		$token = explode( '.', (string) ( $_POST['calp_t'] ?? '' ) );
		$in    = wp_unslash( (array) ( $_POST['calp'] ?? array() ) );
		if ( Plugin::is_bot() || 2 !== count( $token ) || ! hash_equals( self::sign( $token[0] ), $token[1] ) || time() - (int) $token[0] < 5 ) {
			$done( 'calp_sent', 'contact' );
		}
		// phpcs:enable
		if ( Plugin::rate_limited( 'contact', 5 ) ) {
			$done( 'calp_error', __( 'Too many messages from your connection. Please try again later.', 'calendar-plugin' ) );
		}
		$name    = sanitize_text_field( $in['name'] ?? '' );
		$email   = sanitize_email( $in['email'] ?? '' );
		$subject = sanitize_text_field( $in['subject'] ?? '' );
		$message = sanitize_textarea_field( $in['message'] ?? '' );
		if ( '' === $name || ! is_email( $email ) || '' === trim( $message ) || empty( $in['consent'] ) ) {
			$done( 'calp_error', __( 'Please fill in all required fields.', 'calendar-plugin' ) );
		}

		$site    = get_bloginfo( 'name' );
		$title   = sprintf( /* translators: 1: site name, 2: subject or sender name */ __( '[%1$s] Contact: %2$s', 'calendar-plugin' ), $site, '' !== $subject ? $subject : $name );
		$body    = implode(
			"\n",
			array(
				sprintf( /* translators: %s: page address */ __( 'Message sent with the contact form on %s', 'calendar-plugin' ), $back ),
				'',
				__( 'Name:', 'calendar-plugin' ) . ' ' . $name,
				__( 'E-mail:', 'calendar-plugin' ) . ' ' . $email,
				'' !== $subject ? __( 'Subject:', 'calendar-plugin' ) . ' ' . $subject : null,
				'',
				$message,
				'',
				'-- ',
				__( 'Reply to this mail to answer the sender directly.', 'calendar-plugin' ),
			)
		);
		$body    = (string) preg_replace( "/\n{3,}/", "\n\n", $body );
		$headers = array( 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>', '"' ), '', $name ) . ' <' . $email . '>' );

		$ok = false;
		foreach ( Settings::contact_recipients() as $to ) {
			$ok = wp_mail( $to, $title, $body, $headers ) || $ok;
		}
		if ( ! $ok ) {
			$done( 'calp_error', __( 'Sending failed. Please try again later.', 'calendar-plugin' ) );
		}
		Plugin::count_submission( 'contact' );
		$done( 'calp_sent', 'contact' );
	}
}
