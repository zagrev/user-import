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
			'existing_users'  => array(),
			'existing_emails' => array(),
		);

		$this->import_csv = new ReflectionMethod( User_Import_Plugin::class, 'import_csv' );
		$this->import_csv->setAccessible( true );
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

		$result = $this->invoke_import(
			$file,
			"Email Address=user_email\n0=user_login\n2=first_name\n3=role\n4=meta:membership_id"
		);

		$this->assertSame( 1, $result['imported'] );
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
	}

	public function test_import_rejects_mapping_without_required_fields(): void {
		$file = $this->create_csv( "Name,Email\nAlex,alex@example.com\n" );

		$result = $this->invoke_import( $file, 'Name=display_name' );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 'Map columns to both user_login and user_email.', $result['errors'][0] );
	}

	public function test_import_skips_existing_username_or_email(): void {
		$GLOBALS['user_import_test_state']['existing_users'] = array( 'member-17' );
		$file = $this->create_csv( "user_login,user_email\nmember-17,member@example.com\n" );

		$result = $this->invoke_import( $file, '' );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array(), $result['errors'] );
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
