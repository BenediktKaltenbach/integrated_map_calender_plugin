<?php
/**
 * Public form to suggest an event. A suggestion becomes a pending local event and goes
 * to the reviewers of the "events" area; once approved it appears in lists and on the map.
 *
 * Spam protection without CAPTCHA: honeypot field, minimum fill-in time, max. 5 per IP and hour.
 */

namespace CalendarPlugin\Events;

use CalendarPlugin\Plugin;
use CalendarPlugin\Review;
use CalendarPlugin\Settings;

final class Form {

	private const MIN_SECONDS = 5;

	public static function shortcode(): string {
		if ( empty( Settings::get()['event_form'] ) ) {
			return '';
		}
		wp_enqueue_style( 'calp' );
		wp_enqueue_script( 'calp-event-form' );

		$sent  = sanitize_key( $_GET['calp_sent'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$error = sanitize_text_field( wp_unslash( $_GET['calp_error'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$open  = 'event' === $sent || '' !== $error;
		$time  = (string) time();

		ob_start();
		?>
		<details class="calp-form calp-event-form" id="calp-event-form"<?php echo $open ? ' open' : ''; ?>>
			<summary><span class="calp-form__icon" aria-hidden="true">📅</span> <?php esc_html_e( 'Suggest an event', 'calendar-plugin' ); ?> <small><?php esc_html_e( 'It will be checked before it is published.', 'calendar-plugin' ); ?></small></summary>
			<?php if ( 'event' === $sent ) : ?>
				<p class="calp-notice calp-notice--ok"><?php esc_html_e( 'Thank you! Your suggestion was sent to the reviewers.', 'calendar-plugin' ); ?></p>
			<?php elseif ( '' !== $error ) : ?>
				<p class="calp-notice calp-notice--error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( remove_query_arg( array( 'calp_sent', 'calp_error' ) ) ); ?>#calp-event-form">
				<input type="hidden" name="calp_form" value="event">
				<input type="hidden" name="calp_t" value="<?php echo esc_attr( $time . '.' . self::sign( $time ) ); ?>">
				<p class="calp-hp" aria-hidden="true"><label>Website <input type="text" name="calp_website" tabindex="-1" autocomplete="off"></label></p>
				<p><label><?php esc_html_e( 'Title', 'calendar-plugin' ); ?> *<br><input type="text" name="calp[title]" required maxlength="120"></label></p>
				<p><label><?php esc_html_e( 'Category', 'calendar-plugin' ); ?><br><select name="calp[category]">
					<?php foreach ( Settings::event_categories() as $slug => $c ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
				<div class="calp-form__row">
					<p><label><?php esc_html_e( 'From', 'calendar-plugin' ); ?> *<br><input type="date" name="calp[start_date]" required></label></p>
					<p><label><?php esc_html_e( 'To', 'calendar-plugin' ); ?><br><input type="date" name="calp[end_date]"></label></p>
					<p><label><?php esc_html_e( 'Start time (optional)', 'calendar-plugin' ); ?><br><input type="time" name="calp[start_time]"></label></p>
				</div>
				<div class="calp-place-search" data-target="calp-form-geo">
					<p><label><?php esc_html_e( 'Location', 'calendar-plugin' ); ?> *<br><input type="text" name="calp[location]" class="calp-place-q" required maxlength="200"></label>
					<button type="button" class="calp-btn calp-btn--secondary calp-place-btn"><?php esc_html_e( 'Find on map', 'calendar-plugin' ); ?></button></p>
					<div class="calp-place-hits"></div>
					<input type="hidden" name="calp[geo]" id="calp-form-geo">
				</div>
				<div class="calp-form__row">
					<p><label><?php esc_html_e( 'Country (e.g. CZ)', 'calendar-plugin' ); ?><br><input type="text" name="calp[country]" maxlength="2" size="3"></label></p>
					<p><label><?php esc_html_e( 'Info link', 'calendar-plugin' ); ?><br><input type="url" name="calp[info]" placeholder="https://"></label></p>
					<p><label><?php esc_html_e( 'Registration link', 'calendar-plugin' ); ?><br><input type="url" name="calp[registration]" placeholder="https://"></label></p>
				</div>
				<p><label><?php esc_html_e( 'Description', 'calendar-plugin' ); ?><br><textarea name="calp[description]" rows="4" maxlength="3000"></textarea></label></p>
				<div class="calp-form__row">
					<p><label><?php esc_html_e( 'Your name', 'calendar-plugin' ); ?> *<br><input type="text" name="calp[name]" required maxlength="80"></label></p>
					<p><label><?php esc_html_e( 'Your e-mail', 'calendar-plugin' ); ?> *<br><input type="email" name="calp[email]" required></label></p>
				</div>
				<p><label><input type="checkbox" name="calp[consent]" value="1" required> <?php esc_html_e( 'I agree that my details are stored to process this suggestion. My e-mail address is only used for questions and a short note once the event is online, then deleted.', 'calendar-plugin' ); ?></label></p>
				<p><button type="submit" class="calp-btn"><?php esc_html_e( 'Send suggestion', 'calendar-plugin' ); ?></button></p>
			</form>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	private static function sign( string $time ): string {
		return substr( hash_hmac( 'sha256', 'event|' . $time, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/** Processes the form on template_redirect, then redirects (no double submit on reload). */
	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'event' !== ( $_POST['calp_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$back = remove_query_arg( array( 'calp_sent', 'calp_error' ), wp_get_referer() ?: home_url( '/' ) );
		$fail = static function ( string $msg ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'calp_error', rawurlencode( $msg ), $back ) . '#calp-event-form' );
			exit;
		};

		// phpcs:disable WordPress.Security.NonceVerification -- public form, protected by time token and honeypot.
		$token = explode( '.', (string) ( $_POST['calp_t'] ?? '' ) );
		$in    = wp_unslash( (array) ( $_POST['calp'] ?? array() ) );
		$bot   = ! empty( $_POST['calp_website'] ) || 2 !== count( $token ) || ! hash_equals( self::sign( $token[0] ), $token[1] )
			|| time() - (int) $token[0] < self::MIN_SECONDS;
		// phpcs:enable
		if ( $bot ) {
			// Pretend success so bots learn nothing.
			wp_safe_redirect( add_query_arg( 'calp_sent', 'event', $back ) . '#calp-event-form' );
			exit;
		}
		if ( empty( Settings::get()['event_form'] ) ) {
			$fail( __( 'Suggestions are currently closed.', 'calendar-plugin' ) );
		}
		if ( Plugin::rate_limited( 'event' ) ) {
			$fail( __( 'Too many suggestions from your connection. Please try again later.', 'calendar-plugin' ) );
		}

		$title = sanitize_text_field( $in['title'] ?? '' );
		$email = sanitize_email( $in['email'] ?? '' );
		$name  = sanitize_text_field( $in['name'] ?? '' );
		if ( '' === $title || '' === $name || ! is_email( $email ) || empty( $in['consent'] ) || '' === trim( (string) ( $in['location'] ?? '' ) ) ) {
			$fail( __( 'Please fill in all required fields.', 'calendar-plugin' ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'    => Events::TYPE,
				'post_status'  => 'pending',
				'post_title'   => mb_substr( $title, 0, 120 ),
				'post_content' => sanitize_textarea_field( $in['description'] ?? '' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$fail( __( 'Saving failed. Please try again.', 'calendar-plugin' ) );
		}
		$ok = Events::store_local( $id, $in );
		if ( is_wp_error( $ok ) ) {
			wp_delete_post( $id, true );
			$fail( $ok->get_error_message() );
		}
		$geo = Geo::parse( (string) ( $in['geo'] ?? '' ) );
		if ( $geo ) {
			update_post_meta( $id, '_calp_geo_manual', $geo );
		}
		update_post_meta( $id, '_calp_submitter_name', $name );
		update_post_meta( $id, '_calp_submitter_email', $email );
		Plugin::count_submission( 'event' );
		Review::submit( $id, 'events' );

		wp_safe_redirect( add_query_arg( 'calp_sent', 'event', $back ) . '#calp-event-form' );
		exit;
	}
}
