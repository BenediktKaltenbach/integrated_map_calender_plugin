<?php
/**
 * Map and calendar in one place with tabs to switch between them.
 * Each tab has its own address (#map, #calendar), so links can open a specific view.
 */

namespace CalendarPlugin;

use CalendarPlugin\Events\Form;
use CalendarPlugin\Events\Renderer;
use CalendarPlugin\Map\Map;

final class Explorer {

	/** [calp_explorer tabs="map,calendar" default="map" category="" months="0"] */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'tabs'     => 'map,calendar',
				'default'  => 'map',
				'category' => '',
				'months'   => 0,
			),
			$atts,
			'calp_explorer'
		);
		$labels = array(
			'map'      => __( 'Map', 'calendar-plugin' ),
			'calendar' => __( 'Calendar', 'calendar-plugin' ),
		);
		$tabs   = array_values( array_intersect( array_map( 'trim', explode( ',', $atts['tabs'] ) ), array_keys( $labels ) ) );
		if ( ! $tabs ) {
			return '';
		}
		$default = in_array( $atts['default'], $tabs, true ) ? $atts['default'] : $tabs[0];
		wp_enqueue_style( 'calp' );
		wp_enqueue_script( 'calp-explorer' );

		$panels = array(
			'map'      => static fn() => Map::shortcode(),
			'calendar' => static fn() => Renderer::list(
				array(
					'categories' => array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['category'] ) ) ),
					'months'     => (int) $atts['months'],
				)
			) . Form::shortcode(),
		);

		$html = '<div class="calp-explorer" data-default="' . esc_attr( $default ) . '"><div class="calp-tabs" role="tablist">';
		foreach ( $tabs as $tab ) {
			$html .= sprintf(
				'<a href="#%1$s" role="tab" id="calp-tab-%1$s" aria-controls="calp-panel-%1$s" aria-selected="%2$s" class="calp-tab%3$s">%4$s</a>',
				esc_attr( $tab ),
				$tab === $default ? 'true' : 'false',
				$tab === $default ? ' is-active' : '',
				esc_html( $labels[ $tab ] )
			);
		}
		$html .= '</div>';
		foreach ( $tabs as $tab ) {
			$html .= sprintf(
				'<div class="calp-panel" role="tabpanel" id="calp-panel-%1$s" aria-labelledby="calp-tab-%1$s" data-tab="%1$s"%2$s>%3$s</div>',
				esc_attr( $tab ),
				$tab === $default ? '' : ' hidden',
				( $panels[ $tab ] )()
			);
		}
		return $html . '</div>';
	}
}
