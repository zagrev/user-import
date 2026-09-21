<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = ''
	) {}

	public function get_error_message(): string {
		return $this->message;
	}
}

$GLOBALS['user_import_test_state'] = array(
	'inserted_users' => array(),
	'user_meta'      => array(),
	'existing_users' => array(),
	'existing_emails' => array(),
);

function add_action( string $hook, $callback ): void {}
function add_users_page( ...$args ): string { return 'users_page_user-import'; }
function wp_enqueue_script( ...$args ): void {}
function wp_enqueue_style( ...$args ): void {}
function plugin_dir_url( string $file ): string { return ''; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr__( string $text, string $domain = 'default' ): string { return $text; }
function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function current_user_can( string $capability ): bool { return true; }
function check_admin_referer( string $action ): bool { return true; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-:]/', '', strtolower( $value ) ); }
function sanitize_user( string $value, bool $strict = false ): string { return preg_replace( '/[^a-z0-9_\-\.]/i', '', $value ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function username_exists( string $username ): bool { return in_array( $username, $GLOBALS['user_import_test_state']['existing_users'], true ); }
function email_exists( string $email ): bool { return in_array( $email, $GLOBALS['user_import_test_state']['existing_emails'], true ); }
function wp_generate_password(): string { return 'generated-password'; }
function get_role( string $role ) { return in_array( $role, array( 'subscriber', 'um_member', 'administrator' ), true ) ? (object) array( 'name' => $role ) : null; }
function wp_insert_user( array $user_data ) {
	$id = count( $GLOBALS['user_import_test_state']['inserted_users'] ) + 1;
	$GLOBALS['user_import_test_state']['inserted_users'][ $id ] = $user_data;
	return $id;
}
function update_user_meta( int $user_id, string $key, string $value ): void {
	$GLOBALS['user_import_test_state']['user_meta'][ $user_id ][ $key ] = $value;
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_die( string $message ): void { throw new RuntimeException( $message ); }
function wp_nonce_field( string $action ): void {}
function submit_button( string $text, string $type, string $name ): void {}

require_once dirname( __DIR__ ) . '/user-import.php';
