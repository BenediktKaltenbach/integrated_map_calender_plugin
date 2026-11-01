<?php
/**
 * Event list for the shortcode and the calendar tab: grouped by month, each event with a
 * date badge, title, location, status and Info / Register buttons.
 */

namespace CalendarPlugin\Events;

final class Renderer {

	/**
	 * [calp_events category="eurocup,championship" count="10" months="12" past="0"]
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'count'    => 0,
				'months'   => 0,
				'past'     => '0',
			),
			$atts,
			'calp_events'
		);
		wp_enqueue_style( 'calp' );
		return self::list(
			array(
				'categories' => array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['category'] ) ) ),
				'count'      => (int) $atts['count'],
				'months'     => (int) $atts['months'],
				'past'       => filter_var( $atts['past'], FILTER_VALIDATE_BOOLEAN ),
			)
		);
	}

	/** @return int[] post IDs of matching events, sorted by start */
	public static function query( array $args ): array {
		$meta = array(
			array(
				'key'     => '_calp_removed',
				'compare' => 'NOT EXISTS',
			),
		);
		if ( ! empty( $args['categories'] ) ) {
			$meta[] = array(
				'key'     => '_calp_category',
				'value'   => $args['categories'],
				'compare' => 'IN',
			);
		}
		if ( empty( $args['past'] ) ) {
			$meta[] = array(
				'key'     => '_calp_end',
				'value'   => time(),
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}
		if ( ! empty( $args['months'] ) ) {
			$meta[] = array(
				'key'     => '_calp_start',
				'value'   => strtotime( '+' . (int) $args['months'] . ' months' ),
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		$ids = get_posts(
			array(
				'post_type'      => Events::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => ! empty( $args['count'] ) ? (int) $args['count'] : 300,
				'fields'         => 'ids',
				'meta_key'       => '_calp_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => empty( $args['past'] ) ? 'ASC' : 'DESC',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		update_meta_cache( 'post', $ids );
		return $ids;
	}

	public static function list( array $args ): string {
		$ids = self::query( $args );
		if ( ! $ids ) {
			return '<p class="calp-empty">' . esc_html__( 'There are no upcoming events at the moment.', 'calendar-plugin' ) . '</p>';
		}
		$tz    = Format::tz();
		$month = '';
		$html  = '<div class="calp-events">';
		foreach ( $ids as $id ) {
			$d = Events::data( $id );
			$m = wp_date( 'F Y', $d['start'], $tz );
			if ( $m !== $month ) {
				$html .= ( '' !== $month ? '</ul>' : '' ) . '<h3 class="calp-events__month"><span>' . esc_html( $m ) . '</span></h3><ul class="calp-events__list">';
				$month = $m;
			}
			$html .= self::item( $id, $d );
		}
		return $html . '</ul></div>';
	}

	private static function item( int $id, array $d ): string {
		$tz      = Format::tz();
		$f       = $d['fields'];
		$status  = $d['status'];
		$end_day = $d['all_day'] ? $d['end'] - DAY_IN_SECONDS : $d['end'];
		$day     = wp_date( 'j', $d['start'], $tz );
		if ( wp_date( 'Ymd', $end_day, $tz ) !== wp_date( 'Ymd', $d['start'], $tz ) && $end_day > $d['start'] ) {
			$day .= '–' . wp_date( 'j', $end_day, $tz );
		}
		$mon = wp_date( 'M', $d['start'], $tz );
		if ( wp_date( 'm', $end_day, $tz ) !== wp_date( 'm', $d['start'], $tz ) && $end_day > $d['start'] ) {
			$mon .= '–' . wp_date( 'M', $end_day, $tz );
		}

		$flag = '';
		if ( ! empty( $f['country'] ) ) {
			$flag = sprintf( '<span class="calp-event__flag" role="img" aria-label="%1$s" title="%1$s">%2$s</span> ', esc_attr( Format::country_name( $f['country'] ) ), esc_html( Format::flag( $f['country'] ) ) );
		}

		$meta = array();
		if ( '' !== ( $f['display'] ?? '' ) ) {
			$meta[] = esc_html( $f['display'] );
		} elseif ( ! $d['all_day'] ) {
			$meta[] = esc_html( Format::range( $d['start'], $d['end'], false ) );
		}
		if ( '' !== $d['location'] ) {
			$meta[] = esc_html( $d['location'] );
		}
		$meta[] = '<span class="calp-event__cat" style="--calp-cat:' . esc_attr( Events::category_color( $d['category'] ) ) . '">' . esc_html( Events::category_name( $d['category'] ) ) . '</span>';
		if ( $status ) {
			$meta[] = '<span class="calp-event__status">' . esc_html( Parser::status_labels()[ $status ] ?? $status ) . '</span>';
		}

		$buttons = '';
		if ( ! empty( $f['info'] ) ) {
			$buttons .= self::button( $f['info'], __( 'Info', 'calendar-plugin' ), ! empty( $f['registration'] ) );
		}
		if ( ! empty( $f['registration'] ) && 'cancelled' !== $status && $d['end'] > time() ) {
			$buttons .= self::button( $f['registration'], __( 'Register', 'calendar-plugin' ), false );
		}
		if ( '' === $buttons && 'cancelled' !== $status ) {
			$buttons = '<span class="calp-btn calp-btn--muted">' . esc_html__( 'Info follows', 'calendar-plugin' ) . '</span>';
		}

		$text = trim( (string) get_post_field( 'post_content', $id ) );
		$more = '' !== $text ? '<details class="calp-event__more"><summary>' . esc_html__( 'Details', 'calendar-plugin' ) . '</summary>' . wp_kses_post( wpautop( make_clickable( esc_html( $text ) ) ) ) . '</details>' : '';

		return sprintf(
			'<li class="calp-event%1$s" style="--calp-cat:%2$s"><div class="calp-event__date"><span class="calp-event__day">%3$s</span><span class="calp-event__mon">%4$s</span></div><div class="calp-event__body"><h4 class="calp-event__title">%5$s%6$s</h4><p class="calp-event__meta">%7$s</p>%8$s</div><div class="calp-event__actions">%9$s</div></li>',
			$status ? ' is-' . esc_attr( $status ) : '',
			esc_attr( Events::category_color( $d['category'] ) ),
			esc_html( $day ),
			esc_html( $mon ),
			$flag,
			esc_html( get_the_title( $id ) ),
			implode( ' · ', $meta ),
			$more,
			$buttons
		);
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
}
