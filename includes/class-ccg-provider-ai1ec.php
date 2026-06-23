<?php
/**
 * Provider for the All-in-One Event Calendar (Timely / ai1ec).
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes All-in-One Event Calendar requests into the shared vocabulary.
 *
 * AI1EC drives a single "calendar page" with navigation query parameters:
 * action (view), exact_date, and month_offset / week_offset / oneday_offset /
 * page_offset. View names are normalized to the engine vocabulary:
 *   month -> month, week -> week, oneday -> day (date-checked)
 *   agenda -> list (primary)
 *   posterboard -> photo, stream -> map (alternate presentation)
 */
class CCG_Provider_AI1EC {

	const NAME = 'ai1ec';

	/**
	 * AI1EC navigation parameters that signal a calendar request.
	 *
	 * @var string[]
	 */
	private array $nav_params = array( 'action', 'exact_date', 'month_offset', 'week_offset', 'oneday_offset', 'page_offset' );

	/**
	 * View-name normalization map.
	 *
	 * @var array<string,string>
	 */
	private array $view_map = array(
		'month'       => 'month',
		'week'        => 'week',
		'oneday'      => 'day',
		'day'         => 'day',
		'agenda'      => 'list',
		'posterboard' => 'photo',
		'stream'      => 'map',
	);

	/**
	 * Is the All-in-One Event Calendar active?
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'AI1EC_VERSION' )
			|| defined( 'AI1EC_POST_TYPE' )
			|| class_exists( 'Ai1ec_Calendar_Controller' )
			|| post_type_exists( 'ai1ec_event' );
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
		$q  = $base['query'];

		$post_type = $qv['post_type'] ?? '';
		if ( is_array( $post_type ) ) {
			$post_type = in_array( 'ai1ec_event', $post_type, true ) ? 'ai1ec_event' : (string) reset( $post_type );
		}

		$is_single = ! empty( $qv['ai1ec_event'] ) || ( 'ai1ec_event' === $post_type && ! empty( $qv['name'] ) );

		// Detect the calendar page.
		$cal_id   = $this->calendar_page_id();
		$page_id  = isset( $qv['page_id'] ) ? (int) $qv['page_id'] : 0;
		$pagename = isset( $qv['pagename'] ) ? (string) $qv['pagename'] : '';

		$on_cal_page = false;
		if ( $cal_id ) {
			if ( $page_id === $cal_id ) {
				$on_cal_page = true;
			} elseif ( '' !== $pagename ) {
				$slug = get_post_field( 'post_name', $cal_id );
				if ( $slug && $pagename === $slug ) {
					$on_cal_page = true;
				}
			}
		}

		$has_nav = $this->has_nav_params( $q );

		$is_match = $is_single || $on_cal_page || $has_nav;
		if ( ! $is_match ) {
			return null;
		}

		return $this->assemble_from_query( $base, $q, $is_single );
	}

	/**
	 * Build context from an arbitrary URL (admin tester, heuristic, query-based).
	 *
	 * @param string $url  URL.
	 * @param array  $base Base context.
	 * @return array|null
	 */
	public function context_from_url( string $url, array $base ): ?array {
		$q = $base['query'];

		$is_single = ! empty( $q['post_type'] ) && 'ai1ec_event' === $q['post_type'];
		$is_match  = $is_single || $this->has_nav_params( $q ) || ! empty( $q['cat_ids'] ) || ! empty( $q['tag_ids'] );

		if ( ! $is_match ) {
			return null;
		}

		return $this->assemble_from_query( $base, $q, $is_single );
	}

	/**
	 * Shared assembly from a query parameter set.
	 *
	 * @param array $base      Base context.
	 * @param array $q         Query parameters.
	 * @param bool  $is_single Single event.
	 * @return array
	 */
	private function assemble_from_query( array $base, array $q, bool $is_single ): array {
		$ctx = $base;

		$action = isset( $q['action'] ) ? (string) $q['action'] : '';
		if ( str_starts_with( $action, 'ai1ec_' ) ) {
			$action = substr( $action, 6 );
		}
		$action = strtolower( trim( $action ) );

		$view = $this->view_map[ $action ] ?? '';
		$date = $this->resolve_date( $q );

		$is_recurring = $is_single && ( isset( $q['instance_id'] ) || isset( $q['instance'] ) );
		$is_feed      = $this->is_feed( $q );

		$cal_url = $this->calendar_page_url();

		$ctx['is_match']        = true;
		$ctx['provider']        = self::NAME;
		$ctx['is_single']       = $is_single;
		$ctx['is_recurring']    = $is_recurring;
		$ctx['is_feed']         = $is_feed;
		$ctx['view']            = $view;
		$ctx['date']            = $date;
		$ctx['cat']             = isset( $q['cat_ids'] ) ? (string) $q['cat_ids'] : '';
		$ctx['primary_archive'] = $cal_url;
		$ctx['primary_view']    = $cal_url;
		$ctx['base_event']      = $is_recurring ? $this->base_event_url( $base ) : '';

		return $ctx;
	}

