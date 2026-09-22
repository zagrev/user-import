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
	private const EXPORT_MENU_SLUG = 'user-export';
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
			array( self::class, 'render_import_page' )
		);
		add_users_page(
			__( 'User Exporter', 'user-import' ),
			__( 'Export Users', 'user-import' ),
			'create_users',
			self::EXPORT_MENU_SLUG,
			array( self::class, 'render_export_page' )
		);
	}

	/**
	 * Enqueue the drag-and-drop mapping interface on the importer page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'users_page_' . self::MENU_SLUG, 'users_page_' . self::EXPORT_MENU_SLUG ), true ) ) {
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
		wp_enqueue_script(
			'user-import-upload',
			plugin_dir_url( __FILE__ ) . 'user-import-upload.js',
			array(),
			'1.0.0',
			true
		);
	}

	/**
	 * Return the user fields available in the drag-and-drop mapper.
	 *
	 * @return array<string, string> User field keys and labels.
	 */
	private static function get_mapping_fields(): array {
		$fields = array(
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

		foreach ( self::get_ultimate_member_fields() as $field_name => $label ) {
			$fields[ 'meta:' . $field_name ] = sprintf( __( 'UM: %s', 'user-import' ), $label );
		}

		foreach ( self::get_acf_user_fields() as $field_name => $label ) {
			$fields[ 'meta:' . $field_name ] = sprintf( __( 'ACF: %s', 'user-import' ), $label );
		}

		return $fields;
	}

	/**
	 * Discover Ultimate Member custom fields when the plugin is active.
	 *
	 * @return array<string, string> Field keys and labels.
	 */
	private static function get_ultimate_member_fields(): array {
		if ( ! function_exists( 'UM' ) ) {
			return array();
		}

		try {
			$ultimate_member = \UM();
			if ( ! is_object( $ultimate_member ) ) {
				return array();
			}

			$raw_fields = array();
			if ( method_exists( $ultimate_member, 'options' ) && function_exists( 'um_get_form_fields' ) ) {
				$options = $ultimate_member->options();
				$core_form = is_object( $options ) && method_exists( $options, 'get' ) ? $options->get( 'core_profile' ) : '';
				if ( $core_form ) {
					$raw_fields = (array) \um_get_form_fields( $core_form );
				}
			}

			if ( empty( $raw_fields ) && method_exists( $ultimate_member, 'fields' ) ) {
                $field_manager = $ultimate_member->fields();
				if ( is_object( $field_manager ) && method_exists( $field_manager, 'all_fields' ) ) {
					$raw_fields = $field_manager->all_fields();
				} elseif ( is_object( $field_manager ) && method_exists( $field_manager, 'get_fields' ) ) {
					$raw_fields = $field_manager->get_fields();
				} elseif ( is_object( $field_manager ) && method_exists( $field_manager, 'get_all_user_fields' ) ) {
					$raw_fields = $field_manager->get_all_user_fields();
                }
            }

			if ( empty( $raw_fields ) ) {
				$raw_fields = get_option( 'um_fields', array() );
			}
            \error_log( "Ultimate Member raw fields: " . print_r( $raw_fields, true ) );
			if ( ! is_array( $raw_fields ) ) {
				return array();
			}

			$fields = array();
			foreach ( $raw_fields as $field_name => $field ) {
				if ( is_array( $field ) ) {
					$field_name = (string) ( $field['metakey'] ?? $field['id'] ?? $field_name );
					$label      = (string) ( $field['title'] ?? $field['label'] ?? $field_name );
				} else {
					$label = (string) $field_name;
				}

				$field_name = sanitize_key( $field_name );
				if ( '' !== $field_name ) {
					$fields[ $field_name ] = $label;
				}
			}

			return $fields;
		} catch ( \Throwable $exception ) {
			return array();
		}
	}

	/**
	 * Discover ACF fields attached to user forms or user roles.
	 *
	 * @return array<string, string> Field keys and labels.
	 */
	private static function get_acf_user_fields(): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$fields = array();
		foreach ( (array) acf_get_field_groups() as $group ) {
			$locations = $group['location'] ?? array();
			$is_user_group = false;
			foreach ( $locations as $location_group ) {
				foreach ( $location_group as $location ) {
					if ( in_array( (string) ( $location['param'] ?? '' ), array( 'user_form', 'user_role', 'user' ), true ) ) {
						$is_user_group = true;
					}
				}
			}

			if ( ! $is_user_group ) {
				continue;
			}

			foreach ( (array) acf_get_fields( $group ) as $field ) {
				$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
				if ( '' !== $name ) {
					$fields[ $name ] = (string) ( $field['label'] ?? $name );
				}
			}
		}

		return $fields;
	}

	/**
	 * Render the importer page and process submitted CSV files.
	 *
	 * @return void
	 */
	public static function render_import_page(): void {
		self::render_page( 'import' );
	}

	/**
	 * Render the export page.
	 *
	 * @return void
	 */
	public static function render_export_page(): void {
		self::render_page( 'export' );
	}

	/**
	 * Render the shared mapping page.
	 *
	 * @param string $mode Either import or export.
	 * @return void
	 */
	private static function render_page( string $mode ): void {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to import users.', 'user-import' ) );
		}

		$results        = null;
		$notice         = '';
		$saved_mappings = self::get_saved_mappings();
		$selected_mapping = '';
		$mapping        = '';
		$pending_token  = '';
		$csv_headers    = array();
		$wizard_step    = 'upload';
		$activity       = array();
		$action        = isset( $_POST['user_import_action'] ) ? sanitize_key( wp_unslash( $_POST['user_import_action'] ) ) : '';
		if ( '' !== $action ) {
			check_admin_referer( self::NONCE_ACTION );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The upload is validated by import_csv() before it is opened.
			$upload  = isset( $_FILES['user_import_csv'] ) && is_array( $_FILES['user_import_csv'] ) ? $_FILES['user_import_csv'] : array();
			$posted_pending_token = isset( $_POST['user_import_pending_token'] ) ? sanitize_file_name( wp_unslash( $_POST['user_import_pending_token'] ) ) : '';
			$pending_token = $posted_pending_token;
			if ( empty( $upload['tmp_name'] ) && '' !== $posted_pending_token ) {
				$upload['tmp_name'] = self::get_pending_upload_path( $posted_pending_token );
				$upload['error']    = file_exists( $upload['tmp_name'] ) ? 0 : UPLOAD_ERR_NO_FILE;
			}
			if ( ! empty( $upload['tmp_name'] ) && file_exists( $upload['tmp_name'] ) ) {
				$csv_headers = self::get_csv_headers( $upload['tmp_name'] );
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Mapping rules are sanitized by parse_mapping().
			$mapping = isset( $_POST['user_import_mapping'] ) ? (string) wp_unslash( $_POST['user_import_mapping'] ) : '';
			$mapping_name = isset( $_POST['user_import_mapping_name'] ) ? sanitize_text_field( wp_unslash( $_POST['user_import_mapping_name'] ) ) : '';

			if ( 'save_mapping' === $action ) {
				if ( '' === trim( $mapping ) && isset( $saved_mappings[ $mapping_name ] ) ) {
					$mapping = $saved_mappings[ $mapping_name ];
				}

				if ( '' === $mapping_name || '' === trim( $mapping ) ) {
					$notice = __( 'Enter a mapping name and configure a mapping before saving.', 'user-import' );
				} else {
					$saved_mappings[ $mapping_name ] = $mapping;
					update_option( self::MAPPINGS_OPTION, $saved_mappings );
					$selected_mapping = $mapping_name;
					$mapping          = $saved_mappings[ $mapping_name ];
					if ( 'import' === $mode && '' !== $pending_token ) {
						$wizard_step = 'mapping';
					}
					$notice = __( 'Mapping saved.', 'user-import' );
				}
			} elseif ( 'back_to_upload' === $action && 'import' === $mode ) {
				$wizard_step = 'upload';
			} elseif ( 'back_to_mapping' === $action && 'import' === $mode ) {
				$wizard_step = 'mapping';
			} elseif ( 'restart_import' === $action && 'import' === $mode ) {
				if ( '' !== $posted_pending_token ) {
					self::delete_pending_upload( $posted_pending_token );
				}
				$pending_token = '';
				$mapping       = '';
				$wizard_step   = 'upload';
			} elseif ( 'upload_csv' === $action && 'import' === $mode ) {
				$pending_token = self::persist_pending_upload( $upload, $posted_pending_token );
				if ( '' !== $pending_token ) {
					$wizard_step = 'mapping';
					$mapping = '';
				} else {
					$notice = __( 'The CSV upload could not be retained.', 'user-import' );
				}
			} elseif ( 'export_csv' === $action && 'export' === $mode ) {
				self::export_csv( $upload, $mapping );
			} elseif ( 'import_users' === $action && 'import' === $mode ) {
				$wizard_step = 'summary';
				$pending_token = self::persist_pending_upload( $upload, $posted_pending_token );
				if ( '' !== $pending_token ) {
					$upload['tmp_name'] = self::get_pending_upload_path( $pending_token );
					$upload['error']    = 0;
				}
				$results = self::import_csv( $upload, $mapping, $activity );
				if ( ! empty( $results['errors'] ) && '' !== $pending_token ) {
					$results['pending_token'] = $pending_token;
				}
			}
		}
		if ( isset( $results['pending_token'] ) ) {
			$pending_token = (string) $results['pending_token'];
		}
		if ( 'import' === $mode && 'summary' === $wizard_step && ! empty( $results['errors'] ) ) {
			$wizard_step = 'mapping';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( 'export' === $mode ? __( 'Export Users', 'user-import' ) : __( 'Import Users', 'user-import' ) ); ?></h1>
			<?php if ( 'import' === $mode ) : ?>
				<h2 class="user-import-step-heading"><?php echo esc_html( 'Step ' . ( 'upload' === $wizard_step ? '1' : ( 'mapping' === $wizard_step ? '2' : '3' ) ) . ': ' . ( 'upload' === $wizard_step ? 'Choose a CSV file.' : ( 'mapping' === $wizard_step ? 'Map CSV columns to WordPress fields.' : 'Review the import activity and summary.' ) ) ); ?></h2>
				<?php if ( 'upload' === $wizard_step ) : ?>
					<div class="user-import-step-instructions">
						<p><?php esc_html_e( 'Start by selecting the CSV file you want to import.', 'user-import' ); ?></p>
						<ul>
							<li><?php esc_html_e( 'The first row should contain your CSV column headings.', 'user-import' ); ?></li>
							<li><?php esc_html_e( 'On the next step, drag CSV columns onto WordPress fields to create the mapping.', 'user-import' ); ?></li>
							<li><?php esc_html_e( 'Map columns to both Username and Email; existing users will be updated without changing those identity fields.', 'user-import' ); ?></li>
						</ul>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Upload a CSV and map its columns to generate a new CSV.', 'user-import' ); ?></p>
			<?php endif; ?>

			<?php if ( 'import' === $mode && is_array( $results ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: 1: imported user count, 2: updated user count, 3: skipped row count, 4: error count. */
							esc_html__( 'Imported: %1$d. Updated: %2$d. Skipped: %3$d. Errors: %4$d.', 'user-import' ),
							(int) $results['imported'],
							(int) $results['updated'],
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
			<?php if ( 'import' === $mode && 'summary' === $wizard_step && ! empty( $activity ) ) : ?>
				<h2><?php esc_html_e( 'Processing activity', 'user-import' ); ?></h2>
				<ul class="user-import-activity">
					<?php foreach ( $activity as $entry ) : ?>
						<li><?php echo esc_html( $entry ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<?php if ( 'summary' === $wizard_step ) : ?>
					<input type="hidden" name="user_import_mapping" value="<?php echo esc_attr( $mapping ); ?>">
				<?php endif; ?>
				<?php if ( '' !== $pending_token ) : ?>
					<input type="hidden" name="user_import_pending_token" value="<?php echo esc_attr( $pending_token ); ?>">
					<p class="description"><?php esc_html_e( 'The uploaded CSV is being retained for this import. Choose a new file to replace it.', 'user-import' ); ?></p>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php if ( 'upload' === $wizard_step || 'export' === $mode ) : ?>
					<tr>
						<th scope="row"><label for="user-import-csv"><?php esc_html_e( 'CSV file', 'user-import' ); ?></label></th>
						<td>
							<?php if ( 'import' === $mode && 'upload' === $wizard_step ) : ?>
								<div id="user-import-upload-dropzone" class="user-import-upload-dropzone" tabindex="0" role="button">
									<strong><?php esc_html_e( 'Drop a CSV file here', 'user-import' ); ?></strong>
									<span><?php esc_html_e( 'or choose a file below', 'user-import' ); ?></span>
								</div>
							<?php endif; ?>
							<input id="user-import-csv" name="user_import_csv" type="file" accept=".csv,text/csv" <?php echo 'upload' === $wizard_step ? 'required' : ''; ?> >
						</td>
					</tr>
					<?php endif; ?>
					<?php if ( 'mapping' === $wizard_step || 'export' === $mode ) : ?>
					<tr>
						<th scope="row"><label for="user-import-mapping"><?php esc_html_e( 'Column mapping', 'user-import' ); ?></label></th>
						<td>
							<fieldset class="user-import-saved-mapping-area">
								<legend><?php esc_html_e( 'Saved mappings', 'user-import' ); ?></legend>
								<label for="user-import-saved-mapping"><?php esc_html_e( 'Load saved mapping', 'user-import' ); ?></label>
								<select id="user-import-saved-mapping" name="user_import_saved_mapping" class="user-import-saved-mapping">
									<option value=""><?php esc_html_e( 'Choose a saved mapping', 'user-import' ); ?></option>
									<?php foreach ( $saved_mappings as $name => $saved_mapping ) : ?>
										<option value="<?php echo esc_attr( $name ); ?>" data-mapping="<?php echo esc_attr( $saved_mapping ); ?>" <?php selected( $selected_mapping, $name ); ?>><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
								<label for="user-import-mapping-name"><?php esc_html_e( 'Save current mapping as', 'user-import' ); ?></label>
								<input id="user-import-mapping-name" name="user_import_mapping_name" type="text" class="regular-text" value="<?php echo esc_attr( $selected_mapping ); ?>">
								<button type="submit" class="button" name="user_import_action" value="save_mapping"><?php esc_html_e( 'Save mapping', 'user-import' ); ?></button>
							</fieldset>
							<br><br>
							<div id="user-import-mapping-builder" class="user-import-mapping-builder">
								<p><?php esc_html_e( 'Choose the CSV file, then drag user fields onto CSV columns. Drag a mapped field back to the user fields list to remove it. Required fields are marked.', 'user-import' ); ?></p>
								<div id="user-import-mapping-panels" class="user-import-mapping-panels">
									<svg id="user-import-mapping-lines" class="user-import-mapping-lines" aria-hidden="true"></svg>
									<div>
										<h3><?php esc_html_e( 'CSV columns', 'user-import' ); ?></h3>
										<div id="user-import-columns" class="user-import-columns" aria-live="polite" data-csv-headers="<?php echo esc_attr( wp_json_encode( $csv_headers ) ); ?>">
											<?php if ( empty( $csv_headers ) ) : ?>
												<p><?php esc_html_e( 'CSV columns will appear here after you choose a file.', 'user-import' ); ?></p>
											<?php else : ?>
												<?php foreach ( $csv_headers as $header_index => $header ) : ?>
													<div class="user-import-column">
														<span class="dashicons dashicons-move user-import-drag-icon" aria-hidden="true"></span>
														<strong><?php echo esc_html( $header_index . ': ' . ( '' !== $header ? $header : __( '(blank header)', 'user-import' ) ) ); ?></strong>
													</div>
												<?php endforeach; ?>
											<?php endif; ?>
										</div>
									</div>
									<div>
										<h3><?php esc_html_e( 'User fields', 'user-import' ); ?></h3>
										<div id="user-import-fields" class="user-import-fields" aria-label="<?php esc_attr_e( 'Draggable user fields', 'user-import' ); ?>">
											<?php foreach ( self::get_mapping_fields() as $field => $label ) : ?>
												<button type="button" class="user-import-field" draggable="true" data-user-field="<?php echo esc_attr( $field ); ?>">
														<span class="dashicons dashicons-move user-import-drag-icon" aria-hidden="true"></span>
													<?php echo esc_html( $label ); ?><?php echo in_array( $field, array( 'user_login', 'user_email' ), true ) ? ' *' : ''; ?>
												</button>
											<?php endforeach; ?>
										</div>
										</div>
									</div>
								</div>
							</div>
							<h3><label for="user-import-mapping"><?php esc_html_e( 'Mapping text', 'user-import' ); ?></label></h3>
							<textarea id="user-import-mapping" name="user_import_mapping" rows="8" class="large-text code" placeholder="Email Address=user_email&#10;0=user_login&#10;First Name=first_name&#10;Membership ID=meta:membership_id"><?php echo esc_textarea( $mapping ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One mapping per line: CSV column name or zero-based column number = user field. Leave blank to use matching CSV headers.', 'user-import' ); ?><br>
								<?php esc_html_e( 'Supported fields include user_login, user_email, user_pass, user_nicename, user_url, display_name, first_name, last_name, nickname, description, locale, role, and meta:KEY for user meta.', 'user-import' ); ?>
							</p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<p class="submit">
					<?php if ( 'import' === $mode && 'upload' === $wizard_step ) : ?>
						<button id="user-import-continue-upload" type="submit" class="button button-primary" name="user_import_action" value="upload_csv" disabled><?php esc_html_e( 'Continue to column mapping', 'user-import' ); ?></button>
					<?php elseif ( 'import' === $mode && 'mapping' === $wizard_step ) : ?>
						<button type="submit" class="button" name="user_import_action" value="back_to_upload"><?php esc_html_e( 'Back', 'user-import' ); ?></button>
						<button type="submit" class="button button-primary" name="user_import_action" value="import_users"><?php esc_html_e( 'Start import', 'user-import' ); ?></button>
					<?php elseif ( 'import' === $mode ) : ?>
						<button type="submit" class="button" name="user_import_action" value="back_to_mapping"><?php esc_html_e( 'Back to mapping', 'user-import' ); ?></button>
						<button type="submit" class="button button-primary" name="user_import_action" value="restart_import"><?php esc_html_e( 'Run Again', 'user-import' ); ?></button>
					<?php else : ?>
						<button type="submit" class="button button-primary" name="user_import_action" value="export_csv"><?php esc_html_e( 'Export Mapped CSV', 'user-import' ); ?></button>
					<?php endif; ?>
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
	 * Read the header row from a CSV file for the mapping step.
	 *
	 * @param string $path CSV path.
	 * @return string[]
	 */
	private static function get_csv_headers( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a temporary CSV header row.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return array();
		}
		$headers = fgetcsv( stream: $handle, escape: '\\' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary CSV header stream.
		fclose( $handle );
		return false === $headers ? array() : array_map( static fn( $header ): string => trim( (string) $header ), $headers );
	}

	/**
	 * Keep an uploaded CSV available when an import needs correction and retry.
	 *
	 * @param array<string, mixed> $upload       Uploaded file data.
	 * @param string              $existing_token Existing pending upload token.
	 * @return string Pending upload token, or an empty string on failure.
	 */
	private static function persist_pending_upload( array $upload, string $existing_token = '' ): string {
		$existing_path = self::get_pending_upload_path( $existing_token );
		if ( '' !== $existing_token && $upload['tmp_name'] === $existing_path && file_exists( $existing_path ) && empty( $upload['error'] ) ) {
			return $existing_token;
		}

		if ( empty( $upload['tmp_name'] ) || ! empty( $upload['error'] ) || ! is_readable( $upload['tmp_name'] ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		$pending_dir = trailingslashit( $upload_dir['basedir'] ) . 'user-import-pending';
		if ( ! wp_mkdir_p( $pending_dir ) ) {
			return '';
		}

		$token = hash( 'sha256', wp_generate_uuid4() . microtime( true ) );
		$path  = trailingslashit( $pending_dir ) . get_current_user_id() . '-' . $token . '.csv';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying the uploaded temporary CSV into a protected retry location.
		if ( ! copy( $upload['tmp_name'], $path ) ) {
			return '';
		}

		if ( '' !== $existing_token && file_exists( $existing_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the replaced plugin-owned temporary CSV.
			unlink( $existing_path );
		}

		return $token;
	}

	/**
	 * Resolve a pending upload token for the current user.
	 *
	 * @param string $token Pending upload token.
	 * @return string Pending upload path.
	 */
	private static function get_pending_upload_path( string $token ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'user-import-pending/' . get_current_user_id() . '-' . $token . '.csv';
	}

	/**
	 * Delete a retained upload after a successful import.
	 *
	 * @param string $token Pending upload token.
	 * @return void
	 */
	private static function delete_pending_upload( string $token ): void {
		$path = self::get_pending_upload_path( $token );
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing this plugin-owned temporary CSV.
			unlink( $path );
		}
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
	 * @return array{imported: int, updated: int, skipped: int, errors: string[]}|WP_Error
	 */
	private static function import_csv( array $upload, string $mapping = '', array &$activity = array() ): array {
		$user_register_hook = 'user_register';
		$results = array(
			'imported' => 0,
			'updated'  => 0,
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

		global $wp_filter;
		$register_user_hooks = $wp_filter[ $user_register_hook ] ?? null;
		\remove_all_actions( $user_register_hook );

		try {
		$headers = fgetcsv( stream: $handle, escape: '\\' );
		if ( false === $headers ) {
			$results['errors'][] = __( 'The CSV file is empty.', 'user-import' );
			return $results;
		}

		$headers       = array_map( static fn( $header ): string => trim( (string) $header ), $headers );
		$column_mapping = self::parse_mapping( $mapping, $headers );
		if ( is_wp_error( $column_mapping ) ) {
			$results['errors'][] = $column_mapping->get_error_message();
			return $results;
		}

		$row_number = 1;
		while ( true ) {
			$row = fgetcsv( stream: $handle, escape: '\\' );
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

			$existing_user = get_user_by( 'login', $login );
			if ( ! $existing_user ) {
				$existing_user = get_user_by( 'email', $email );
			}

			if ( $existing_user ) {
				$update_data = array( 'ID' => (int) $existing_user->ID );
				foreach ( array( 'user_nicename', 'user_url', 'display_name', 'first_name', 'last_name', 'nickname', 'description', 'locale' ) as $field ) {
					if ( isset( $data[ $field ] ) ) {
						$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
					}
				}

				$role = sanitize_key( (string) ( $data['role'] ?? '' ) );
				if ( '' !== $role && get_role( $role ) ) {
					$update_data['role'] = $role;
				}

				$updated_user_id = wp_update_user( $update_data );
				if ( is_wp_error( $updated_user_id ) ) {
					/* translators: %d: CSV row number. */
					$results['errors'][] = sprintf( __( 'Row %d could not be updated.', 'user-import' ), $row_number );
					continue;
				}

				foreach ( $data as $field => $value ) {
					if ( str_starts_with( $field, 'meta:' ) ) {
						update_user_meta( $updated_user_id, sanitize_key( substr( $field, 5 ) ), sanitize_text_field( $value ) );
					}
				}

				++$results['updated'];
				$activity[] = sprintf( __( 'Updated user: %s', 'user-import' ), $login );
				continue;
			}

			$user_data = array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password(),
				'notify'      => 'none',
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
			$activity[] = sprintf( __( 'Created user: %s', 'user-import' ), $login );
		}

		return $results;
		} catch ( \Throwable $exception ) {
			$results['errors'][] = __( 'The CSV import could not be completed.', 'user-import' );
			return $results;
		} finally {
			if ( null === $register_user_hooks ) {
				unset( $wp_filter[ $user_register_hook ] );
			} else {
				$wp_filter[ $user_register_hook ] = $register_user_hooks;
			}

			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Always close the CSV handle, including exception paths.
				fclose( $handle );
			}

			if ( ! self::is_pending_upload_path( (string) $upload['tmp_name'] ) && file_exists( $upload['tmp_name'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the temporary uploaded CSV after processing.
				unlink( $upload['tmp_name'] );
			}
		}
	}

	/**
	 * Whether a file is a    retry upload.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function is_pending_upload_path( string $path ): bool {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return false;
		}

		$upload_dir  = wp_upload_dir();
		$pending_dir = trailingslashit( $upload_dir['basedir'] ) . 'user-import-pending/';
		$normalize   = static fn( string $value ): string => function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $value ) : str_replace( '\\', '/', $value );

		return str_starts_with( $normalize( $path ), $normalize( $pending_dir ) );
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
