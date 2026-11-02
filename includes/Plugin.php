<?php
/**
 * Central hooks: post types, shortcodes, cron, assets.
 */

namespace CalendarPlugin;

use CalendarPlugin\Events\Events;
use CalendarPlugin\Events\Form;
use CalendarPlugin\Events\Pages;
use CalendarPlugin\Events\Renderer;
use CalendarPlugin\Events\Sync;
use CalendarPlugin\Map\Map;

final class Plugin {

	public const CRON_HOOK = 'calp_sync';

	public static function start(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
		add_action( 'init', array( Events::class, 'register' ) );
		add_action( 'init', array( Map::class, 'register' ) );
		add_action( 'init', array( Countries::class, 'register' ) );
		add_action( 'init', array( Articles::class, 'register' ) );
		add_action( 'init', array( self::class, 'assets_and_shortcodes' ) );
		add_action( 'rest_api_init', array( Map::class, 'routes' ) );
		add_action( 'rest_api_init', array( Events::class, 'routes' ) );
		add_action( self::CRON_HOOK, array( Sync::class, 'when_due' ) );
		add_action( 'template_redirect', array( Review::class, 'handle_link' ), 1 );
		add_action( 'template_redirect', array( Form::class, 'handle' ), 1 );
		add_action( 'template_redirect', array( Articles::class, 'handle_submission' ), 1 );
		add_action( 'template_redirect', array( Contact::class, 'handle_submission' ), 1 );
		add_action( 'admin_post_nopriv_calp_cron', array( Sync::class, 'cron_url' ) );
		add_action( 'admin_post_calp_cron', array( Sync::class, 'cron_url' ) );

		Review::start();
		Map::start();
		Events::start();

		if ( is_admin() ) {
			Settings::admin();
			Events::admin();
			Pages::admin();
			Map::admin();
			Countries::admin();
			Articles::admin();
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'calendar-plugin', false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}

	public static function url( string $path ): string {
		return plugins_url( $path, FILE );
	}

	public static function assets_and_shortcodes(): void {
		wp_register_style( 'calp', self::url( 'assets/hub.css' ), array(), VERSION );
		wp_register_script( 'calp-explorer', self::url( 'assets/explorer.js' ), array(), VERSION, array( 'in_footer' => true ) );
		wp_register_script( 'calp-filter', self::url( 'assets/filter.js' ), array(), VERSION, array( 'in_footer' => true ) );
		wp_register_script( 'calp-articles', self::url( 'assets/articles.js' ), array(), VERSION, array( 'in_footer' => true ) );
		wp_register_script( 'calp-event-form', self::url( 'assets/event-form.js' ), array(), VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'calp-event-form',
			'calpEventForm',
			array(
				'places' => rest_url( 'calp/v1/places-search' ),
				'i18n'   => array(
					'searching' => __( 'Searching …', 'calendar-plugin' ),
					'none'      => __( 'Nothing found. Try another wording, e.g. town and country.', 'calendar-plugin' ),
					'choose'    => __( 'Choose', 'calendar-plugin' ),
					'chosen'    => __( 'Position set:', 'calendar-plugin' ),
				),
			)
		);
		Map::register_assets();

		add_shortcode( 'calp_events', array( Renderer::class, 'shortcode' ) );
		add_shortcode( 'calp_event_form', array( Form::class, 'shortcode' ) );
		add_shortcode( 'calp_map', array( Map::class, 'shortcode' ) );
		add_shortcode( 'calp_countries', array( Countries::class, 'shortcode' ) );
		add_shortcode( 'calp_articles', array( Articles::class, 'shortcode_list' ) );
		add_shortcode( 'calp_article_form', array( Articles::class, 'shortcode_form' ) );
		add_shortcode( 'calp_event_info', array( Pages::class, 'shortcode' ) );
		add_shortcode( 'calp_contact_form', array( Contact::class, 'shortcode' ) );
		add_shortcode( 'calp_explorer', array( Explorer::class, 'shortcode' ) );
	}

	/**
	 * Creates an option only if it does not exist yet – atomically via INSERT IGNORE.
	 *
	 * @return bool true if this call created the option.
	 */
	public static function once( string $name, string $value ): bool {
		global $wpdb;
		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$name,
				$value
			)
		);
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $ok;
	}

	/**
	 * Hidden fields every public form needs: where to come back to, and a honeypot.
	 * The honeypot has no name that browsers or password managers would autofill
	 * (Firefox for Android filled the former "website" field, so real submissions were dropped as spam).
	 */
	public static function form_fields(): string {
		return '<input type="hidden" name="calp_back" value="' . esc_attr( remove_query_arg( array( 'calp_sent', 'calp_error' ) ) ) . '">'
			. '<p class="calp-hp" aria-hidden="true"><label>Leave this field empty <input type="text" name="calp_hp_x7" value="" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore></label></p>';
	}

	public static function is_bot(): bool {
		return '' !== trim( (string) wp_unslash( $_POST['calp_hp_x7'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Page to return to after a form was sent. The form tells us (calp_back); wp_get_referer() is no help
	 * because it is empty when the referrer equals the current address – which it always does here.
	 */
	public static function back_url(): string {
		$back = wp_validate_redirect( (string) wp_unslash( $_POST['calp_back'] ?? '' ), '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $back ) {
			$back = wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) );
		}
		return remove_query_arg( array( 'calp_sent', 'calp_error' ), $back );
	}

	/** Simple spam protection shared by all public forms: max. $max valid submissions per IP and hour. */
	public static function rate_limited( string $area, int $max = 5 ): bool {
		$key = 'calp_rate_' . $area . '_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return (int) get_transient( $key ) >= $max;
	}

	public static function count_submission( string $area ): void {
		$key = 'calp_rate_' . $area . '_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	public static function activate(): void {
		Events::register();
		Map::register();
		Countries::register();
		Articles::register();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
		if ( ! get_option( Settings::OPTION_CRON_KEY ) ) {
			update_option( Settings::OPTION_CRON_KEY, wp_generate_password( 32, false ), false );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules();
	}
}
