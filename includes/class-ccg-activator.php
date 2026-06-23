<?php
/**
 * Activation / deactivation handling, docs page lifecycle, and docs URL helpers.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation tasks: the auto-generated documentation page and the
 * optional logging table.
 */
class CCG_Activator {

	const DOCS_SLUG = 'calendar-crawl-guard-docs';

	/**
	 * Logging table name (without prefix).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ccg_log';
	}

	/**
	 * Run on activation.
	 */
	public static function activate(): void {
		self::maybe_create_docs_page();
		self::maybe_create_table();
		add_option( 'ccg_flush_needed', 1 );
	}

	/**
	 * Run on deactivation.
	 */
	public static function deactivate(): void {
		// Intentionally non-destructive. The docs page and table are preserved
		// so settings/content survive a deactivate/reactivate cycle. Removal is
		// handled by uninstall.php when the user opts in.
		flush_rewrite_rules();
	}

	/**
	 * Idempotently create the public documentation page.
	 *
	 * Re-creates the page only if the stored ID is missing or trashed, so a
	 * deactivate/reactivate cycle restores a trashed docs page.
	 */
	public static function maybe_create_docs_page(): void {
		$existing_id = (int) get_option( CCG_DOCS_OPTION, 0 );

		if ( $existing_id ) {
			$page = get_post( $existing_id );
			if ( $page && 'trash' !== $page->post_status ) {
				return; // Healthy page already exists.
			}
		}

		$page_id = wp_insert_post(
			array(
				'post_title'     => 'Calendar Crawl Guard — Documentation',
				'post_name'      => self::DOCS_SLUG,
				'post_content'   => CCG_Docs::get_content(),
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_post_meta( $page_id, '_managed_page', 'calendar-crawl-guard' );
			update_option( CCG_DOCS_OPTION, (int) $page_id );
		}
	}

	/**
	 * Create the logging table (MySQL).
	 */
	public static function maybe_create_table(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			action VARCHAR(20) NOT NULL DEFAULT '',
			status SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			reason VARCHAR(190) NOT NULL DEFAULT '',
			request_url VARCHAR(512) NOT NULL DEFAULT '',
			target_url VARCHAR(512) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Front-end permalink of the docs page.
	 *
	 * @return string
	 */
	public static function get_docs_url(): string {
		$id = (int) get_option( CCG_DOCS_OPTION, 0 );
		if ( $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * wp-admin edit link of the docs page.
	 *
	 * @return string
	 */
	public static function get_docs_edit_url(): string {
		$id = (int) get_option( CCG_DOCS_OPTION, 0 );
		if ( $id ) {
			return admin_url( 'post.php?post=' . $id . '&action=edit' );
		}
		return '';
	}
}
