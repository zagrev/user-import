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
	private const MAPPINGS_OPTION = 'user_import_saved_mappings';

	/**
	 * Register the plugin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register the importer under the Users menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_users_page(
			__( 'User Importer', 'user-import' ),
			__( 'Import Users', 'user-import' ),
			'create_users',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Enqueue the drag-and-drop mapping interface on the importer page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'users_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'user-import-admin',
			plugin_dir_url( __FILE__ ) . 'user-import.js',
			array(),
			'1.0.0',
			true
		);
		wp_enqueue_style(
			'user-import-admin',
			plugin_dir_url( __FILE__ ) . 'user-import.css',
			array(),
			'1.0.0'
		);
	}

	/**
	 * Return the user fields available in the drag-and-drop mapper.
	 *
	 * @return array<string, string> User field keys and labels.
	 */
	private static function get_mapping_fields(): array {
		return array(
			'user_login'    => __( 'Username', 'user-import' ),
			'user_email'    => __( 'Email', 'user-import' ),
			'user_pass'     => __( 'Password', 'user-import' ),
			'user_nicename' => __( 'Nicename', 'user-import' ),
			'user_url'      => __( 'Website URL', 'user-import' ),
			'display_name'  => __( 'Display name', 'user-import' ),
			'first_name'    => __( 'First name', 'user-import' ),
			'last_name'     => __( 'Last name', 'user-import' ),
			'nickname'      => __( 'Nickname', 'user-import' ),
			'description'   => __( 'Description', 'user-import' ),
			'locale'        => __( 'Locale', 'user-import' ),
			'role'          => __( 'Role', 'user-import' ),
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

		$results        = null;
		$notice         = '';
		$saved_mappings = self::get_saved_mappings();
		$selected_mapping = '';
		$mapping        = '';
		$action        = isset( $_POST['user_import_action'] ) ? sanitize_key( wp_unslash( $_POST['user_import_action'] ) ) : '';
		if ( '' !== $action ) {
			check_admin_referer( self::NONCE_ACTION );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The upload is validated by import_csv() before it is opened.
			$upload  = isset( $_FILES['user_import_csv'] ) && is_array( $_FILES['user_import_csv'] ) ? $_FILES['user_import_csv'] : array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Mapping rules are sanitized by parse_mapping().
			$mapping = isset( $_POST['user_import_mapping'] ) ? (string) wp_unslash( $_POST['user_import_mapping'] ) : '';
			$mapping_name = isset( $_POST['user_import_mapping_name'] ) ? sanitize_text_field( wp_unslash( $_POST['user_import_mapping_name'] ) ) : '';

			if ( 'save_mapping' === $action ) {
				if ( '' === $mapping_name || '' === trim( $mapping ) ) {
					$notice = __( 'Enter a mapping name and configure a mapping before saving.', 'user-import' );
				} else {
					$saved_mappings[ $mapping_name ] = $mapping;
					update_option( self::MAPPINGS_OPTION, $saved_mappings );
					$selected_mapping = $mapping_name;
					$notice = __( 'Mapping saved.', 'user-import' );
				}
			} elseif ( 'export_csv' === $action ) {
				self::export_csv( $upload, $mapping );
			} elseif ( 'import_users' === $action ) {
				$results = self::import_csv( $upload, $mapping );
			}
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
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
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
							<label for="user-import-saved-mapping"><?php esc_html_e( 'Saved mapping', 'user-import' ); ?></label>
							<select id="user-import-saved-mapping" name="user_import_saved_mapping" class="user-import-saved-mapping">
								<option value=""><?php esc_html_e( 'Choose a saved mapping', 'user-import' ); ?></option>
								<?php foreach ( $saved_mappings as $name => $saved_mapping ) : ?>
									<option value="<?php echo esc_attr( $name ); ?>" data-mapping="<?php echo esc_attr( $saved_mapping ); ?>" <?php selected( $selected_mapping, $name ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<label for="user-import-mapping-name"><?php esc_html_e( 'Save current mapping as', 'user-import' ); ?></label>
							<input id="user-import-mapping-name" name="user_import_mapping_name" type="text" class="regular-text" value="<?php echo esc_attr( $selected_mapping ); ?>">
							<button type="submit" class="button" name="user_import_action" value="save_mapping"><?php esc_html_e( 'Save mapping', 'user-import' ); ?></button>
							<br><br>
							<div id="user-import-mapping-builder" class="user-import-mapping-builder">
								<p><?php esc_html_e( 'Choose the CSV file, then drag user fields onto CSV columns. Drag a mapped field back to the user fields list to remove it. Required fields are marked.', 'user-import' ); ?></p>
								<div class="user-import-mapping-panels">
									<div>
										<h3><?php esc_html_e( 'User fields', 'user-import' ); ?></h3>
										<div id="user-import-fields" class="user-import-fields" aria-label="<?php esc_attr_e( 'Draggable user fields', 'user-import' ); ?>">
											<?php foreach ( self::get_mapping_fields() as $field => $label ) : ?>
												<button type="button" class="user-import-field" draggable="true" data-user-field="<?php echo esc_attr( $field ); ?>">
													<?php echo esc_html( $label ); ?><?php echo in_array( $field, array( 'user_login', 'user_email' ), true ) ? ' *' : ''; ?>
												</button>
											<?php endforeach; ?>
										</div>
									</div>
									<div>
										<h3><?php esc_html_e( 'CSV columns', 'user-import' ); ?></h3>
										<div id="user-import-columns" class="user-import-columns" aria-live="polite">
											<p><?php esc_html_e( 'CSV columns will appear here after you choose a file.', 'user-import' ); ?></p>
										</div>
									</div>
								</div>
							</div>
							<textarea id="user-import-mapping" name="user_import_mapping" rows="8" class="large-text code" placeholder="Email Address=user_email&#10;0=user_login&#10;First Name=first_name&#10;Membership ID=meta:membership_id"><?php echo esc_textarea( $mapping ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One mapping per line: CSV column name or zero-based column number = user field. Leave blank to use matching CSV headers.', 'user-import' ); ?><br>
								<?php esc_html_e( 'Supported fields include user_login, user_email, user_pass, user_nicename, user_url, display_name, first_name, last_name, nickname, description, locale, role, and meta:KEY for user meta.', 'user-import' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary" name="user_import_action" value="import_users"><?php esc_html_e( 'Import Users', 'user-import' ); ?></button>
					<button type="submit" class="button" name="user_import_action" value="export_csv"><?php esc_html_e( 'Export Mapped CSV', 'user-import' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Get saved mapping definitions.
	 *
	 * @return array<string, string> Saved mapping names and rules.
	 */
	private static function get_saved_mappings(): array {
		$mappings = get_option( self::MAPPINGS_OPTION, array() );
		return is_array( $mappings ) ? array_filter( $mappings, 'is_string' ) : array();
	}

	/**
	 * Export the uploaded CSV using the selected mapping.
	 *
	 * @param array<string, mixed> $upload Uploaded file data.
	 * @param string              $mapping Mapping rules.
	 * @return void
	 */
	private static function export_csv( array $upload, string $mapping ): void {
		if ( empty( $upload['tmp_name'] ) || ! empty( $upload['error'] ) ) {
			wp_die( esc_html__( 'The CSV upload could not be read.', 'user-import' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the temporary uploaded CSV file.
		$handle = fopen( $upload['tmp_name'], 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'The CSV file could not be opened.', 'user-import' ) );
		}

		$headers = fgetcsv( $handle );
		$headers = false === $headers ? array() : array_map( static fn( $header ): string => trim( (string) $header ), $headers );
		$column_mapping = self::parse_mapping( $mapping, $headers );
		if ( is_wp_error( $column_mapping ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary uploaded CSV file.
			fclose( $handle );
			wp_die( esc_html( $column_mapping->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mapped-users.csv"' );
		$output = fopen( 'php://output', 'wb' );
		$target_fields = array_values( $column_mapping );
		fputcsv( $output, $target_fields );

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$row = array_pad( $row, count( $headers ), '' );
			$export_row = array();
			foreach ( $column_mapping as $source_index => $target_field ) {
				$export_row[] = $row[ $source_index ] ?? '';
			}
			fputcsv( $output, $export_row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing temporary CSV streams.
		fclose( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary uploaded CSV file.
		fclose( $handle );
		exit;
	}

	/**
	 * Import users from an uploaded CSV file.
	 *
	 * @param array<string, mixed> $upload  Uploaded file data.
	 * @param string              $mapping Mapping rules from CSV columns to user fields.
	 * @return array{imported: int, skipped: int, errors: string[]}|WP_Error
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
