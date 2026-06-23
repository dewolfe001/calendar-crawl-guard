<?php
/**
 * The request interceptor and decision engine.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides — and on the live request, enforces — the canonical policy for
 * supported calendars.
 *
 * Calendar-specific request parsing lives in provider classes (one per
 * calendar plugin). Each provider normalizes a request into a common "context"
 * array, including precomputed canonical target URLs. The provider-agnostic
 * evaluate() then makes the decision, so the same engine powers the live
 * parse_request hook and the admin "Test a URL" tool.
 */
class CCG_Interceptor {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private array $s;

	/**
	 * Active provider instances.
	 *
	 * @var array<int,object>
	 */
	private array $providers = array();

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( array $settings ) {
		$this->s = $settings;

		// Order matters only when two calendars could both claim a request,
		// which should not happen in practice.
		if ( CCG_Provider_TEC::is_active() ) {
			$this->providers[] = new CCG_Provider_TEC();
		}
		if ( CCG_Provider_AI1EC::is_active() ) {
			$this->providers[] = new CCG_Provider_AI1EC();
		}
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		// Priority 1: act before the calendar plugin's own heavier work, and
		// crucially before the main query runs in WP::main().
		add_action( 'parse_request', array( $this, 'on_parse_request' ), 1 );
		add_action( 'wp_ajax_ccg_test_url', array( $this, 'ajax_test_url' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Live request handling
	 * --------------------------------------------------------------------- */

	/**
	 * Evaluate the current request and act on the verdict.
	 *
	 * @param WP $wp The WordPress environment object (passed by reference).
	 */
	public function on_parse_request( $wp ): void {
		if ( empty( $this->s['enabled'] ) ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}
		if ( $this->is_rest( $wp ) ) {
			return;
		}
		if ( ! empty( $this->s['skip_logged_in'] ) && is_user_logged_in() ) {
			return;
		}

		$context  = $this->detect_context( $wp );
		$decision = $this->evaluate( $context );

		if ( 'allow' === $decision['action'] ) {
			return;
		}

		if ( ! empty( $this->s['log_enabled'] ) ) {
			CCG_Logger::record( $decision, $context );
		}

		$this->send_cache_headers();

		if ( 'redirect' === $decision['action'] && ! empty( $decision['target'] ) ) {
			wp_safe_redirect( $decision['target'], (int) $decision['status'] );
			exit;
		}

		$this->terminate( (int) $decision['status'] );
	}

	/* --------------------------------------------------------------------- *
	 * The decision engine (provider-agnostic)
	 * --------------------------------------------------------------------- */

	/**
	 * Decide what to do with a normalized request context.
	 *
	 * @param array $c Context.
	 * @return array{action:string,status:int,target:?string,reason:string}
	 */
	public function evaluate( array $c ): array {
		$allow = static function ( string $reason ): array {
			return array(
				'action' => 'allow',
				'status' => 200,
				'target' => null,
				'reason' => $reason,
			);
		};
		$reject = static function ( int $status, string $reason ): array {
			return array(
				'action' => 'reject',
				'status' => $status,
				'target' => null,
				'reason' => $reason,
			);
		};
		$redirect = static function ( string $target, int $status, string $reason ): array {
			return array(
				'action' => 'redirect',
				'status' => $status,
				'target' => $target,
				'reason' => $reason,
			);
		};

		if ( empty( $this->s['enabled'] ) ) {
			return $allow( 'Plugin disabled' );
		}
		if ( empty( $c['is_match'] ) ) {
			return $allow( 'Not a managed calendar request' );
		}

		// Bad user agents (optional hard block).
		if ( ! empty( $this->s['enable_403'] ) && '' !== $c['ua'] && $this->matches_bad_ua( $c['ua'] ) ) {
			return $reject( 403, 'Blocked user agent' );
		}

		// Single events — real content, always kept. Recurring instances fold
		// back to the base event.
		if ( ! empty( $c['is_single'] ) ) {
			if ( ! empty( $this->s['recurring_redirect'] ) && ! empty( $c['is_recurring'] ) && ! empty( $c['base_event'] ) && $c['base_event'] !== $c['url'] ) {
				return $redirect( $c['base_event'], 301, 'Recurring instance &rarr; base event' );
			}
			return $allow( 'Single event' );
		}

		// Feeds.
		if ( ! empty( $c['is_feed'] ) ) {
			if ( empty( $this->s['allow_feeds'] ) ) {
				return $reject( (int) ( $this->s['feed_status'] ?? 410 ), 'Feed rejected' );
			}
			return $allow( 'Feed allowed' );
		}

		$view = (string) $c['view'];
		$date = (string) $c['date'];

		// Alternate presentation views → primary view of the same scope.
		if ( in_array( $view, (array) $this->s['redirect_views'], true ) ) {
			if ( ! empty( $c['primary_view'] ) && $c['primary_view'] !== $c['url'] ) {
				return $redirect( $c['primary_view'], (int) $this->s['redirect_status'], "View &lsquo;{$view}&rsquo; &rarr; primary view" );
			}
			return $reject( 410, "View &lsquo;{$view}&rsquo; (no canonical target)" );
		}

		// Date-tree views / explicit date → canonical window check.
		if ( in_array( $view, (array) $this->s['date_views'], true ) || '' !== $date ) {
			if ( ! $this->date_in_window( $date, (int) $this->s['window_months'] ) ) {
				$method = (int) $this->s['out_of_window_status'];
				if ( 301 === $method ) {
					if ( ! empty( $c['primary_archive'] ) && $c['primary_archive'] !== $c['url'] ) {
						return $redirect( $c['primary_archive'], 301, 'Out-of-window date &rarr; archive' );
					}
					return $reject( 410, 'Out-of-window date' );
				}
				return $reject( $method, 'Out-of-window date (' . ( '' !== $date ? $date : $view ) . ')' );
			}
			// In window → fall through to query-string canonicalization.
		}

		// Strip duplicate filter query parameters (pretty permalinks only).
		if ( ! empty( $this->s['reject_querystrings'] ) && ! empty( $c['has_pretty'] ) ) {
			$clean = $this->stripped_url( $c );
			if ( null !== $clean && $clean !== $c['url'] ) {
				return $redirect( $clean, (int) $this->s['redirect_status'], 'Stripped duplicate query parameters' );
			}
		}

		return $allow( 'Canonical / in-window' );
	}

	/* --------------------------------------------------------------------- *
	 * Context detection (delegated to providers)
	 * --------------------------------------------------------------------- */

	/**
	 * Base context shared by all providers, built from the live request.
	 *
	 * @param WP $wp WordPress environment object.
	 * @return array
	 */
	private function base_context_wp( $wp ): array {
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		return array(
			'is_match'        => false,
			'provider'        => '',
			'is_single'       => false,
			'is_recurring'    => false,
			'is_feed'         => false,
			'view'            => '',
			'date'            => '',
			'cat'             => '',
			'url'             => $this->current_url(),
			'path'            => '/' . $request . ( '' !== $request ? '/' : '' ),
			'query'           => $_GET, // phpcs:ignore WordPress.Security.NonceVerification
			'has_pretty'      => (bool) get_option( 'permalink_structure' ),
			'ua'              => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'primary_archive' => '',
			'primary_view'    => '',
			'base_event'      => '',
		);
	}

	/**
	 * Resolve the live request to a normalized context via the first matching
	 * provider.
	 *
	 * @param WP $wp WordPress environment object.
	 * @return array
	 */
	private function detect_context( $wp ): array {
		$base = $this->base_context_wp( $wp );
		foreach ( $this->providers as $provider ) {
			$ctx = $provider->context_from_wp( $wp, $base );
			if ( null !== $ctx && ! empty( $ctx['is_match'] ) ) {
				return $ctx;
			}
		}
		return $base;
	}

	/**
	 * Build a context from an arbitrary URL (admin tester) via the first
	 * matching provider.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	public function context_from_url( string $url ): array {
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$parts     = wp_parse_url( $url );
		$path      = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query     = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$abs    = $scheme . '://' . $home_host . $path;
		if ( ! empty( $query ) ) {
			$abs .= '?' . http_build_query( $query );
		}

		$base = array(
			'is_match'        => false,
			'provider'        => '',
			'is_single'       => false,
			'is_recurring'    => false,
			'is_feed'         => false,
			'view'            => '',
			'date'            => '',
			'cat'             => '',
			'url'             => $abs,
			'path'            => $path,
			'query'           => $query,
			'has_pretty'      => (bool) get_option( 'permalink_structure' ),
			'ua'              => '',
			'primary_archive' => '',
			'primary_view'    => '',
			'base_event'      => '',
		);

		foreach ( $this->providers as $provider ) {
			$ctx = $provider->context_from_url( $url, $base );
			if ( null !== $ctx && ! empty( $ctx['is_match'] ) ) {
				return $ctx;
			}
		}
		return $base;
	}

	/* --------------------------------------------------------------------- *
	 * SEO helper used by CCG_SEO
	 * --------------------------------------------------------------------- */

	/**
	 * Whether the current (kept) view should carry a noindex hint.
	 *
	 * @param WP $wp WordPress environment object.
	 * @return bool
	 */
	public function should_noindex( $wp ): bool {
		if ( empty( $this->s['noindex_nonprimary'] ) ) {
			return false;
		}
		$c = $this->detect_context( $wp );
		if ( empty( $c['is_match'] ) || ! empty( $c['is_single'] ) ) {
			return false;
		}
		if ( '' !== $c['date'] ) {
			return true;
		}
		if ( in_array( $c['view'], (array) $this->s['date_views'], true ) ) {
			return true;
		}
		$paged = isset( $c['query']['paged'] ) ? (int) $c['query']['paged'] : ( isset( $c['query']['tribe_paged'] ) ? (int) $c['query']['tribe_paged'] : ( isset( $c['query']['page_offset'] ) ? (int) $c['query']['page_offset'] : 0 ) );
		return $paged > 1;
	}

	/**
	 * Expose the active provider context for the current request (used by SEO).
	 *
	 * @param WP $wp WordPress environment object.
	 * @return array
	 */
	public function current_context( $wp ): array {
		return $this->detect_context( $wp );
	}

	/* --------------------------------------------------------------------- *
	 * Shared helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Is the date string inside the canonical window?
	 *
	 * @param string $date    YYYY-MM or YYYY-MM-DD, or '' (current).
	 * @param int    $months  Window radius in months.
	 * @return bool
	 */
	public function date_in_window( string $date, int $months ): bool {
		if ( '' === $date ) {
			return true;
		}

		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		$start = $now->modify( 'first day of this month' )->modify( "-{$months} months" )->setTime( 0, 0, 0 );
		$end   = $now->modify( 'last day of this month' )->modify( "+{$months} months" )->setTime( 23, 59, 59 );

		if ( preg_match( '/^\d{4}-\d{2}$/', $date ) ) {
			$d = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . '-01 00:00:00', $tz );
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$d = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 00:00:00', $tz );
		} else {
			return false;
		}

		if ( ! $d ) {
			return false;
		}

		return ( $d >= $start && $d <= $end );
	}

	/**
	 * Clean URL with the configured strip-parameters removed.
	 *
	 * @param array $c Context.
	 * @return string|null Null if nothing was stripped.
	 */
	private function stripped_url( array $c ): ?string {
		$strip = (array) ( $this->s['strip_params'] ?? array() );
		if ( empty( $strip ) ) {
			return null;
		}

		$query   = $c['query'];
		$changed = false;
		foreach ( $strip as $k ) {
			if ( isset( $query[ $k ] ) ) {
				unset( $query[ $k ] );
				$changed = true;
			}
		}
		if ( ! $changed ) {
			return null;
		}

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme    = is_ssl() ? 'https' : 'http';
		$url       = $scheme . '://' . $home_host . $c['path'];
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}
		return $url;
	}

	/**
	 * Current request URL, rebuilt on the trusted home host.
	 *
	 * @return string
	 */
	private function current_url(): string {
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme    = is_ssl() ? 'https' : 'http';
		$uri       = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return $scheme . '://' . $home_host . $uri;
	}

	/**
	 * Is this a REST request?
	 *
	 * @param WP $wp WordPress environment object.
	 * @return bool
	 */
	private function is_rest( $wp ): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( is_object( $wp ) && ! empty( $wp->query_vars['rest_route'] ) ) {
			return true;
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return false !== strpos( $uri, '/' . $prefix . '/' );
	}

	/**
	 * Case-insensitive user-agent block-list match.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	private function matches_bad_ua( string $ua ): bool {
		$list = (string) ( $this->s['bad_user_agents'] ?? '' );
		if ( '' === trim( $list ) ) {
			return false;
		}
		$needles = preg_split( '/\r\n|\r|\n/', $list );
		$hay     = strtolower( $ua );
		foreach ( $needles as $needle ) {
			$needle = strtolower( trim( $needle ) );
			if ( '' !== $needle && false !== strpos( $hay, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Send cache headers for an intercepted response (if enabled).
	 */
	private function send_cache_headers(): void {
		if ( empty( $this->s['cache_headers'] ) || headers_sent() ) {
			return;
		}
		$ttl = max( 0, (int) ( $this->s['cache_ttl'] ?? 0 ) );
		if ( $ttl > 0 ) {
			header( 'Cache-Control: public, max-age=' . $ttl );
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $ttl ) . ' GMT' );
		}
	}

	/**
	 * Emit a terminal status with a minimal body and stop.
	 *
	 * @param int $status HTTP status (403/404/410).
	 */
	private function terminate( int $status ): void {
		if ( ! in_array( $status, array( 403, 404, 410 ), true ) ) {
			$status = 410;
		}
		status_header( $status );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}

		$messages = array(
			403 => '403 Forbidden',
			404 => '404 Not Found',
			410 => '410 Gone',
		);
		echo esc_html( $messages[ $status ] );
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * Ajax "Test a URL" tool
	 * --------------------------------------------------------------------- */

	/**
	 * Ajax handler: evaluate a pasted URL and return the verdict as JSON.
	 */
	public function ajax_test_url(): void {
		check_ajax_referer( 'ccg_test_url', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => 'Please enter a URL.' ) );
		}

		$context  = $this->context_from_url( $url );
		$decision = $this->evaluate( $context );

		$labels = array(
			'allow'    => 'Allow (200)',
			'redirect' => $decision['status'] . ' Redirect',
			'reject'   => $decision['status'] . ' Reject',
		);

		wp_send_json_success(
			array(
				'verdict' => $labels[ $decision['action'] ] ?? $decision['action'],
				'action'  => $decision['action'],
				'status'  => (int) $decision['status'],
				'target'  => $decision['target'],
				'reason'  => wp_kses_post( $decision['reason'] ),
				'context' => array(
					'provider'  => $context['provider'],
					'is_match'  => $context['is_match'],
					'is_single' => $context['is_single'],
					'is_feed'   => $context['is_feed'],
					'view'      => $context['view'],
					'date'      => $context['date'],
					'cat'       => $context['cat'],
				),
			)
		);
	}
}
