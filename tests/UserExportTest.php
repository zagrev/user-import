<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserExportTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
		$GLOBALS['user_import_test_state'] = array(
			'inserted_users'  => array(),
			'user_meta'       => array(),
			'updated_users'   => array(),
			'existing_users'  => array(),
			'existing_emails' => array(),
			'user_register_hooks_disabled' => array(),
			'options'         => array(),
			'um_fields'       => array(),
			'acf_groups'      => array(),
			'acf_fields'      => array(),
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_export_starts_with_user_selection_without_import_controls(): void {
		$_POST = array();
		ob_start();
		User_Export_Plugin::render_export_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Step 1: Select users', $html );
		$this->assertStringContainsString( 'Search query', $html );
		$this->assertStringContainsString( 'aria-describedby="user-export-query-examples"', $html );
		$this->assertStringContainsString( '<code>alex</code>', $html );
		$this->assertStringContainsString( '<code>alex@example.com</code>', $html );
		$this->assertStringContainsString( '<code>@example.com</code>', $html );
		$this->assertStringContainsString( '<code>Alex Smith</code>', $html );
		$this->assertStringContainsString( 'SQL and field:value expressions are not supported.', $html );
		$this->assertStringContainsString( 'value="role:um_member"', $html );
		$this->assertStringNotContainsString( 'user_import_csv', $html );
		$this->assertStringNotContainsString( 'user_import_mapping', $html );
	}

	public function test_export_preserves_selection_and_field_order_across_steps(): void {
		$_POST = array(
			'user_export_step' => 'fields',
			'user_export_source' => 'group',
			'user_export_group' => 'role:um_member',
			'user_export_fields' => array( 'last_name', 'user_email' ),
		);
		ob_start();
		User_Export_Plugin::render_export_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Step 2: Select and order fields', $html );
		$this->assertStringContainsString( 'draggable="true"', $html );
		$this->assertStringNotContainsString( 'value="user_pass"', $html );
		$selected = substr( $html, strpos( $html, 'id="user-export-selected"' ) );
		$this->assertLessThan( strpos( $selected, 'data-field="user_email"' ), strpos( $selected, 'data-field="last_name"' ) );
		foreach ( array( 'download', 'fields', 'selection' ) as $step ) {
			$_POST['user_export_step'] = $step;
			ob_start();
			User_Export_Plugin::render_export_page();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'role:um_member', $html );
			$this->assertLessThan( strpos( $html, 'name="user_export_fields[]" value="user_email"' ), strpos( $html, 'name="user_export_fields[]" value="last_name"' ) );
		}
		$this->assertSame( 'um_member', $GLOBALS['user_import_test_state']['export_queries'][0]['role'] );
		$_POST = array();
	}

	public function test_export_fields_are_sorted_naturally_by_label(): void {
		$GLOBALS['user_import_test_state']['um_fields'] = array(
			'field_10' => array( 'title' => 'Field 10' ),
			'field_2'  => array( 'title' => 'Field 2' ),
		);
		$method = new ReflectionMethod( User_Export_Plugin::class, 'get_export_fields' );
		$fields = $method->invoke( null );
		$this->assertSame(
			array( 'Description', 'Display name', 'Email', 'First name', 'Last name', 'Locale', 'Nicename', 'Nickname', 'Registered date', 'Roles', 'UM: Field 2', 'UM: Field 10', 'User ID', 'Username', 'Website URL' ),
			array_values( $fields )
		);
		$this->assertSame( 'Email', $fields['user_email'] );
		$this->assertSame( 'UM: Field 2', $fields['meta:field_2'] );
	}

	public function test_export_rejects_invalid_selection_and_empty_fields(): void {
		foreach ( array( array( 'group', 'role:missing', '', 'Select an available group or role.' ), array( 'query', '', '', 'Enter a user search query.' ), array( 'group', '', '', 'Select at least one user field.' ) ) as $case ) {
			$_POST = array( 'user_export_step' => 'download', 'user_export_source' => $case[0], 'user_export_group' => $case[1], 'user_export_query' => $case[2] );
			ob_start();
			User_Export_Plugin::render_export_page();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( $case[3], $html );
			$this->assertStringNotContainsString( 'Step 3:', $html );
		}
		$_POST = array();
	}

	public function test_export_allows_only_known_fields_and_keeps_posted_order(): void {
		$_POST = array( 'user_export_fields' => array( 'last_name', 'user_pass', 'user_email', 'last_name', 'meta:session_tokens', array( 'bad' ) ) );
		$method = new ReflectionMethod( User_Export_Plugin::class, 'get_export_state' );
		$this->assertSame( array( 'last_name', 'user_email' ), $method->invoke( null )['fields'] );
		$_POST = array();
	}

	public function test_export_csv_uses_live_users_custom_fields_and_selected_order(): void {
		$user = new WP_User( 7 );
		$user->user_email = 'alex@example.com';
		$user->last_name = '=HYPERLINK("https://example.com")';
		$user->roles = array( 'um_member' );
		$GLOBALS['user_import_test_state']['existing_users'][7] = $user;
		$GLOBALS['user_import_test_state']['user_meta'][7]['membership'] = array( 'active', 'paid' );
		$other = new WP_User( 8 );
		$other->user_email = 'other@example.com';
		$other->roles = array( 'subscriber' );
		$GLOBALS['user_import_test_state']['existing_users'][8] = $other;
		$method = new ReflectionMethod( User_Export_Plugin::class, 'write_users_csv' );
		$output = fopen( 'php://temp', 'w+' );
		$method->invoke( null, $output, array( 'role' => 'um_member' ), array( 'last_name', 'user_email', 'meta:membership', 'roles' ) );
		rewind( $output );
		$this->assertSame( array( 'last_name', 'user_email', 'meta:membership', 'roles' ), fgetcsv( $output, escape: '' ) );
		$this->assertSame( array( "'" . $user->last_name, 'alex@example.com', '["active","paid"]', '["um_member"]' ), fgetcsv( $output, escape: '' ) );
		$this->assertFalse( fgetcsv( $output, escape: '' ) );
		fclose( $output );
	}

	public function test_export_query_matches_names_emails_and_logins(): void {
		$method = new ReflectionMethod( User_Export_Plugin::class, 'get_export_query_args' );
		$args = $method->invoke( null, array( 'source' => 'query', 'query' => 'Alex', 'group' => 'role:subscriber' ) );
		$this->assertSame( '*Alex*', $args['search'] );
		$this->assertSame( array( 'user_login', 'user_email', 'user_nicename', 'display_name' ), $args['search_columns'] );
		$this->assertArrayNotHasKey( 'role', $args );
	}

	public function test_export_mailster_groups_include_only_linked_users_and_empty_groups_match_nobody(): void {
		$previous_database = $GLOBALS['wpdb'] ?? null;
		$database = new class {
			public string $prefix = 'wp_';
			public array $ids = array( '7', '9' );
			public string $membership_query = '';
			public function esc_like( string $value ): string { return $value; }
			public function prepare( string $sql, $value ): string { return str_replace( array( '%s', '%d' ), (string) $value, $sql ); }
			public function get_var( string $sql ): string { return 'wp_mailster_groups'; }
			public function get_results( string $sql ): array { return array( (object) array( 'id' => 3, 'name' => 'Members' ) ); }
			public function get_col( string $sql ): array { $this->membership_query = $sql; return $this->ids; }
		};
		$GLOBALS['wpdb'] = $database;
		try {
			$groups = new ReflectionMethod( User_Export_Plugin::class, 'get_export_groups' );
			$this->assertSame( 'WP Mailster: Members', $groups->invoke( null )['mailster:3'] );
			$method = new ReflectionMethod( User_Export_Plugin::class, 'get_export_query_args' );
			$state = array( 'source' => 'group', 'group' => 'mailster:3', 'query' => '' );
			$this->assertSame( array( 7, 9 ), $method->invoke( null, $state )['include'] );
			$this->assertStringContainsString( 'group_id = 3 AND is_core_user = 1', $database->membership_query );
			$database->ids = array();
			$this->assertSame( array( 0 ), $method->invoke( null, $state )['include'] );
		} finally {
			$GLOBALS['wpdb'] = $previous_database;
		}
	}

	public function test_export_batches_users_without_missing_rows(): void {
		foreach ( range( 1, 501 ) as $id ) {
			$GLOBALS['user_import_test_state']['existing_users'][ $id ] = new WP_User( $id );
		}
		$method = new ReflectionMethod( User_Export_Plugin::class, 'write_users_csv' );
		$output = fopen( 'php://temp', 'w+' );
		$method->invoke( null, $output, array(), array( 'ID' ) );
		rewind( $output );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $output, escape: '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $output );
		$this->assertCount( 502, $rows );
		$this->assertSame( array( '501' ), $rows[501] );
		$this->assertSame( array( 0, 500 ), array_column( $GLOBALS['user_import_test_state']['export_queries'], 'offset' ) );
	}
}