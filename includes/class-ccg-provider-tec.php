<?php
/**
 * Provider for The Events Calendar (TEC).
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes The Events Calendar requests into the shared context vocabulary.
 *
 * Normalized view names: month, day, week (date-checked); list (primary);
 * photo, map (alternate presentation).
 */
class CCG_Provider_TEC {

	const NAME = 'tec';

	/**
	 * Is The Events Calendar active?
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( 'Tribe__Events__Main' ) || function_exists( 'tribe_get_option' );
	}

	/**
	 * Build context from the live request.
	 *
	 * @param WP    $wp   WordPress environment object.
	 * @param array $base Base context.
	 * @return array|null
	 */
	public function context_from_wp( $wp, array $base ): ?array {
		$qv = is_object( $wp ) && isset( $wp->query_vars ) ? (array) $wp->query_vars : array();

		$post_type = $qv['post_type'] ?? '';
		if ( is_array( $post_type ) ) {
			$post_type = in_array( 'tribe_events', $post_type, true ) ? 'tribe_events' : (string) reset( $post_type );
		}

		$is_single = ! empty( $qv['tribe_events'] )
			|| ( 'tribe_events' === $post_type && ! empty( $qv['name'] ) );

		$view = isset( $qv['eventDisplay'] ) ? (string) $qv['eventDisplay'] : '';
		$date = isset( $qv['eventDate'] ) ? (string) $qv['eventDate'] : '';

		$is_recurring = $is_single && ( '' !== $date || 'all' === $view );

		$is_feed = ! empty( $qv['ical'] )
			|| ! empty( $qv['feed'] )
			|| isset( $base['query']['outlook-ical'] );

		$cat = ! empty( $qv['tribe_events_cat'] ) ? (string) $qv['tribe_events_cat'] : '';

		$slug        = ccg_events_slug();
		$single_slug = ccg_single_slug();
		$request     = trim( (string) ( $base['path'] ?? '' ), '/' );

		$is_match = 'tribe_events' === $post_type
			|| '' !== $view
			|| '' !== $cat
			|| ! empty( $qv['tribe_events'] )
			|| $request === $slug
			|| 0 === strpos( $request, $slug . '/' )
			|| 0 === strpos( $request, $single_slug . '/' );

		if ( ! $is_match ) {
			return null;
		}

		return $this->assemble( $base, $is_single, $is_recurring, $is_feed, $view, $date, $cat );
	}

	/**
	 * Build context from an arbitrary URL (admin tester, heuristic).
	 *
	 * @param string $url  URL.
	 * @param array  $base Base context.
	 * @return array|null
	 */
	public function context_from_url( string $url, array $base ): ?array {
		$query = $base['query'];
		$slug        = ccg_events_slug();
		$single_slug = ccg_single_slug();

		$path = trim( (string) $base['path'], '/' );
		$segs = '' === $path ? array() : explode( '/', $path );

		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path ) {
			foreach ( explode( '/', $home_path ) as $hs ) {
				if ( isset( $segs[0] ) && $segs[0] === $hs ) {
					array_shift( $segs );
				}
			}
		}

		$view         = isset( $query['eventDisplay'] ) ? (string) $query['eventDisplay'] : ( isset( $query['tribe_event_display'] ) ? (string) $query['tribe_event_display'] : '' );
		$date         = isset( $query['eventDate'] ) ? (string) $query['eventDate'] : '';
		$cat          = isset( $query['tribe_events_cat'] ) ? (string) $query['tribe_events_cat'] : '';
		$is_single    = false;
		$is_recurring = false;
		$is_feed      = ! empty( $query['ical'] ) || isset( $query['outlook-ical'] );

		$known_views = array( 'list', 'month', 'day', 'week', 'photo', 'map', 'past', 'upcoming', 'today', 'all' );
		$is_match    = '' !== $view || '' !== $date || '' !== $cat
			|| ( ! empty( $query['post_type'] ) && 'tribe_events' === $query['post_type'] );

