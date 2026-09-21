<?php
/**
 * Plugin Name: User Import
 * Description: Import WordPress users from a CSV file.
 * Version: 1.0.0
 * Author: Steve Betts
 * Requires PHP: 8.0
 * Text Domain: user-import
 *
 * @package UserImport
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the user import admin page and CSV importer.
 */
final class User_Import_Plugin {
	private const NONCE_ACTION = 'user_import_csv';
	private const MENU_SLUG    = 'user-import';

	/**
	 * Register the plugin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
	}

	/**
	 * Register the importer under the Users menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_users_page(
			__( 'Import Users', 'user-import' ),
			__( 'Import Users', 'user-import' ),
			'create_users',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render the importer page and process submitted CSV files.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to import users.', 'user-import' ) );
		}

		$results = null;
		if ( isset( $_POST['user_import_submit'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The upload is validated by import_csv() before it is opened.
			$upload  = isset( $_FILES['user_import_csv'] ) && is_array( $_FILES['user_import_csv'] ) ? $_FILES['user_import_csv'] : array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Mapping rules are sanitized by parse_mapping().
			$mapping = isset( $_POST['user_import_mapping'] ) ? (string) wp_unslash( $_POST['user_import_mapping'] ) : '';
			$results = self::import_csv( $upload, $mapping );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Users', 'user-import' ); ?></h1>
			<p><?php esc_html_e( 'Upload a CSV and optionally map its columns to WordPress user fields.', 'user-import' ); ?></p>

			<?php if ( is_array( $results ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: 1: imported user count, 2: skipped row count, 3: error count. */
							esc_html__( 'Imported: %1$d. Skipped: %2$d. Errors: %3$d.', 'user-import' ),
							(int) $results['imported'],
							(int) $results['skipped'],
							count( $results['errors'] )
						);
						?>
					</p>
					<?php if ( ! empty( $results['errors'] ) ) : ?>
						<ul>
							<?php foreach ( $results['errors'] as $error ) : ?>
								<li><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="user-import-csv"><?php esc_html_e( 'CSV file', 'user-import' ); ?></label></th>
						<td><input id="user-import-csv" name="user_import_csv" type="file" accept=".csv,text/csv" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="user-import-mapping"><?php esc_html_e( 'Column mapping', 'user-import' ); ?></label></th>
						<td>
							<textarea id="user-import-mapping" name="user_import_mapping" rows="8" class="large-text code" placeholder="Email Address=user_email&#10;0=user_login&#10;First Name=first_name&#10;Membership ID=meta:membership_id"></textarea>
							<p class="description">
								<?php esc_html_e( 'One mapping per line: CSV column name or zero-based column number = user field. Leave blank to use matching CSV headers.', 'user-import' ); ?><br>
								<?php esc_html_e( 'Supported fields include user_login, user_email, user_pass, user_nicename, user_url, display_name, first_name, last_name, nickname, description, locale, role, and meta:KEY for user meta.', 'user-import' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import Users', 'user-import' ), 'primary', 'user_import_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Import users from an uploaded CSV file.
	 *
	 * @param array<string, mixed> $upload  Uploaded file data.
	 * @param string              $mapping Mapping rules from CSV columns to user fields.
	 * @return array{imported: int, skipped: int, errors: string[]}
	 */
	private static function import_csv( array $upload, string $mapping = '' ): array {
		$results = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		if ( empty( $upload['tmp_name'] ) || ! empty( $upload['error'] ) ) {
			$results['errors'][] = __( 'The CSV upload could not be read.', 'user-import' );
			return $results;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the temporary uploaded CSV file.
		$handle = fopen( $upload['tmp_name'], 'rb' );
		if ( false === $handle ) {
			$results['errors'][] = __( 'The CSV file could not be opened.', 'user-import' );
			return $results;
		}

		$headers = fgetcsv( $handle );
		if ( false === $headers ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary uploaded CSV file.
			fclose( $handle );
			$results['errors'][] = __( 'The CSV file is empty.', 'user-import' );
			return $results;
		}

		$headers       = array_map( static fn( $header ): string => trim( (string) $header ), $headers );
		$column_mapping = self::parse_mapping( $mapping, $headers );
		if ( is_wp_error( $column_mapping ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary uploaded CSV file.
			fclose( $handle );
			$results['errors'][] = $column_mapping->get_error_message();
			return $results;
		}

		$row_number = 1;
		while ( true ) {
			$row = fgetcsv( $handle );
			if ( false === $row ) {
				break;
			}

			++$row_number;
			if ( count( array_filter( $row, static fn( $value ): bool => '' !== trim( (string) $value ) ) ) < 1 ) {
				continue;
			}

			$row = array_pad( $row, count( $headers ), '' );
			if ( count( $row ) > count( $headers ) ) {
				/* translators: %d: CSV row number. */
				$results['errors'][] = sprintf( __( 'Row %d could not be parsed.', 'user-import' ), $row_number );
				continue;
			}

			$data = array();
			foreach ( $column_mapping as $source_index => $target_field ) {
				$data[ $target_field ] = (string) ( $row[ $source_index ] ?? '' );
			}

			$login = sanitize_user( (string) ( $data['user_login'] ?? '' ), true );
			$email = sanitize_email( (string) ( $data['user_email'] ?? '' ) );
			if ( '' === $login || ! is_email( $email ) ) {
				/* translators: %d: CSV row number. */
				$results['errors'][] = sprintf( __( 'Row %d has an invalid username or email.', 'user-import' ), $row_number );
				continue;
			}

			if ( username_exists( $login ) || email_exists( $email ) ) {
				++$results['skipped'];
				continue;
			}

			$user_data = array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password(),
			);
			foreach ( array( 'user_pass', 'user_nicename', 'user_url', 'display_name', 'first_name', 'last_name', 'nickname', 'description', 'locale' ) as $field ) {
				if ( isset( $data[ $field ] ) ) {
					$user_data[ $field ] = sanitize_text_field( $data[ $field ] );
				}
			}

			$role = sanitize_key( (string) ( $data['role'] ?? '' ) );
			if ( '' !== $role && get_role( $role ) ) {
				$user_data['role'] = $role;
			}

			$user_id = wp_insert_user( $user_data );
			if ( is_wp_error( $user_id ) ) {
				/* translators: %d: CSV row number. */
				$results['errors'][] = sprintf( __( 'Row %d could not be imported.', 'user-import' ), $row_number );
				continue;
			}

			foreach ( $data as $field => $value ) {
				if ( str_starts_with( $field, 'meta:' ) ) {
					update_user_meta( $user_id, sanitize_key( substr( $field, 5 ) ), sanitize_text_field( $value ) );
				}
			}

			++$results['imported'];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary uploaded CSV file.
		fclose( $handle );
		return $results;
	}

	/**
	 * Parse CSV column-to-user-field mapping rules.
	 *
	 * @param string   $mapping Mapping rules.
	 * @param string[] $headers CSV header values.
	 * @return array<int, string>|\WP_Error
	 */
	private static function parse_mapping( string $mapping, array $headers ) {
		if ( '' === trim( $mapping ) ) {
			$mapping = implode( "\n", array_map( static fn( $header ): string => $header . '=' . sanitize_key( $header ), $headers ) );
		}

		$allowed_fields = array( 'user_login', 'user_email', 'user_pass', 'user_nicename', 'user_url', 'display_name', 'first_name', 'last_name', 'nickname', 'description', 'locale', 'role' );
		$parsed         = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $mapping ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || ! str_contains( $line, '=' ) ) {
				continue;
			}

			list( $source, $target ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( str_starts_with( strtolower( $target ), 'meta:' ) ) {
				$target = 'meta:' . sanitize_key( substr( $target, 5 ) );
			} else {
				$target = sanitize_key( $target );
			}

			if ( ! in_array( $target, $allowed_fields, true ) && ! str_starts_with( $target, 'meta:' ) ) {
				return new \WP_Error( 'invalid_mapping', sprintf( __( 'Unsupported user field: %s.', 'user-import' ), $target ) );
			}

			$source_index = null;
			if ( ctype_digit( $source ) ) {
				$source_index = (int) $source;
			} else {
				foreach ( $headers as $index => $header ) {
					if ( 0 === strcasecmp( trim( $header ), $source ) ) {
						$source_index = $index;
						break;
					}
				}
			}

			if ( null === $source_index || ! array_key_exists( $source_index, $headers ) ) {
				return new \WP_Error( 'invalid_mapping', sprintf( __( 'CSV column not found: %s.', 'user-import' ), $source ) );
			}

			$parsed[ $source_index ] = $target;
		}

		$mapped_fields = array_values( $parsed );
		if ( ! in_array( 'user_login', $mapped_fields, true ) || ! in_array( 'user_email', $mapped_fields, true ) ) {
			return new \WP_Error( 'missing_mapping', __( 'Map columns to both user_login and user_email.', 'user-import' ) );
		}

		return $parsed;
	}
}

User_Import_Plugin::init();
