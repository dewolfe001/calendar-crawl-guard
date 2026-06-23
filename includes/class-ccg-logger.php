<?php
/**
 * Optional logging of intercepted requests to a MySQL table.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight, opt-in request logger. Disabled by default because it adds a
 * database write per intercepted request.
 */
class CCG_Logger {

	/**
	 * Record an intercepted request.
	 *
	 * @param array $decision Decision array from the interceptor.
	 * @param array $context  Request context.
	 */
	public static function record( array $decision, array $context ): void {
		global $wpdb;

		$wpdb->insert(
			CCG_Activator::table_name(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'action'      => substr( (string) ( $decision['action'] ?? '' ), 0, 20 ),
				'status'      => (int) ( $decision['status'] ?? 0 ),
				'reason'      => substr( (string) ( $decision['reason'] ?? '' ), 0, 190 ),
				'request_url' => substr( (string) ( $context['url'] ?? '' ), 0, 512 ),
				'target_url'  => substr( (string) ( $decision['target'] ?? '' ), 0, 512 ),
				'user_agent'  => substr( (string) ( $context['ua'] ?? '' ), 0, 255 ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Summary counts for the last N hours, grouped by status.
	 *
	 * @param int $hours Look-back window.
	 * @return array<int,int> status => count
	 */
	public static function summary( int $hours = 24 ): array {
		global $wpdb;

		$table = CCG_Activator::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$table} WHERE created_at >= %s GROUP BY status", $since ),
			ARRAY_A
		);

		$out = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row['status'] ] = (int) $row['c'];
			}
		}
		return $out;
	}

	/**
	 * Most recent intercepted requests.
	 *
	 * @param int $limit Row count.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 25 ): array {
		global $wpdb;

		$table = CCG_Activator::table_name();
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT created_at, action, status, reason, request_url, target_url FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Empty the log table.
	 */
	public static function purge(): void {
		global $wpdb;
		$table = CCG_Activator::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
