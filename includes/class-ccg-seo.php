<?php
/**
 * Authority-shaping: canonical tags, noindex hints, robots.txt rules.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "feed authority to a short list" half of the strategy. These are
 * crawler hints (they do not reduce load); they complement the interceptor.
 */
class CCG_SEO {

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private array $s;

	/**
	 * Interceptor (for shared context/decision logic).
	 *
	 * @var CCG_Interceptor
	 */
	private CCG_Interceptor $interceptor;

	/**
	 * Constructor.
	 *
	 * @param array            $settings    Settings.
	 * @param CCG_Interceptor $interceptor Interceptor instance.
	 */
	public function __construct( array $settings, CCG_Interceptor $interceptor ) {
		$this->s           = $settings;
		$this->interceptor = $interceptor;
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		if ( ! empty( $this->s['noindex_nonprimary'] ) ) {
			// TEC exposes its own filter; AI1EC needs a wp_head meta tag.
			if ( ccg_is_tec_active() ) {
				add_filter( 'tribe_events_add_no_index_meta', array( $this, 'filter_noindex' ) );
			}
			if ( ccg_is_ai1ec_active() ) {
				add_action( 'wp_head', array( $this, 'print_ai1ec_noindex' ), 1 );
			}
		}
		if ( ! empty( $this->s['add_canonical'] ) ) {
			add_action( 'wp_head', array( $this, 'print_canonical' ), 1 );
		}
		if ( ! empty( $this->s['robots_txt'] ) ) {
			add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20, 2 );
		}
	}

	/**
	 * Print a noindex meta tag for kept-but-secondary AI1EC calendar views.
	 */
	public function print_ai1ec_noindex(): void {
		global $wp;
		$ctx = $this->interceptor->current_context( $wp );
		if ( empty( $ctx['is_match'] ) || 'ai1ec' !== $ctx['provider'] ) {
			return;
		}
		if ( $this->interceptor->should_noindex( $wp ) ) {
			echo '<meta name="robots" content="noindex,follow" />' . "\n";
		}
	}

	/**
	 * Add a noindex hint to kept-but-secondary event views.
	 *
	 * @param bool $add Current value from TEC.
	 * @return bool
	 */
	public function filter_noindex( $add ) {
		global $wp;
		if ( $add ) {
			return true; // Respect TEC's own decision.
		}
		return $this->interceptor->should_noindex( $wp );
	}

	/**
	 * Print a rel=canonical link for kept calendar archive views.
	 *
	 * Skipped when a major SEO plugin is managing canonicals to avoid duplicates.
	 */
	public function print_canonical(): void {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}
		if ( is_singular() ) {
			return; // Core / calendar plugin handles singular canonicals.
		}

		$canonical = '';

		// The Events Calendar.
		if ( function_exists( 'tribe_is_event_query' ) && tribe_is_event_query() ) {
			if ( function_exists( 'tribe_is_month' ) && tribe_is_month() ) {
				$canonical = function_exists( 'tribe_get_gridview_link' ) ? tribe_get_gridview_link() : '';
			} elseif ( function_exists( 'tribe_get_listview_link' ) ) {
				$canonical = tribe_get_listview_link();
			}
		}

		// All-in-One Event Calendar: canonicalize calendar-page views to the
		// bare calendar page.
		if ( '' === $canonical && ccg_is_ai1ec_active() ) {
			global $wp;
			$ctx = $this->interceptor->current_context( $wp );
			if ( ! empty( $ctx['is_match'] ) && 'ai1ec' === $ctx['provider'] && empty( $ctx['is_single'] ) && ! empty( $ctx['primary_archive'] ) ) {
				$canonical = $ctx['primary_archive'];
			}
		}

		if ( $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
	}

	/**
	 * Append targeted Disallow rules to the virtual robots.txt.
	 *
	 * Scoped to feeds and filter parameters only — never the pages we 301/410,
	 * so crawlers can still see and process those signals.
	 *
	 * @param string $output Existing robots.txt body.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function filter_robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$rules = array( '', '# Calendar Crawl Guard' );

		if ( ccg_is_tec_active() ) {
			$slug    = ccg_events_slug();
			$rules[] = 'Disallow: /*ical=1';
			$rules[] = 'Disallow: /*outlook-ical=';
			$rules[] = 'Disallow: /*tribe-bar-date=';
			$rules[] = 'Disallow: /*tribe-bar-search=';
			$rules[] = 'Disallow: /*tribe-bar-geoloc';
			$rules[] = 'Disallow: /' . $slug . '/*/feed/';
			$rules[] = 'Disallow: /' . $slug . '/*/ical/';
		}

		if ( ccg_is_ai1ec_active() ) {
			$rules[] = 'Disallow: /*month_offset=';
			$rules[] = 'Disallow: /*week_offset=';
			$rules[] = 'Disallow: /*oneday_offset=';
			$rules[] = 'Disallow: /*page_offset=';
			$rules[] = 'Disallow: /*exact_date=';
			$rules[] = 'Disallow: /*plugin=all-in-one-event-calendar';
		}

		return $output . implode( "\n", $rules ) . "\n";
	}
}