		if ( isset( $segs[0] ) && $segs[0] === $single_slug ) {
			$is_single = true;
			$is_match  = true;
			$tail      = end( $segs );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $tail ) || 'all' === $tail ) {
				$is_recurring = true;
				if ( 'all' === $tail ) {
					$view = 'all';
				} else {
					$date = (string) $tail;
				}
			}
		} elseif ( isset( $segs[0] ) && $segs[0] === $slug ) {
			$is_match = true;
			array_shift( $segs );
			$count = count( $segs );
			for ( $i = 0; $i < $count; $i++ ) {
				$seg = $segs[ $i ];
				if ( in_array( $seg, $known_views, true ) ) {
					$view = ( '' === $view ) ? $seg : $view;
				} elseif ( preg_match( '/^\d{4}-\d{2}(-\d{2})?$/', $seg ) ) {
					$date = ( '' === $date ) ? $seg : $date;
				} elseif ( 'category' === $seg && isset( $segs[ $i + 1 ] ) ) {
					$cat = ( '' === $cat ) ? $segs[ $i + 1 ] : $cat;
				} elseif ( 'feed' === $seg || 'ical' === $seg ) {
					$is_feed = true;
				}
			}
		}

		if ( ! $is_match ) {
			return null;
		}

		return $this->assemble( $base, $is_single, $is_recurring, $is_feed, $view, $date, $cat );
	}

	/**
	 * Assemble the final normalized context (shared by both builders).
	 *
	 * @param array  $base         Base context.
	 * @param bool   $is_single    Single event.
	 * @param bool   $is_recurring Recurring instance permalink.
	 * @param bool   $is_feed      Feed.
	 * @param string $view         TEC view (eventDisplay).
	 * @param string $date         eventDate.
	 * @param string $cat          Category slug.
	 * @return array
	 */
	private function assemble( array $base, bool $is_single, bool $is_recurring, bool $is_feed, string $view, string $date, string $cat ): array {
		$ctx = $base;

		$ctx['is_match']     = true;
		$ctx['provider']     = self::NAME;
		$ctx['is_single']    = $is_single;
		$ctx['is_recurring'] = $is_recurring;
		$ctx['is_feed']      = $is_feed;
		$ctx['view']         = $view; // TEC view names already match the engine vocabulary.
		$ctx['date']         = $date;
		$ctx['cat']          = $cat;

		$ctx['primary_archive'] = $this->primary_archive_url();
		$ctx['primary_view']    = $this->primary_view_url( $cat );
		$ctx['base_event']      = $is_recurring ? $this->base_event_url( $base['path'] ) : '';

		return $ctx;
	}

	/**
	 * Primary events archive URL (list view, current).
	 *
	 * @return string
	 */
	private function primary_archive_url(): string {
		if ( function_exists( 'tribe_get_listview_link' ) ) {
			$u = tribe_get_listview_link();
			if ( $u ) {
				return $u;
			}
		}
		return home_url( '/' . ccg_events_slug() . '/' );
	}

	/**
	 * Primary (list) view URL for the current scope, preserving category.
	 *
	 * @param string $cat Category slug.
	 * @return string
	 */
	private function primary_view_url( string $cat ): string {
		$term = null;
		if ( '' !== $cat ) {
			$t = get_term_by( 'slug', $cat, 'tribe_events_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$term = $t;
			}
		}
		if ( function_exists( 'tribe_get_listview_link' ) ) {
			$u = tribe_get_listview_link( $term );
			if ( $u ) {
				return $u;
			}
		}
		return $this->primary_archive_url();
	}

	/**
	 * Base event URL for a recurring instance permalink.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private function base_event_url( string $path ): string {
		$path = preg_replace( '#/\d{4}-\d{2}-\d{2}/?$#', '/', $path );
		$path = preg_replace( '#/all/?$#', '/', $path );

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme    = is_ssl() ? 'https' : 'http';
		return $scheme . '://' . $home_host . $path;
	}
}
