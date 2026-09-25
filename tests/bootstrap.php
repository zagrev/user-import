<?php

declare(strict_types=1);
use function PHPUnit\Framework\assertSame;

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

if (! class_exists( 'WP_User' ) ) :
/**
 * A stupid mock of the WordPress WP_User class for local testing.
 */
class WP_User {
    public int $ID = 0;
    public string $user_login = '';
	public string $user_email = '';
	public string $user_pass = '';
	public string $user_nicename = '';
	public string $user_url = '';
	public string $display_name = '';
	public string $first_name = '';
	public string $last_name = '';
	public string $nickname = '';
	public string $description = '';
	public string $locale = '';
    public array $roles = [];
    public array $caps = [];

    public function __construct(
        int|string|object $id = 0,
        string $name = '',
        int $site_id = 0
    ) {
        // Basic "stupid" mapping logic
        if (is_int($id)) {
            $this->ID = $id;
        } elseif (is_string($id)) {
            $this->user_login = $id;
        } elseif (is_object($id)) {
            $this->ID = $id->ID ?? 0;
            $this->user_login = $id->user_login ?? '';
        }

        if (!empty($name)) {
            $this->user_login = $name;
        }
    }

    public function has_cap(string $capability): bool {
        return in_array($capability, $this->caps, true);
    }

	public function get_error_message(): string {
		return '';
	}
}
endif;

class User_Import_Test_Um_Fields {
	public function get_fields(): array {
		return $GLOBALS['user_import_test_state']['um_fields'];
	}
}

class User_Import_Test_Um {
	public function fields(): User_Import_Test_Um_Fields {
		return new User_Import_Test_Um_Fields();
	}
}

class User_Import_Test_Roles {
	public function get_names(): array {
		return array( 'subscriber' => 'Subscriber', 'um_member' => 'Member' );
	}
}

$GLOBALS['user_import_test_state'] = array(
	'inserted_users' => array(),
	'user_meta'      => array(),
	'updated_users'  => array(),
	'existing_users' => array(),
	'existing_emails' => array(),
	'user_register_hooks_disabled' => array(),
	'options'        => array(),
	'um_fields' => array(),
	'acf_groups' => array(),
	'acf_fields' => array(),
);

function add_action( string $hook, $callback ): void {}
function remove_all_actions( string $hook ): void { unset( $GLOBALS['wp_filter'][ $hook ] ); }
function add_users_page( ...$args ): string { return 'users_page_user-import'; }
function wp_enqueue_script( ...$args ): void {}
function wp_enqueue_style( ...$args ): void {}
function plugin_dir_url( string $file ): string { return ''; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function esc_html_e( string $text, string $domain = 'default' ): void { echo $text; }
function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr__( string $text, string $domain = 'default' ): string { return $text; }
function esc_attr_e( string $text, string $domain = 'default' ): void { echo esc_attr( $text ); }
function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function current_user_can( string $capability ): bool { return true; }
function check_admin_referer( string $action ): bool { return true; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-:]/', '', strtolower( $value ) ); }
function sanitize_file_name( string $value ): string { return preg_replace( '/[^a-zA-Z0-9._-]/', '', $value ); }
function sanitize_user( string $value, bool $strict = false ): string { return preg_replace( '/[^a-z0-9_\-\.]/i', '', $value ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function esc_textarea( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function wp_json_encode( $value ): string { return json_encode( $value ); }
function selected( $selected, $current ): void { if ( (string) $selected === (string) $current ) { echo ' selected="selected"'; } }
function get_option( string $key, $default = false ) { return $GLOBALS['user_import_test_state']['options'][ $key ] ?? $default; }
function update_option( string $key, $value ): bool { $GLOBALS['user_import_test_state']['options'][ $key ] = $value; return true; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function wp_upload_dir(): array { return array( 'basedir' => sys_get_temp_dir() . '/user-import-tests' ); }
function get_current_user_id(): int { return 1; }
function sanitize_email( string $value ): string { return trim( $value ); }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function username_exists( string $username ): bool { return in_array( $username, $GLOBALS['user_import_test_state']['existing_users'], true ); }
function email_exists( string $email ): bool { return in_array( $email, $GLOBALS['user_import_test_state']['existing_emails'], true ); }
function wp_generate_password(): string { return 'generated-password'; }
function get_role( string $role ) { return in_array( $role, array( 'subscriber', 'um_member', 'administrator' ), true ) ? (object) array( 'name' => $role ) : null; }
function wp_roles(): User_Import_Test_Roles { return new User_Import_Test_Roles(); }
function checked( $checked, $current = true, bool $echo = true ): string {
	$result = $checked === $current ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}
function wp_insert_user( array &$user_data ) {
	global $GLOBALS;

	if ( ! isset( $user_data['user_login'] ) ) {
		return false;
	}
	if ( isset( $user_data['ID'] ) ) {
		$id = $user_data['ID'];
	} else {
		$id = count( $GLOBALS['user_import_test_state']['inserted_users'] ) + 1;
	}
	$new_user = new WP_User($id, $user_data['user_login']);

	foreach (array_keys($user_data) as $key) {
		$new_user->$key = $user_data[$key];
	}

	$GLOBALS['user_import_test_state']['inserted_users'][ $id ] = $user_data;
	$GLOBALS['user_import_test_state']['user_register_hooks_disabled'][] = ! isset( $GLOBALS['wp_filter']['user_register'] );

	$GLOBALS['user_import_test_state']['existing_users'][ $id ] = $new_user;
	$GLOBALS['user_import_test_state']['existing_emails'][ $user_data['user_email'] ] = $new_user;

	return $id;
}
function get_user_by( string $field, string|int $value ) {
	switch ( $field ) {
		case 'login':
			return $GLOBALS['user_import_test_state']['existing_users'][ $value ] ?? false;
		case 'email':
			return $GLOBALS['user_import_test_state']['existing_emails'][ $value ] ?? false;
			break;
		case 'ID':
			return $GLOBALS['user_import_test_state']['existing_users'][ $value ] ?? false;
			break;
		default:
			return false;
	}
}
function wp_update_user( array $user_data ) {
	$GLOBALS['user_import_test_state']['updated_users'][] = $user_data;
	return $user_data['ID'];
}
function update_user_meta( int $user_id, string $key, string $value ): void {
	$GLOBALS['user_import_test_state']['user_meta'][ $user_id ][ $key ] = $value;
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_die( string $message ): void { throw new RuntimeException( $message ); }
function wp_nonce_field( string $action ): void {}
function submit_button( string $text, string $type, string $name ): void {}

function UM(): User_Import_Test_Um { return new User_Import_Test_Um(); }
function acf_get_field_groups(): array { return $GLOBALS['user_import_test_state']['acf_groups']; }
function acf_get_fields( $group ): array { return $GLOBALS['user_import_test_state']['acf_fields'][ $group['key'] ?? '' ] ?? array(); }

require_once dirname( __DIR__ ) . '/user-import.php';
require_once dirname( __DIR__ ) . '/logger.php';

// Test that wp_insert_user created user can be retrieved with get_user_by
$user = array(
    'user_login' => 'testuser',
    'user_email' => 'testuser@example.com',
);
$new_id = wp_insert_user( $user );
$retrieved_user = get_user_by( 'ID', $new_id );
assertSame( $user['user_email'], $retrieved_user->user_email );
assertSame( $user['user_login'], $retrieved_user->user_login );


DmbcLogger::get_instance()->set_level( DmbcLogger::LEVEL_DEBUG );
