<?php
/**
 * Settings registration and the admin settings screen.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the options schema, the Settings API wiring, and the admin page.
 */
class CCG_Settings {

	const MENU_SLUG = 'ccg-settings';

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'             => 1,
			'window_months'       => 6,
			'skip_logged_in'      => 1,
			'date_views'          => array( 'month', 'day', 'week' ),
			'redirect_views'      => array( 'photo', 'map' ),
			'redirect_status'     => 301,
			'out_of_window_status' => 410,
			'allow_feeds'         => 1,
			'feed_status'         => 410,
			'recurring_redirect'  => 1,
			'reject_querystrings' => 1,
			'strip_params'        => array(
				'tribe-bar-date',
				'tribe-bar-search',
				'tribe-bar-geoloc',
				'tribe-bar-geoloc-lat',
				'tribe-bar-geoloc-lng',
				'eventDisplay',
				'tribe_event_display',
				'outlook-ical',
			),
			'enable_403'          => 0,
			'bad_user_agents'     => '',
			'add_canonical'       => 1,
			'noindex_nonprimary'  => 1,
			'robots_txt'          => 1,
			'cache_headers'       => 1,
			'cache_ttl'           => 3600,
			'log_enabled'         => 0,
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Get merged options.
	 *
	 * @return array
	 */
	public static function get_options(): array {
		$saved = get_option( CCG_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_ccg_purge_log', array( $this, 'handle_purge_log' ) );

		add_filter( 'plugin_action_links_' . CCG_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Flush rewrite rules once after activation (docs page permalink).
	 */
	public function maybe_flush_rewrite(): void {
		if ( get_option( 'ccg_flush_needed' ) ) {
			flush_rewrite_rules();
			delete_option( 'ccg_flush_needed' );
		}
	}

	/**
	 * Add the settings page under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Calendar Crawl Guard', 'calendar-crawl-guard' ),
			__( 'Calendar Crawl Guard', 'calendar-crawl-guard' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the setting + sanitizer.
	 */
	public function register(): void {
		register_setting(
			'ccg_group',
			CCG_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$d   = self::defaults();
		$out = self::get_options();
		$in  = is_array( $input ) ? $input : array();

		$bools = array( 'enabled', 'skip_logged_in', 'allow_feeds', 'recurring_redirect', 'reject_querystrings', 'enable_403', 'add_canonical', 'noindex_nonprimary', 'robots_txt', 'cache_headers', 'log_enabled', 'delete_on_uninstall' );
		foreach ( $bools as $b ) {
			$out[ $b ] = empty( $in[ $b ] ) ? 0 : 1;
		}

		$out['window_months'] = isset( $in['window_months'] ) ? max( 0, min( 120, (int) $in['window_months'] ) ) : $d['window_months'];
		$out['cache_ttl']     = isset( $in['cache_ttl'] ) ? max( 0, (int) $in['cache_ttl'] ) : $d['cache_ttl'];

		$status_choices = array( 301, 404, 410 );
		$rs             = isset( $in['redirect_status'] ) ? (int) $in['redirect_status'] : $d['redirect_status'];
		$out['redirect_status'] = in_array( $rs, array( 301, 302 ), true ) ? $rs : 301;

		$ow = isset( $in['out_of_window_status'] ) ? (int) $in['out_of_window_status'] : $d['out_of_window_status'];
		$out['out_of_window_status'] = in_array( $ow, $status_choices, true ) ? $ow : 410;

		$fs = isset( $in['feed_status'] ) ? (int) $in['feed_status'] : $d['feed_status'];
		$out['feed_status'] = in_array( $fs, array( 403, 404, 410 ), true ) ? $fs : 410;

		$valid_views = array( 'month', 'day', 'week', 'photo', 'map', 'list', 'past', 'upcoming', 'today', 'all' );
		foreach ( array( 'date_views', 'redirect_views' ) as $key ) {
			$vals = isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? $in[ $key ] : array();
			$out[ $key ] = array_values( array_intersect( $valid_views, array_map( 'sanitize_key', $vals ) ) );
		}

		if ( isset( $in['strip_params'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $in['strip_params'] );
			$clean = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$clean[] = sanitize_text_field( $line );
				}
			}
			$out['strip_params'] = $clean;
		}

		$out['bad_user_agents'] = isset( $in['bad_user_agents'] ) ? sanitize_textarea_field( $in['bad_user_agents'] ) : '';

		return $out;
	}

	/**
	 * Enqueue admin assets on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ccg-admin', CCG_URL . 'assets/admin.css', array(), CCG_VERSION );
		wp_enqueue_script( 'ccg-admin', CCG_URL . 'assets/admin.js', array( 'jquery' ), CCG_VERSION, true );
		wp_localize_script(
			'ccg-admin',
			'CCG',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ccg_test_url' ),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Plugin list links
	 * --------------------------------------------------------------------- */

	/**
	 * Settings link on the plugins list row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$settings = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Settings', 'calendar-crawl-guard' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Donate + docs links in the plugin row meta.
	 *
	 * @param array  $meta Existing meta links.
	 * @param string $file Plugin file.
	 * @return array
	 */
	public function row_meta( $meta, $file ): array {
		if ( CCG_BASENAME !== $file ) {
			return $meta;
		}
		$meta[] = '<a href="' . esc_url( CCG_DONATE_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Donate', 'calendar-crawl-guard' ) . '</a>';
		$docs   = CCG_Activator::get_docs_url();
		if ( $docs ) {
			$meta[] = '<a href="' . esc_url( $docs ) . '" target="_blank" rel="noopener">' . esc_html__( 'Docs', 'calendar-crawl-guard' ) . '</a>';
		}
		return $meta;
	}

	/* --------------------------------------------------------------------- *
	 * Log purge handler
	 * --------------------------------------------------------------------- */

	/**
	 * Handle the "clear log" admin-post action.
	 */
	public function handle_purge_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'calendar-crawl-guard' ) );
		}
		check_admin_referer( 'ccg_purge_log' );
		CCG_Logger::purge();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&purged=1' ) );
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * Page render
	 * --------------------------------------------------------------------- */

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::get_options();

		$all_date_views     = array( 'month', 'day', 'week' );
		$all_redirect_views = array( 'photo', 'map', 'week', 'day' );
		?>
		<div class="wrap ccg-wrap">
			<h1><?php esc_html_e( 'Calendar Crawl Guard', 'calendar-crawl-guard' ); ?></h1>
			<p class="ccg-tagline"><?php esc_html_e( 'Redirect, terminate, or feed authority to a short list of canonical calendar URLs — cutting crawler load.', 'calendar-crawl-guard' ); ?></p>

			<?php
			$detected = array();
			if ( ccg_is_tec_active() ) {
				$detected[] = 'The Events Calendar';
			}
			if ( ccg_is_ai1ec_active() ) {
				$detected[] = 'All-in-One Event Calendar';
			}
			?>
			<?php if ( empty( $detected ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No supported calendar is active. Rules will not run until The Events Calendar or the All-in-One Event Calendar is installed and enabled.', 'calendar-crawl-guard' ); ?></p></div>
			<?php else : ?>
				<p class="ccg-detected"><?php esc_html_e( 'Detected calendar(s):', 'calendar-crawl-guard' ); ?> <strong><?php echo esc_html( implode( ', ', $detected ) ); ?></strong></p>
			<?php endif; ?>

			<div class="ccg-layout">
				<div class="ccg-main">
					<form method="post" action="options.php">
						<?php settings_fields( 'ccg_group' ); ?>

						<h2 class="title"><?php esc_html_e( 'Core', 'calendar-crawl-guard' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable crawl guard', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?>> <?php esc_html_e( 'Master switch', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><label for="ccg-window"><?php esc_html_e( 'Canonical window (months)', 'calendar-crawl-guard' ); ?></label></th>
								<td>
									<input type="number" min="0" max="120" id="ccg-window" name="<?php echo esc_attr( CCG_OPTION ); ?>[window_months]" value="<?php echo esc_attr( $o['window_months'] ); ?>" class="small-text">
									<p class="description"><?php esc_html_e( 'Keep the current month plus/minus this many months crawlable. Dates outside the window are rejected.', 'calendar-crawl-guard' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Skip logged-in users', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[skip_logged_in]" value="1" <?php checked( $o['skip_logged_in'], 1 ); ?>> <?php esc_html_e( 'Never intercept logged-in users', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
						</table>

						<h2 class="title"><?php esc_html_e( 'Date window &amp; views', 'calendar-crawl-guard' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Date-checked views', 'calendar-crawl-guard' ); ?></th>
								<td>
									<?php foreach ( $all_date_views as $v ) : ?>
										<label class="ccg-inline"><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[date_views][]" value="<?php echo esc_attr( $v ); ?>" <?php checked( in_array( $v, (array) $o['date_views'], true ) ); ?>> <?php echo esc_html( $v ); ?></label>
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'These views are subject to the canonical window.', 'calendar-crawl-guard' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ccg-oow"><?php esc_html_e( 'Out-of-window response', 'calendar-crawl-guard' ); ?></label></th>
								<td>
									<select id="ccg-oow" name="<?php echo esc_attr( CCG_OPTION ); ?>[out_of_window_status]">
										<option value="410" <?php selected( $o['out_of_window_status'], 410 ); ?>>410 Gone (recommended)</option>
										<option value="404" <?php selected( $o['out_of_window_status'], 404 ); ?>>404 Not Found</option>
										<option value="301" <?php selected( $o['out_of_window_status'], 301 ); ?>>301 to archive</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Redirected views', 'calendar-crawl-guard' ); ?></th>
								<td>
									<?php foreach ( $all_redirect_views as $v ) : ?>
										<label class="ccg-inline"><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[redirect_views][]" value="<?php echo esc_attr( $v ); ?>" <?php checked( in_array( $v, (array) $o['redirect_views'], true ) ); ?>> <?php echo esc_html( $v ); ?></label>
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'Alternate presentation views that 301 to the primary (list) view of the same scope.', 'calendar-crawl-guard' ); ?></p>
								</td>
							</tr>
						</table>

						<h2 class="title"><?php esc_html_e( 'Duplicates &amp; feeds', 'calendar-crawl-guard' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Strip duplicate query strings', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[reject_querystrings]" value="1" <?php checked( $o['reject_querystrings'], 1 ); ?>> <?php esc_html_e( '301 to a clean URL when filter parameters are present (pretty permalinks only)', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><label for="ccg-strip"><?php esc_html_e( 'Parameters to strip', 'calendar-crawl-guard' ); ?></label></th>
								<td><textarea id="ccg-strip" rows="6" class="large-text code" name="<?php echo esc_attr( CCG_OPTION ); ?>[strip_params]"><?php echo esc_textarea( implode( "\n", (array) $o['strip_params'] ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'One parameter name per line.', 'calendar-crawl-guard' ); ?></p></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Redirect recurring instances', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[recurring_redirect]" value="1" <?php checked( $o['recurring_redirect'], 1 ); ?>> <?php esc_html_e( '301 per-date and /all/ permalinks to the base event', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Allow iCal feeds', 'calendar-crawl-guard' ); ?></th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[allow_feeds]" value="1" <?php checked( $o['allow_feeds'], 1 ); ?>> <?php esc_html_e( 'Serve feeds normally', 'calendar-crawl-guard' ); ?></label>
									<label class="ccg-sub" for="ccg-feed"><?php esc_html_e( 'If not allowed, respond with:', 'calendar-crawl-guard' ); ?>
										<select id="ccg-feed" name="<?php echo esc_attr( CCG_OPTION ); ?>[feed_status]">
											<option value="410" <?php selected( $o['feed_status'], 410 ); ?>>410</option>
											<option value="404" <?php selected( $o['feed_status'], 404 ); ?>>404</option>
											<option value="403" <?php selected( $o['feed_status'], 403 ); ?>>403</option>
										</select>
									</label>
								</td>
							</tr>
						</table>

						<h2 class="title"><?php esc_html_e( 'Authority signals', 'calendar-crawl-guard' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Canonical tags', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[add_canonical]" value="1" <?php checked( $o['add_canonical'], 1 ); ?>> <?php esc_html_e( 'Add rel=canonical on kept archive views (skipped if Yoast / Rank Math is active)', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Noindex non-primary views', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[noindex_nonprimary]" value="1" <?php checked( $o['noindex_nonprimary'], 1 ); ?>> <?php esc_html_e( 'Hint noindex on dated / paginated views via The Events Calendar filter', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'robots.txt rules', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[robots_txt]" value="1" <?php checked( $o['robots_txt'], 1 ); ?>> <?php esc_html_e( 'Disallow feeds and filter parameters in the virtual robots.txt', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
						</table>

						<h2 class="title"><?php esc_html_e( 'Performance &amp; bots', 'calendar-crawl-guard' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Cache headers on rejects', 'calendar-crawl-guard' ); ?></th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[cache_headers]" value="1" <?php checked( $o['cache_headers'], 1 ); ?>> <?php esc_html_e( 'Send Cache-Control / Expires so a front cache can serve repeats', 'calendar-crawl-guard' ); ?></label>
									<label class="ccg-sub" for="ccg-ttl"><?php esc_html_e( 'TTL (seconds):', 'calendar-crawl-guard' ); ?>
										<input type="number" min="0" id="ccg-ttl" class="small-text" name="<?php echo esc_attr( CCG_OPTION ); ?>[cache_ttl]" value="<?php echo esc_attr( $o['cache_ttl'] ); ?>">
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Block bad user agents (403)', 'calendar-crawl-guard' ); ?></th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[enable_403]" value="1" <?php checked( $o['enable_403'], 1 ); ?>> <?php esc_html_e( 'Return 403 for matching user agents', 'calendar-crawl-guard' ); ?></label>
									<textarea rows="4" class="large-text code" name="<?php echo esc_attr( CCG_OPTION ); ?>[bad_user_agents]" placeholder="<?php esc_attr_e( 'One substring per line, e.g. SemrushBot', 'calendar-crawl-guard' ); ?>"><?php echo esc_textarea( $o['bad_user_agents'] ); ?></textarea>
								</td>
							</tr>
						</table>

						<h2 class="title"><?php esc_html_e( 'Logging', 'calendar-crawl-guard' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable logging', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[log_enabled]" value="1" <?php checked( $o['log_enabled'], 1 ); ?>> <?php esc_html_e( 'Record intercepted requests (adds one DB write per intercept)', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'calendar-crawl-guard' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( CCG_OPTION ); ?>[delete_on_uninstall]" value="1" <?php checked( $o['delete_on_uninstall'], 1 ); ?>> <?php esc_html_e( 'Delete settings, log table, and docs page when the plugin is deleted', 'calendar-crawl-guard' ); ?></label></td>
							</tr>
						</table>

						<?php submit_button(); ?>
					</form>

					<?php $this->render_log_panel( $o ); ?>
				</div>

				<div class="ccg-side">
					<div class="ccg-card">
						<h3><?php esc_html_e( 'Test a URL', 'calendar-crawl-guard' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Paste any event URL to preview the verdict using your current (saved) settings.', 'calendar-crawl-guard' ); ?></p>
						<input type="text" id="ccg-test-url" class="widefat" placeholder="<?php echo esc_attr( home_url( '/' . ccg_events_slug() . '/2030-01/' ) ); ?>">
						<button type="button" class="button button-secondary" id="ccg-test-btn"><?php esc_html_e( 'Test', 'calendar-crawl-guard' ); ?></button>
						<div id="ccg-test-result" class="ccg-result" hidden></div>
					</div>

					<div class="ccg-card">
						<h3><?php esc_html_e( 'Documentation', 'calendar-crawl-guard' ); ?></h3>
						<?php
						$docs_view = CCG_Activator::get_docs_url();
						$docs_edit = CCG_Activator::get_docs_edit_url();
						?>
						<p>
							<?php if ( $docs_view ) : ?>
								<a class="button button-secondary" href="<?php echo esc_url( $docs_view ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Docs Page', 'calendar-crawl-guard' ); ?></a>
							<?php endif; ?>
							<?php if ( $docs_edit ) : ?>
								<a class="button" href="<?php echo esc_url( $docs_edit ); ?>"><?php esc_html_e( 'Edit Docs Page', 'calendar-crawl-guard' ); ?></a>
							<?php endif; ?>
						</p>
					</div>

					<div class="ccg-card ccg-donate">
						<h3><?php esc_html_e( 'Support this plugin', 'calendar-crawl-guard' ); ?></h3>
						<p><?php esc_html_e( 'Built by Web321 Marketing Ltd. If it saved your server some grief, a small donation keeps it maintained.', 'calendar-crawl-guard' ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( CCG_DONATE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Donate via PayPal', 'calendar-crawl-guard' ); ?></a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the optional log summary panel.
	 *
	 * @param array $o Options.
	 */
	private function render_log_panel( array $o ): void {
		if ( empty( $o['log_enabled'] ) ) {
			return;
		}
		$summary = CCG_Logger::summary( 24 );
		$recent  = CCG_Logger::recent( 25 );
		$total   = array_sum( $summary );
		?>
		<h2 class="title"><?php esc_html_e( 'Last 24 hours', 'calendar-crawl-guard' ); ?></h2>
		<?php if ( isset( $_GET['purged'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Log cleared.', 'calendar-crawl-guard' ); ?></p></div>
		<?php endif; ?>
		<p><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong> <?php esc_html_e( 'requests intercepted.', 'calendar-crawl-guard' ); ?>
			<?php foreach ( $summary as $status => $count ) : ?>
				<span class="ccg-pill">HTTP <?php echo esc_html( $status ); ?>: <?php echo esc_html( number_format_i18n( $count ) ); ?></span>
			<?php endforeach; ?>
		</p>
		<?php if ( $recent ) : ?>
			<table class="widefat striped ccg-log">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'calendar-crawl-guard' ); ?></th>
					<th><?php esc_html_e( 'Status', 'calendar-crawl-guard' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'calendar-crawl-guard' ); ?></th>
					<th><?php esc_html_e( 'Request', 'calendar-crawl-guard' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $recent as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( wp_strip_all_tags( $row['reason'] ) ); ?></td>
						<td class="ccg-url"><?php echo esc_html( $row['request_url'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ccg_purge_log">
				<?php wp_nonce_field( 'ccg_purge_log' ); ?>
				<?php submit_button( __( 'Clear log', 'calendar-crawl-guard' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
