<?php
/**
 * Uninstall User Import.
 *
 * @package UserImport
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'user_import_saved_mappings' );

$upload_dir  = wp_upload_dir();
$pending_dir = trailingslashit( $upload_dir['basedir'] ) . 'user-import-pending';
if ( is_dir( $pending_dir ) ) {
	foreach ( glob( trailingslashit( $pending_dir ) . '*.csv' ) as $pending_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing plugin-owned temporary CSV files.
		unlink( $pending_file );
	}
	// phpcs:ignore WordPress.WP.FilesystemOperations.FileSystemProperty -- Removing the empty plugin-owned temporary directory.
	rmdir( $pending_dir );
}
