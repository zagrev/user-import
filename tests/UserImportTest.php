<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserImportTest extends TestCase {
	private ReflectionMethod $import_csv;
	private array $temporary_files = array();

	protected function setUp(): void {
		parent::setUp();
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
		$GLOBALS['wp_filter'] = array(
			'user_register' => array( 'test_callback' => true ),
		);

		$this->import_csv = new ReflectionMethod( User_Import_Plugin::class, 'import_csv' );
		if (\PHP_VERSION_ID < 80100) {
			$this->import_csv->setAccessible( true );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->temporary_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temporary_files = array();
		parent::tearDown();
	}

	public function test_import_maps_columns_by_name_and_number_and_saves_user_meta(): void {
		$file = $this->create_csv(
			"External ID,Email Address,First Name,Role,Membership ID\n" .
			"member-17,member@example.com,Alex,subscriber,ABC-17\n"
		);

		$result = $this->import_csv->invoke( null, array( 'tmp_name' => $file, 'error' => 0 ), 
			"Email Address=user_email\n0=user_login\n2=first_name\n3=role\n4=meta:membership_id"
		);

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array(
				'user_login' => 'member-17',
				'user_email' => 'member@example.com',
				'user_pass'  => 'generated-password',
				'notify'     => 'none',
				'first_name' => 'Alex',
				'role'       => 'subscriber',
			),
			$GLOBALS['user_import_test_state']['inserted_users'][1]
		);
		$this->assertSame( 'ABC-17', $GLOBALS['user_import_test_state']['user_meta'][1]['membership_id'] );
		$this->assertSame( array( true ), $GLOBALS['user_import_test_state']['user_register_hooks_disabled'] );
		$this->assertArrayHasKey( 'user_register', $GLOBALS['wp_filter'] );
	}

	public function test_import_rejects_mapping_without_required_fields(): void {
		$file = $this->create_csv( "Name,Email\nAlex,alex@example.com\n" );

		$result = $this->invoke_import( $file, 'Name=display_name' );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 'Map columns to both user_login and user_email.', $result['errors'][0] );
	}

	public function test_import_updates_existing_user_without_changing_identity_fields(): void {
		$test_user =  new WP_User( 7 );
		$test_user->user_login = 'member-17';
		$test_user->user_email = 'member@example.com';
		$GLOBALS['user_import_test_state']['existing_users'][7] = $test_user;
		$GLOBALS['user_import_test_state']['existing_emails'][ $test_user->user_email ] = $test_user;
	
		$file = $this->create_csv( "user_login,user_email,display_name,first_name,user_pass\nmember-17,member@example.com,Alex Member,Alex,new-password\n" );

		$result = $this->invoke_import( $file, "user_login=user_login\nuser_email=user_email\ndisplay_name=display_name\nfirst_name=first_name\nuser_pass=user_pass" );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array(
				'ID'           => 7,
				'user_login' => 'member-17', 
				'user_email' => 'member@example.com', 
				'user_pass' => 'new-password', 
				'display_name' => 'Alex Member',
				'first_name'   => 'Alex',
			),
			$GLOBALS['user_import_test_state']['updated_users'][0]
		);
	}

	public function test_import_page_renders_step_one_instructions_and_upload_action(): void {
		ob_start();
		User_Import_Plugin::render_import_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 1:', $html );
		$this->assertStringContainsString( 'Drop a CSV file here', $html );
		$this->assertStringContainsString( 'Continue to column mapping', $html );
		$this->assertStringContainsString( 'name="user_import_action" value="upload_csv"', $html );
	}

	public function test_step_one_continue_is_enabled_for_retained_csv(): void {
		$token = str_repeat( 'd', 64 );
		$pending_directory = wp_upload_dir()['basedir'] . '/user-import-pending/';
		if ( ! is_dir( $pending_directory ) ) {
			mkdir( $pending_directory, 0777, true );
		}
		$pending_file = $pending_directory . '1-' . $token . '.csv';
		file_put_contents( $pending_file, "Username,Email\n" );
		$this->temporary_files[] = $pending_file;

		$_POST = array(
			'user_import_action'        => 'back_to_upload',
			'user_import_pending_token' => $token,
		);

		ob_start();
		User_Import_Plugin::render_import_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'value="upload_csv" disabled', $html );
		$this->assertStringNotContainsString( 'name="user_import_csv" type="file" accept=".csv,text/csv" required', $html );
	}

	public function test_mapping_fields_include_ultimate_member_and_acf_fields(): void {
		$GLOBALS['user_import_test_state']['um_fields'] = array(
			'um_member_number' => array(
				'title' => 'Member Number',
				'metakey' => 'um_member_number',
			),
		);
		$GLOBALS['user_import_test_state']['acf_groups'] = array(
			array(
				'key' => 'group_user_profile',
				'location' => array(
					array(
						array( 'param' => 'user_form' ),
					),
				),
			),
		);
		$GLOBALS['user_import_test_state']['acf_fields']['group_user_profile'] = array(
			array(
				'name'  => 'preferred_chord',
				'label' => 'Preferred Chord',
			),
		);

		$method = new ReflectionMethod( User_Import_Plugin::class, 'get_mapping_fields' );
		$method->setAccessible( true );
		$fields = $method->invoke( null );

		$this->assertSame( 'UM: Member Number', $fields['meta:um_member_number'] );
		$this->assertSame( 'ACF: Preferred Chord', $fields['meta:preferred_chord'] );
	}

	public function test_saving_mapping_keeps_import_wizard_on_mapping_step(): void {
		$pending_directory = sys_get_temp_dir() . '/user-import-tests';
		if ( ! is_dir( $pending_directory ) ) {
			mkdir( $pending_directory, 0777, true );
		}
		$token = str_repeat( 'a', 64 );
		$pending_file = $pending_directory . '/1-' . $token . '.csv';
		file_put_contents( $pending_file, "Email,Username\n" );
		$this->temporary_files[] = $pending_file;

		$_POST = array(
			'user_import_action'       => 'save_mapping',
			'user_import_pending_token' => $token,
			'user_import_mapping_name' => 'Members',
			'user_import_mapping'      => "Username=user_login\nEmail=user_email",
		);

		ob_start();
		User_Import_Plugin::render_import_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 2:', $html );
		$this->assertStringContainsString( 'Username=user_login', $html );
		$this->assertStringContainsString( 'Members', $html );
	}

	public function test_mapping_step_blocks_progress_without_username_and_email(): void {
		$token = str_repeat( 'b', 64 );
		$pending_directory = wp_upload_dir()['basedir'] . '/user-import-pending/';
		if ( ! is_dir( $pending_directory ) ) {
			mkdir( $pending_directory, 0777, true );
		}
		$pending_file = $pending_directory . '1-' . $token . '.csv';
		file_put_contents( $pending_file, "Email,Name\nalex@example.com,Alex\n" );
		$this->temporary_files[] = $pending_file;

		$_POST = array(
			'user_import_action'        => 'back_to_roles',
			'user_import_pending_token' => $token,
			'user_import_mapping'       => 'Email=user_email',
		);

		ob_start();
		User_Import_Plugin::render_import_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 2:', $html );
		$this->assertStringContainsString( 'Map columns to both user_login and user_email.', $html );
		$this->assertStringNotContainsString( 'Step 3:', $html );
	}

	public function test_role_step_renders_selected_roles_after_valid_mapping(): void {
		$token = str_repeat( 'c', 64 );
		$pending_directory = wp_upload_dir()['basedir'] . '/user-import-pending/';
		if ( ! is_dir( $pending_directory ) ) {
			mkdir( $pending_directory, 0777, true );
		}
		$pending_file = $pending_directory . '1-' . $token . '.csv';
		file_put_contents( $pending_file, "Email,Username\nalex@example.com,alex\n" );
		$this->temporary_files[] = $pending_file;

		$_POST = array(
			'user_import_action'        => 'back_to_roles',
			'user_import_pending_token' => $token,
			'user_import_mapping'       => "Username=user_login\nEmail=user_email",
			'user_import_roles'         => array( 'subscriber' ),
		);

		ob_start();
		User_Import_Plugin::render_import_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 3:', $html );
		$this->assertStringContainsString( 'Select the roles to apply to every imported or updated user.', $html );
		$this->assertStringContainsString( 'value="subscriber"', $html );
		$this->assertStringContainsString( 'checked="checked"', $html );
		$this->assertStringContainsString( 'Start import', $html );
	}

	public function test_wizard_round_trip_preserves_csv_mapping_name_and_roles(): void {
		$token = str_repeat( 'e', 64 );
		$pending_directory = wp_upload_dir()['basedir'] . '/user-import-pending/';
		if ( ! is_dir( $pending_directory ) ) {
			mkdir( $pending_directory, 0777, true );
		}
		$pending_file = $pending_directory . '1-' . $token . '.csv';
		file_put_contents( $pending_file, "Username,Email\nalex,alex@example.com\n" );
		$this->temporary_files[] = $pending_file;

		$_POST = array(
			'user_import_action'        => 'back_to_mapping',
			'user_import_pending_token' => $token,
			'user_import_mapping_name'  => 'Members',
			'user_import_mapping'       => "Username=user_login\nEmail=user_email",
			'user_import_roles'         => array( 'subscriber' ),
		);

		ob_start();
		User_Import_Plugin::render_import_page();
		$mapping_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 2:', $mapping_html );
		$this->assertStringContainsString( 'Username=user_login', $mapping_html );
		$this->assertStringContainsString( 'Members', $mapping_html );
		$this->assertStringContainsString( 'name="user_import_roles[]" value="subscriber"', $mapping_html );

		$_POST['user_import_action'] = 'back_to_roles';
		ob_start();
		User_Import_Plugin::render_import_page();
		$roles_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 3:', $roles_html );
		$this->assertStringContainsString( 'value="subscriber"', $roles_html );
		$this->assertStringContainsString( 'checked="checked"', $roles_html );
		$this->assertStringContainsString( 'Members', $roles_html );
	}

	public function test_retained_csv_survives_step_two_to_step_one_and_back(): void {
		$token = str_repeat( 'f', 64 );
		$pending_directory = wp_upload_dir()['basedir'] . '/user-import-pending/';
		if ( ! is_dir( $pending_directory ) ) {
			mkdir( $pending_directory, 0777, true );
		}
		$pending_file = $pending_directory . '1-' . $token . '.csv';
		file_put_contents( $pending_file, "Username,Email\nalex,alex@example.com\n" );
		$this->temporary_files[] = $pending_file;

		// Step 2 -> step 1: mapping, mapping name, and roles must ride along in the hidden fields.
		$_POST = array(
			'user_import_action'          => 'back_to_upload',
			'user_import_pending_token'   => $token,
			'user_import_pending_filename' => 'members.csv',
			'user_import_mapping_name'    => 'Members',
			'user_import_mapping'         => "Username=user_login\nEmail=user_email",
			'user_import_roles'           => array( 'subscriber' ),
		);

		ob_start();
		User_Import_Plugin::render_import_page();
		$upload_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 1:', $upload_html );
		$this->assertStringContainsString( 'Retained CSV:', $upload_html );
		$this->assertStringContainsString( 'members.csv', $upload_html );
		$this->assertStringNotContainsString( 'value="upload_csv" disabled', $upload_html );
		$this->assertStringNotContainsString( 'name="user_import_csv" type="file" accept=".csv,text/csv" required', $upload_html );
		$this->assertStringContainsString( 'name="user_import_mapping" value="Username=user_login', $upload_html );
		$this->assertStringContainsString( 'Email=user_email"', $upload_html );
		$this->assertStringContainsString( 'name="user_import_mapping_name" value="Members"', $upload_html );
		$this->assertStringContainsString( 'name="user_import_roles[]" value="subscriber"', $upload_html );

		// Step 1 -> step 2 without choosing a new file: the retained token, mapping, name, and roles must all persist.
		$_POST['user_import_action'] = 'upload_csv';
		unset( $_FILES['user_import_csv'] );

		ob_start();
		User_Import_Plugin::render_import_page();
		$mapping_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Step 2:', $mapping_html );
		$this->assertStringContainsString( 'Username=user_login', $mapping_html );
		$this->assertStringContainsString( 'name="user_import_mapping_name" ', $mapping_html );
		$this->assertStringContainsString( 'value="Members"', $mapping_html );
		$this->assertStringContainsString( 'name="user_import_roles[]" value="subscriber"', $mapping_html );
		$this->assertStringContainsString( 'data-csv-headers="[&quot;Username&quot;,&quot;Email&quot;]"', $mapping_html );
	}

	private function create_csv( string $contents ): string {
		$file = tempnam( sys_get_temp_dir(), 'user-import-test-' );
		file_put_contents( $file, $contents );
		$this->temporary_files[] = $file;
		return $file;
	}

	private function invoke_import( string $file, string $mapping ): array {
		return $this->import_csv->invoke( null, array( 'tmp_name' => $file, 'error' => 0 ), $mapping );
	}
}