	/**
	 * Are any AI1EC navigation parameters present?
	 *
	 * @param array $q Query parameters.
	 * @return bool
	 */
	private function has_nav_params( array $q ): bool {
		foreach ( $this->nav_params as $p ) {
			if ( isset( $q[ $p ] ) && '' !== $q[ $p ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determine an effective YYYY-MM / YYYY-MM-DD date from exact_date or offsets.
	 *
	 * @param array $q Query parameters.
	 * @return string Empty string means "current" (always in window).
	 */
	private function resolve_date( array $q ): string {
		if ( ! empty( $q['exact_date'] ) ) {
			return $this->normalize_date( (string) $q['exact_date'] );
		}

		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		if ( isset( $q['month_offset'] ) && '' !== $q['month_offset'] && is_numeric( $q['month_offset'] ) ) {
			$n = (int) $q['month_offset'];
			return $now->modify( ( $n >= 0 ? '+' : '' ) . $n . ' months' )->format( 'Y-m' );
		}
		if ( isset( $q['week_offset'] ) && '' !== $q['week_offset'] && is_numeric( $q['week_offset'] ) ) {
			$n = (int) $q['week_offset'] * 7;
			return $now->modify( ( $n >= 0 ? '+' : '' ) . $n . ' days' )->format( 'Y-m-d' );
		}
		if ( isset( $q['oneday_offset'] ) && '' !== $q['oneday_offset'] && is_numeric( $q['oneday_offset'] ) ) {
			$n = (int) $q['oneday_offset'];
			return $now->modify( ( $n >= 0 ? '+' : '' ) . $n . ' days' )->format( 'Y-m-d' );
		}

		return '';
	}

	/**
	 * Normalize a variety of date inputs to YYYY-MM-DD. Returns '' if unparseable
	 * (treated as current, i.e. in-window) to avoid false rejections.
	 *
	 * @param string $raw Raw date.
	 * @return string
	 */
	private function normalize_date( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Already YYYY-MM-DD or YYYY-MM.
		if ( preg_match( '/^\d{4}-\d{2}(-\d{2})?$/', $raw ) ) {
			return $raw;
		}

		// Unix timestamp.
		if ( ctype_digit( $raw ) && strlen( $raw ) >= 9 ) {
			return gmdate( 'Y-m-d', (int) $raw );
		}

		// m-d-Y (the format AI1EC documents, e.g. 5-10-2015).
		if ( preg_match( '#^(\d{1,2})-(\d{1,2})-(\d{4})$#', $raw, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2] );
		}

		// Fallback.
		$ts = strtotime( $raw );
		if ( false !== $ts ) {
			return gmdate( 'Y-m-d', $ts );
		}

		return '';
	}

	/**
	 * Is this an AI1EC export / feed request?
	 *
	 * @param array $q Query parameters.
	 * @return bool
	 */
	private function is_feed( array $q ): bool {
		if ( isset( $q['action'] ) && false !== stripos( (string) $q['action'], 'export' ) ) {
			return true;
		}
		if ( isset( $q['plugin'] ) && 'all-in-one-event-calendar' === $q['plugin'] ) {
			return true;
		}
		return false;
	}

	/**
	 * The AI1EC calendar page ID, from settings (best effort).
	 *
	 * @return int
	 */
	private function calendar_page_id(): int {
		$opt = get_option( 'ai1ec_settings' );
		if ( is_array( $opt ) && ! empty( $opt['calendar_page_id'] ) ) {
			return (int) $opt['calendar_page_id'];
		}
		if ( is_object( $opt ) && ! empty( $opt->calendar_page_id ) ) {
			return (int) $opt->calendar_page_id;
		}
		return 0;
	}

	/**
	 * Canonical calendar page URL (the bare default view).
	 *
	 * @return string
	 */
	private function calendar_page_url(): string {
		$id = $this->calendar_page_id();
		if ( $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Base event URL for a recurring instance permalink (strip instance params).
	 *
	 * @param array $base Base context.
	 * @return string
	 */
	private function base_event_url( array $base ): string {
		$q = $base['query'];
		unset( $q['instance_id'], $q['instance'] );

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme    = is_ssl() ? 'https' : 'http';
		$url       = $scheme . '://' . $home_host . $base['path'];
		if ( ! empty( $q ) ) {
			$url .= '?' . http_build_query( $q );
		}
		return $url;
	}
}
