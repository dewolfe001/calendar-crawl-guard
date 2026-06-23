<?php
/**
 * Documentation page content.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates the full HTML stored in the auto-created documentation page.
 */
class CCG_Docs {

	/**
	 * Build the documentation page HTML.
	 *
	 * @return string
	 */
	public static function get_content(): string {
		$wp_link = 'https://web321.co/knowledgebase/wordpress/';

		$html  = '<h2>What this plugin does</h2>';
		$html .= '<p>Event calendars generate an enormous URL space. Every calendar view can be combined with almost any date, and each view renders "previous" and "next" links — so a crawler can walk forward and backward through dates indefinitely. Each of those is a distinct, usually empty, dynamic page that fires PHP and queries the database. The result is wasted bandwidth, high server load, and crawl budget spent on pages with no real content.</p>';
		$html .= '<p><strong>Calendar Crawl Guard</strong> supports both <strong>The Events Calendar</strong> and the <strong>All-in-One Event Calendar</strong> (Timely). It intercepts these requests early in the ';
		$html .= '<a href="' . esc_url( $wp_link ) . '" target="_new" rel="noopener">WordPress</a> ';
		$html .= 'request lifecycle — before the main database query runs — and applies one of three responses:</p>';
		$html .= '<ul>';
		$html .= '<li><strong>301 redirect</strong> for alternate-but-equivalent paths that have a clear canonical target (duplicate query strings, alternate presentation views, recurring-event instance permalinks).</li>';
		$html .= '<li><strong>410 Gone</strong> (or 404) for the infinite date tree — calendar dates outside a sensible window. 410 tells crawlers to drop the URL and stop revisiting.</li>';
		$html .= '<li><strong>403 Forbidden</strong> (optional) for known bad bots, matched by user-agent.</li>';
		$html .= '</ul>';
		$html .= '<p>It also feeds indexing authority to your canonical pages using canonical tags and <code>noindex</code> hints on non-primary views, and can add targeted <code>robots.txt</code> rules for feeds and filter parameters.</p>';

		$html .= '<h2>Supported calendars</h2>';
		$html .= '<p>Both calendars are detected automatically; the matching rules run only for whichever is active. Each calendar&rsquo;s native views are normalized to a common vocabulary so one set of settings governs both:</p>';
		$html .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">';
		$html .= '<thead><tr><th align="left">Normalized view</th><th align="left">The Events Calendar</th><th align="left">All-in-One Event Calendar</th><th align="left">Handling</th></tr></thead>';
		$html .= '<tbody>';
		$html .= '<tr><td>month</td><td>month</td><td>month</td><td>date-checked</td></tr>';
		$html .= '<tr><td>day</td><td>day</td><td>oneday</td><td>date-checked</td></tr>';
		$html .= '<tr><td>week</td><td>week</td><td>week</td><td>date-checked</td></tr>';
		$html .= '<tr><td>list</td><td>list / upcoming / past</td><td>agenda</td><td>primary (kept)</td></tr>';
		$html .= '<tr><td>photo</td><td>photo</td><td>posterboard</td><td>redirected to primary</td></tr>';
		$html .= '<tr><td>map</td><td>map</td><td>stream</td><td>redirected to primary</td></tr>';
		$html .= '</tbody></table>';
		$html .= '<p>For the All-in-One Event Calendar the date is taken from <code>exact_date</code> or derived from the <code>month_offset</code> / <code>week_offset</code> / <code>oneday_offset</code> navigation parameters, so deep forward/backward navigation falls outside the window and is rejected.</p>';

		$html .= '<h2>Setup steps</h2>';
		$html .= '<ol>';
		$html .= '<li>Make sure The Events Calendar or the All-in-One Event Calendar is installed and active.</li>';
		$html .= '<li>Go to <strong>Settings &rarr; Calendar Crawl Guard</strong>.</li>';
		$html .= '<li>Confirm the <strong>canonical window</strong> (default: current month, &plusmn;6 months). Dates outside this window are rejected.</li>';
		$html .= '<li>Review which views are <strong>kept</strong>, <strong>redirected</strong>, and <strong>date-checked</strong>. The defaults are sensible for most sites.</li>';
		$html .= '<li>Use the <strong>Test a URL</strong> tool on the settings page to confirm the verdict for any calendar URL before going live.</li>';
		$html .= '<li>Enable the plugin (the master switch) and watch your server logs / analytics for the drop in crawler hits.</li>';
		$html .= '</ol>';
		$html .= '<p><strong>Recommended:</strong> keep a page cache (Varnish, NGINX FastCGI cache, or a caching plugin) in front of the site, and leave "Send cache headers on rejected responses" enabled so repeat bot hits are served from cache without ever touching PHP.</p>';

		$html .= '<h2>Configuration reference</h2>';
		$html .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">';
		$html .= '<thead><tr><th align="left">Setting</th><th align="left">Default</th><th align="left">What it controls</th></tr></thead>';
		$html .= '<tbody>';
		$html .= self::row( 'Enable crawl guard', 'On', 'Master switch. When off, no requests are intercepted.' );
		$html .= self::row( 'Canonical window (months)', '6', 'How many months before and after the current month stay crawlable. Calendar dates outside this range are rejected.' );
		$html .= self::row( 'Skip logged-in users', 'On', 'Never intercept requests from logged-in users so editors can browse freely.' );
		$html .= self::row( 'Date-checked views', 'month, day, week', 'Views that map to a specific date and are subject to the canonical window.' );
		$html .= self::row( 'Out-of-window response', '410 Gone', 'Status returned for dates outside the window. 410, 404, or 301 to the archive.' );
		$html .= self::row( 'Redirected views', 'photo, map', 'Alternate presentation views that 301 to the primary (list) view of the same scope.' );
		$html .= self::row( 'Strip duplicate query strings', 'On', '301-redirects URLs carrying the Tribe Bar / view filter parameters to a clean canonical URL (only when pretty permalinks are active).' );
		$html .= self::row( 'Redirect recurring instances', 'On', '301-redirects per-date and /all/ recurring-event permalinks to the base event.' );
		$html .= self::row( 'Allow iCal feeds', 'On', 'When off, iCal / Outlook feed URLs are rejected. When on, feeds are allowed and (optionally) disallowed in robots.txt.' );
		$html .= self::row( 'Block bad user agents (403)', 'Off', 'Optional. Returns 403 for requests whose user-agent matches your block list.' );
		$html .= self::row( 'Add canonical tags', 'On', 'Outputs a rel=canonical link on kept event archive views (skipped if Yoast or Rank Math is managing canonicals).' );
		$html .= self::row( 'Noindex non-primary views', 'On', 'Adds a noindex hint to kept-but-secondary views (dated month/day/week, paginated pages) via The Events Calendar&rsquo;s own filter.' );
		$html .= self::row( 'robots.txt rules', 'On', 'Appends Disallow rules for feeds and filter parameters to the virtual robots.txt.' );
		$html .= self::row( 'Send cache headers on rejects', 'On', 'Adds Cache-Control / Expires headers to 301 / 404 / 410 / 403 responses so a front cache can serve repeats.' );
		$html .= self::row( 'Cache TTL (seconds)', '3600', 'Lifetime for the cache headers above.' );
		$html .= self::row( 'Enable logging', 'Off', 'Records intercepted requests to a database table for review. Adds a write per intercept — leave off during heavy bot storms unless you need the data.' );
		$html .= '</tbody></table>';

		$html .= '<h2>Troubleshooting</h2>';
		$html .= '<h3>A page I want indexed is being rejected</h3>';
		$html .= '<p>Use the <strong>Test a URL</strong> tool to see the verdict and the reason. Widen the canonical window, remove the relevant view from the "date-checked" or "redirected" lists, or switch the out-of-window response to a 301 so authority is preserved.</p>';
		$html .= '<h3>I see a redirect loop</h3>';
		$html .= '<p>The engine never redirects a URL to itself, but if you have another redirect plugin or server rule touching the same paths, disable one of them. Confirm your primary (list) view URL resolves to a 200.</p>';
		$html .= '<h3>Redirects are not consolidating in Google Search Console</h3>';
		$html .= '<p>Make sure you have <em>not</em> also disallowed those URLs in robots.txt — a disallowed URL can never be crawled, so the 301/410 is never seen. Reserve robots.txt rules for feeds and filter parameters only.</p>';
		$html .= '<h3>Logged-in editing is being interrupted</h3>';
		$html .= '<p>Keep "Skip logged-in users" enabled.</p>';

		$html .= '<h2>FAQ</h2>';
		$html .= '<h3>Will this slow my site down?</h3>';
		$html .= '<p>The opposite. Rejected requests exit before the main query and template render, so they cost far less than a normal page load. With cache headers enabled, repeat hits are served from your cache.</p>';
		$html .= '<h3>Why 410 instead of 404 for old dates?</h3>';
		$html .= '<p>410 Gone is a permanent signal: crawlers drop the URL and stop revisiting. 404 invites them to try again later.</p>';
		$html .= '<h3>Does it touch single event pages?</h3>';
		$html .= '<p>No — single events have real content and are always allowed. Only recurring-event <em>instance</em> permalinks (a specific date, or /all/) are redirected to the base event, and only if you enable that option.</p>';
		$html .= '<h3>Does it work with query-string (plain) permalinks?</h3>';
		$html .= '<p>Yes. Date-window rejection still applies. Query-string canonicalization is skipped when pretty permalinks are off, because in that mode the query string <em>is</em> the canonical URL.</p>';

		$html .= '<h2>WP-CLI</h2>';
		$html .= '<p>For bulk checking and boundary verification, the plugin registers a <code>ccg</code> command group:</p>';
		$html .= '<ul>';
		$html .= '<li><code>wp ccg test &lt;url&gt;...</code> — run one or more URLs through the same decision engine the live hook uses. Accepts <code>--file=&lt;path&gt;</code> (or <code>--file=-</code> for STDIN), <code>--only=allow,redirect,reject</code>, and <code>--format=table|csv|json|count</code>.</li>';
		$html .= '<li><code>wp ccg scan</code> — generate month views across a range of offsets and report each verdict, so you can see exactly where the canonical window flips from allow to reject. Accepts <code>--provider=tec|ai1ec</code>, <code>--months=&lt;n&gt;</code>, <code>--base=&lt;url&gt;</code>, and <code>--format</code>.</li>';
		$html .= '<li><code>wp ccg providers</code> — list the calendars detected on the site.</li>';
		$html .= '</ul>';
		$html .= '<p>Example: <code>wp ccg test --file=urls.txt --only=reject --format=csv</code> exports just the URLs that would be terminated.</p>';

		$html .= '<h2>Support</h2>';
		$html .= '<p>Built and maintained by Web321 Marketing Ltd.<br>';
		$html .= 'Web: <a href="https://web321.co/" target="_new" rel="noopener">web321.co</a><br>';
		$html .= 'Email: <a href="mailto:shawn@web321.co">shawn@web321.co</a><br>';
		$html .= 'Phone: (250) 661-4834<br>';
		$html .= 'Saanichton, BC, Canada</p>';
		$html .= '<p>If this plugin saved your server some grief, you can ';
		$html .= '<a href="' . esc_url( CCG_DONATE_URL ) . '" target="_new" rel="noopener">support development with a donation</a>.</p>';

		return $html;
	}

	/**
	 * Build one reference-table row.
	 *
	 * @param string $setting Setting label.
	 * @param string $default Default value.
	 * @param string $desc    Description.
	 * @return string
	 */
	private static function row( string $setting, string $default, string $desc ): string {
		return '<tr><td><strong>' . esc_html( $setting ) . '</strong></td><td>' . esc_html( $default ) . '</td><td>' . esc_html( $desc ) . '</td></tr>';
	}
}
