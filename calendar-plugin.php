<?php
/**
 * Plugin Name:       Calendar Plugin
 * Description:       Events from Google Calendars and local entries, an interactive map, member countries, article submissions – all with review by mail to a configurable list of reviewers.
 * Version:           0.3.4
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Benedikt Kaltenbach
 * License:           GPL-2.0-or-later
 * Text Domain:       calendar-plugin
 * Domain Path:       /languages
 * Update URI:        https://github.com/BenediktKaltenbach/integrated_map_calender_plugin
 *
 * Merges and generalises "DTRV-Termine" (plugin_calenders) and "My Map Plugin" (map_plugin).
 */

namespace CalendarPlugin;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.3.4';
const FILE    = __FILE__;
const REPO    = 'https://github.com/BenediktKaltenbach/integrated_map_calender_plugin/';

$autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>Calendar Plugin:</strong> the <code>vendor/</code> folder is missing. Please install the release ZIP from GitHub, not the source code.</p></div>';
		}
	);
	return;
}
require_once $autoload;

foreach ( array(
	'Plugin',
	'Settings',
	'Review',
	'Events/Format',
	'Events/Parser',
	'Events/Feed',
	'Events/Geo',
	'Events/Events',
	'Events/Sync',
	'Events/Pages',
	'Events/Renderer',
	'Events/Form',
	'Map/Map',
	'Countries',
	'Articles',
	'Contact',
	'Explorer',
) as $file ) {
	require_once __DIR__ . '/includes/' . $file . '.php';
}

Plugin::start();

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

// Updates via GitHub releases (asset calendar-plugin.zip contains vendor/).
$updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( REPO, __FILE__, 'calendar-plugin' );
$updater->getVcsApi()->enableReleaseAssets( '/calendar-plugin\.zip/' );
