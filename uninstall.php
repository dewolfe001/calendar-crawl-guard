<?php
/**
 * Uninstall handler. Removes plugin data only when the user opted in via the
 * "Remove data on uninstall" setting.
 *
 * @package Web321\CalendarCrawlGuard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = get_option( 'ccg_settings', array() );

if ( empty( $options['delete_on_uninstall'] ) ) {
	return; // Leave everything in place.
}

global $wpdb;

// Drop the log table.
$table = $wpdb->prefix . 'ccg_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Remove the managed docs page.
$docs_id = (int) get_option( 'ccg_docs_page_id', 0 );
if ( $docs_id ) {
	wp_delete_post( $docs_id, true );
}

// Remove options.
delete_option( 'ccg_settings' );
delete_option( 'ccg_docs_page_id' );
delete_option( 'ccg_flush_needed' );
