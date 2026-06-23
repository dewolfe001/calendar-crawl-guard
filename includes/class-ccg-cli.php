<?php
/**
 * WP-CLI commands for Calendar Crawl Guard.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Test and inspect Calendar Crawl Guard verdicts from the command line.
 */
class CCG_CLI {

	/**
	 * Lazily-built interceptor using the current saved settings.
	 *
	 * @var CCG_Interceptor|null
	 */
	private ?CCG_Interceptor $interceptor = null;

	/**
	 * Get an interceptor instance bound to current settings.
	 *
	 * @return CCG_Interceptor
	 */
	private function engine(): CCG_Interceptor {
		if ( null === $this->interceptor ) {
			$this->interceptor = new CCG_Interceptor( CCG_Settings::get_options() );
		}
		return $this->interceptor;
	}

	/**
	 * Run one or more URLs through the decision engine and print the verdicts.
	 *
	 * Uses the same evaluate() engine as the live request hook, so the verdict
	 * matches what a visitor (or crawler) would get. URL detection from a string
	 * is heuristic (it does not run WordPress rewrite rules); the query-string
	 * form of a URL is always parsed deterministically.
	 *
	 * ## OPTIONS
	 *
	 * [<url>...]
	 * : One or more URLs to test.
	 *
	 * [--file=<file>]
	 * : Read URLs from a file, one per line (blank lines and lines starting with
	 * # are ignored). Use - to read from STDIN.
	 *
	 * [--only=<actions>]
	 * : Show only rows whose action is in this comma-separated list
	 * (allow, redirect, reject).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Test a few URLs
	 *     wp ccg test "https://example.com/events/2031-05/" "https://example.com/events/list/"
	 *
	 *     # Test a list from a file, JSON output
	 *     wp ccg test --file=urls.txt --format=json
	 *
	 *     # Pipe URLs in and show only the ones that get rejected
	 *     wp ccg test --file=- --only=reject < urls.txt
	 *
	 * @param array $args       Positional args (URLs).
	 * @param array $assoc_args Flags.
	 */
	public function test( $args, $assoc_args ): void {
		$urls = $args;

		$file = \WP_CLI\Utils\get_flag_value( $assoc_args, 'file', '' );
		if ( '' !== $file ) {
			$urls = array_merge( $urls, $this->read_urls( $file ) );
		}

		$urls = array_values( array_filter( array_map( 'trim', $urls ), 'strlen' ) );
		if ( empty( $urls ) ) {
			WP_CLI::error( 'No URLs given. Pass URLs as arguments or use --file=<path> (or --file=-).' );
		}

		$only_raw = \WP_CLI\Utils\get_flag_value( $assoc_args, 'only', '' );
		$only     = '' !== $only_raw ? array_map( 'trim', explode( ',', strtolower( $only_raw ) ) ) : array();

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$rows = array();
		foreach ( $urls as $url ) {
			$rows[] = $this->evaluate_url( $url );
		}

		if ( $only ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $only ) {
						return in_array( $r['action'], $only, true );
					}
				)
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No rows matched the filter.' );
			return;
		}

		if ( 'count' === $format ) {
			$this->print_counts( $rows );
			return;
		}

		$fields = ( 'table' === $format )
			? array( 'url', 'action', 'status', 'provider', 'view', 'date', 'target' )
			: array( 'url', 'action', 'status', 'provider', 'view', 'date', 'target', 'reason' );

		\WP_CLI\Utils\format_items( $format, $rows, $fields );

		if ( 'table' === $format ) {
			$this->print_summary_line( $rows );
		}
	}

	/**
	 * Probe month URLs across a range of offsets to see where the canonical
	 * window flips from allow to reject.
	 *
	 * ## OPTIONS
	 *
	 * [--months=<n>]
	 * : How many months to probe in each direction from the current month.
	 * Defaults to the configured window plus 3, so you can see both sides of
	 * the boundary.
	 *
	 * [--provider=<provider>]
	 * : Which calendar's URL shape to generate.
	 * ---
	 * options:
	 *   - tec
	 *   - ai1ec
	 * ---
	 *
	 * [--base=<url>]
	 * : Base URL to build query parameters onto. Defaults to the site home URL.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Probe the boundary for The Events Calendar
	 *     wp ccg scan --provider=tec
	 *
	 *     # Probe 12 months either side for All-in-One Event Calendar
	 *     wp ccg scan --provider=ai1ec --months=12
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function scan( $args, $assoc_args ): void {
		$options  = CCG_Settings::get_options();
		$window   = (int) $options['window_months'];
		$months   = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'months', $window + 3 );
		$months   = max( 1, min( 120, $months ) );
		$format   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$base     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'base', home_url( '/' ) );

		$provider = \WP_CLI\Utils\get_flag_value( $assoc_args, 'provider', '' );
		if ( '' === $provider ) {
			if ( ccg_is_tec_active() ) {
				$provider = 'tec';
			} elseif ( ccg_is_ai1ec_active() ) {
				$provider = 'ai1ec';
			} else {
				WP_CLI::error( 'No supported calendar is active. Specify --provider=tec or --provider=ai1ec to scan anyway.' );
			}
		}

		WP_CLI::log( sprintf( 'Scanning %s month views from -%d to +%d months (window is +/-%d).', $provider, $months, $months, $window ) );

		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		$rows = array();
		for ( $n = -$months; $n <= $months; $n++ ) {
			$ym  = $now->modify( ( $n >= 0 ? '+' : '' ) . $n . ' months' )->format( 'Y-m' );
			$url = $this->build_month_url( $provider, $base, $n, $ym );

			$row             = $this->evaluate_url( $url );
			$row             = array( 'offset' => sprintf( '%+d', $n ), 'month' => $ym ) + $row;
			$rows[]          = $row;
		}

		if ( 'count' === $format ) {
			$this->print_counts( $rows );
			return;
		}

		$fields = ( 'table' === $format )
			? array( 'offset', 'month', 'action', 'status', 'target' )
			: array( 'offset', 'month', 'url', 'action', 'status', 'provider', 'view', 'date', 'target', 'reason' );

		\WP_CLI\Utils\format_items( $format, $rows, $fields );

		if ( 'table' === $format ) {
			$this->print_summary_line( $rows );
		}
	}

	/**
	 * List the calendars detected on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ccg providers
	 */
	public function providers(): void {
		$detected = array();
		if ( ccg_is_tec_active() ) {
			$detected[] = 'tec (The Events Calendar)';
		}
		if ( ccg_is_ai1ec_active() ) {
			$detected[] = 'ai1ec (All-in-One Event Calendar)';
		}
		if ( empty( $detected ) ) {
			WP_CLI::warning( 'No supported calendar detected.' );
			return;
		}
		foreach ( $detected as $d ) {
			WP_CLI::log( '- ' . $d );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Evaluate one URL and return a flat row.
	 *
	 * @param string $url URL.
	 * @return array<string,string|int>
	 */
	private function evaluate_url( string $url ): array {
		$engine   = $this->engine();
		$ctx      = $engine->context_from_url( $url );
		$decision = $engine->evaluate( $ctx );

		return array(
			'url'      => $url,
			'action'   => (string) $decision['action'],
			'status'   => (int) $decision['status'],
			'provider' => '' !== (string) $ctx['provider'] ? (string) $ctx['provider'] : '-',
			'view'     => '' !== (string) $ctx['view'] ? (string) $ctx['view'] : '-',
			'date'     => '' !== (string) $ctx['date'] ? (string) $ctx['date'] : '-',
			'target'   => ! empty( $decision['target'] ) ? (string) $decision['target'] : '-',
			'reason'   => html_entity_decode( wp_strip_all_tags( (string) $decision['reason'] ), ENT_QUOTES | ENT_HTML5 ),
		);
	}

	/**
	 * Build a month-view URL for the given provider.
	 *
	 * @param string $provider tec|ai1ec.
	 * @param string $base     Base URL.
	 * @param int    $offset   Month offset from now.
	 * @param string $ym       YYYY-MM.
	 * @return string
	 */
	private function build_month_url( string $provider, string $base, int $offset, string $ym ): string {
		if ( 'ai1ec' === $provider ) {
			return add_query_arg(
				array(
					'action'       => 'month',
					'month_offset' => $offset,
				),
				$base
			);
		}

		// Default: The Events Calendar (query-string form is deterministic).
		return add_query_arg(
			array(
				'post_type'    => 'tribe_events',
				'eventDisplay' => 'month',
				'eventDate'    => $ym,
			),
			$base
		);
	}

	/**
	 * Read and clean a URL list from a file path or STDIN ("-").
	 *
	 * @param string $file Path or "-".
	 * @return string[]
	 */
	private function read_urls( string $file ): array {
		if ( '-' === $file ) {
			$raw = file_get_contents( 'php://stdin' );
		} else {
			if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
				WP_CLI::error( 'Cannot read file: ' . $file );
			}
			$raw = file_get_contents( $file );
		}

		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Print a per-status / per-action count table.
	 *
	 * @param array $rows Rows.
	 */
	private function print_counts( array $rows ): void {
		$by_action = array();
		$by_status = array();
		foreach ( $rows as $r ) {
			$a               = $r['action'];
			$s               = (int) $r['status'];
			$by_action[ $a ] = ( $by_action[ $a ] ?? 0 ) + 1;
			$by_status[ $s ] = ( $by_status[ $s ] ?? 0 ) + 1;
		}

		$items = array();
		foreach ( $by_action as $action => $count ) {
			$items[] = array(
				'group' => 'action',
				'key'   => $action,
				'count' => $count,
			);
		}
		foreach ( $by_status as $status => $count ) {
			$items[] = array(
				'group' => 'status',
				'key'   => (string) $status,
				'count' => $count,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'group', 'key', 'count' ) );
		WP_CLI::log( sprintf( 'Total: %d URL(s).', count( $rows ) ) );
	}

	/**
	 * Print a one-line summary after a table.
	 *
	 * @param array $rows Rows.
	 */
	private function print_summary_line( array $rows ): void {
		$counts = array();
		foreach ( $rows as $r ) {
			$counts[ $r['action'] ] = ( $counts[ $r['action'] ] ?? 0 ) + 1;
		}
		$parts = array();
		foreach ( $counts as $action => $count ) {
			$parts[] = $count . ' ' . $action;
		}
		WP_CLI::log( sprintf( '%d URL(s): %s.', count( $rows ), implode( ', ', $parts ) ) );
	}
}
