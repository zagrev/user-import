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
