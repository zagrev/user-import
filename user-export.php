<?php
/**
 * WordPress user export workflow.
 *
 * @package UserImport
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the user export admin page and CSV download.
 */
final class User_Export_Plugin {
	private const MENU_SLUG = 'user-export';

	/**
	 * Register the exporter hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		\add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		\add_action( 'admin_post_user_export_csv', array( self::class, 'download_users_csv' ) );
	}

	/**
	 * Register the exporter under the Users menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		\add_users_page(
			__( 'User Exporter', 'user-import' ),
			__( 'Export Users', 'user-import' ),
			'list_users',
			self::MENU_SLUG,
			array( self::class, 'render_export_page' )
		);
	}

	/**
	 * Enqueue the exporter field selection interface.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'users_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}
		\wp_enqueue_script( 'user-export-admin', plugin_dir_url( __FILE__ ) . 'user-export.js', array(), (string) filemtime( __DIR__ . '/user-export.js' ), true );
		\wp_enqueue_style( 'user-export-admin', plugin_dir_url( __FILE__ ) . 'user-export.css', array(), (string) filemtime( __DIR__ . '/user-export.css' ) );
	}

	/**
	 * Render the export page.
	 *
	 * @return void
	 */
	public static function render_export_page(): void {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to export users.', 'user-import' ) );
		}

		$step   = 'selection';
		$error  = '';
		$state  = array(
			'source' => 'group',
			'group'  => '',
			'query'  => '',
			'fields' => array( 'user_login', 'user_email', 'first_name', 'last_name' ),
		);
		$action = isset( $_POST['user_export_step'] ) ? sanitize_key( wp_unslash( $_POST['user_export_step'] ) ) : '';
		if ( '' !== $action ) {
			check_admin_referer( 'user_export' );
			$state = self::get_export_state();
			$check = self::get_export_query_args( $state );
			if ( 'selection' !== $action && is_wp_error( $check ) ) {
				$error = $check->get_error_message();
			} else {
				$step = in_array( $action, array( 'selection', 'fields', 'download' ), true ) ? $action : 'selection';
			}
			if ( 'download' === $step && empty( $state['fields'] ) ) {
				$step  = 'fields';
				$error = __( 'Select at least one user field.', 'user-import' );
			}
		}
		$fields = self::get_export_fields();
		$groups = self::get_export_groups();
		?>
		<div class="wrap user-export">
			<h1><?php esc_html_e( 'Export Users', 'user-import' ); ?></h1>
			<h2><?php echo esc_html( 'selection' === $step ? __( 'Step 1: Select users', 'user-import' ) : ( 'fields' === $step ? __( 'Step 2: Select and order fields', 'user-import' ) : __( 'Step 3: Download CSV', 'user-import' ) ) ); ?></h2>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( 'download' === $step ? admin_url( 'admin-post.php' ) : admin_url( 'users.php?page=user-export' ) ); ?>">
				<?php wp_nonce_field( 'user_export' ); ?>
				<?php if ( 'selection' === $step ) : ?>
					<fieldset class="user-export-source">
						<legend><?php esc_html_e( 'Select users by', 'user-import' ); ?></legend>
						<label><input type="radio" name="user_export_source" value="group" <?php checked( $state['source'], 'group' ); ?>><?php esc_html_e( 'Group / role', 'user-import' ); ?></label>
						<label><input type="radio" name="user_export_source" value="query" <?php checked( $state['source'], 'query' ); ?>><?php esc_html_e( 'Search query', 'user-import' ); ?></label>
					</fieldset>
					<p><label for="user-export-group"><?php esc_html_e( 'Group / role', 'user-import' ); ?></label><br>
						<select id="user-export-group" name="user_export_group">
							<option value=""><?php esc_html_e( 'All users', 'user-import' ); ?></option>
							<?php foreach ( $groups as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state['group'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><label for="user-export-query"><?php esc_html_e( 'Username, email or name', 'user-import' ); ?></label><br><input id="user-export-query" class="regular-text" type="search" name="user_export_query" aria-describedby="user-export-query-examples" value="<?php echo esc_attr( $state['query'] ); ?>"></p>
					<div id="user-export-query-examples" class="description">
						<p><?php esc_html_e( 'Enter plain text to match any part of a username, email, nicename or display name. Examples:', 'user-import' ); ?></p>
						<ul>
							<li><code>alex</code>: <?php esc_html_e( 'Users whose username or name contains alex.', 'user-import' ); ?></li>
							<li><code>alex@example.com</code>: <?php esc_html_e( 'Users whose email contains this address.', 'user-import' ); ?></li>
							<li><code>@example.com</code>: <?php esc_html_e( 'Users with an email at this domain.', 'user-import' ); ?></li>
							<li><code>Alex Smith</code>: <?php esc_html_e( 'Users whose display name contains this phrase.', 'user-import' ); ?></li>
						</ul>
						<p><?php esc_html_e( 'Use one search term or phrase. SQL and field:value expressions are not supported.', 'user-import' ); ?></p>
					</div>
				<?php else : ?>
					<input type="hidden" name="user_export_source" value="<?php echo esc_attr( $state['source'] ); ?>">
					<input type="hidden" name="user_export_group" value="<?php echo esc_attr( $state['group'] ); ?>">
					<input type="hidden" name="user_export_query" value="<?php echo esc_attr( $state['query'] ); ?>">
				<?php endif; ?>
				<?php if ( 'fields' === $step ) : ?>
					<div class="user-export-field-panels">
						<section><h3><?php esc_html_e( 'Available fields', 'user-import' ); ?></h3><ul id="user-export-available" class="user-export-field-list" aria-label="<?php esc_attr_e( 'Available fields', 'user-import' ); ?>">
							<?php foreach ( $fields as $key => $label ) : ?>
								<?php if ( ! in_array( $key, $state['fields'], true ) ) : ?>
									<?php self::render_export_field( $key, $label, false ); ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul></section>
						<section><h3><?php esc_html_e( 'CSV columns', 'user-import' ); ?></h3><ul id="user-export-selected" class="user-export-field-list" aria-label="<?php esc_attr_e( 'CSV columns in order', 'user-import' ); ?>">
							<?php foreach ( $state['fields'] as $key ) : ?>
								<?php self::render_export_field( $key, $fields[ $key ], true ); ?>
								<?php endforeach; ?>
						</ul></section>
					</div>
				<?php else : ?>
					<?php foreach ( $state['fields'] as $key ) : ?>
						<input type="hidden" name="user_export_fields[]" value="<?php echo esc_attr( $key ); ?>">
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if ( 'download' === $step ) : ?>
					<?php
					$users = new WP_User_Query(
						array_merge(
							self::get_export_query_args( $state ),
							array(
								'number'      => 1,
								'fields'      => 'ID',
								'count_total' => true,
							)
						)
					);
					?>
					<p><?php esc_html_e( 'Matching users:', 'user-import' ); ?> <?php echo esc_html( (string) $users->get_total() ); ?></p>
					<ol>
						<?php foreach ( $state['fields'] as $key ) : ?>
							<li><?php echo esc_html( $fields[ $key ] ); ?></li>
						<?php endforeach; ?>
					</ol>
					<input type="hidden" name="action" value="user_export_csv">
					<button type="submit" class="button" name="user_export_step" value="fields" formaction="<?php echo esc_url( admin_url( 'users.php?page=user-export' ) ); ?>"><?php esc_html_e( 'Back to fields', 'user-import' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Download CSV', 'user-import' ); ?></button>
				<?php elseif ( 'fields' === $step ) : ?>
					<p><button type="submit" class="button" name="user_export_step" value="selection"><?php esc_html_e( 'Back to users', 'user-import' ); ?></button>
					<button type="submit" class="button button-primary" name="user_export_step" value="download"><?php esc_html_e( 'Continue to download', 'user-import' ); ?></button></p>
				<?php else : ?>
					<p><button type="submit" class="button button-primary" name="user_export_step" value="fields"><?php esc_html_e( 'Continue to fields', 'user-import' ); ?></button></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Return the allowlisted export fields, excluding password hashes.
	 *
	 * @return array<string, string> Field keys and labels.
	 */
	private static function get_export_fields(): array {
		$fields = User_Import_Plugin::get_mapping_fields();
		unset( $fields['user_pass'], $fields['meta:user_pass'] );
		$fields = array_merge(
			array(
				'ID'              => __( 'User ID', 'user-import' ),
				'roles'           => __( 'Roles', 'user-import' ),
				'user_registered' => __( 'Registered date', 'user-import' ),
			),
			$fields
		);
		asort( $fields, SORT_NATURAL | SORT_FLAG_CASE );
		return $fields;
	}

	/**
	 * Render a selectable and draggable export field.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param bool   $selected Whether the field is selected.
	 * @return void
	 */
	private static function render_export_field( string $key, string $label, bool $selected ): void {
		?>
		<li class="user-export-field" draggable="true" data-field="<?php echo esc_attr( $key ); ?>">
			<span class="dashicons dashicons-menu" aria-hidden="true"></span>
			<label><input type="checkbox" name="user_export_fields[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $selected ); ?>><?php echo esc_html( $label ); ?></label>
			<button type="button" class="button user-export-up" aria-label="<?php esc_attr_e( 'Move up', 'user-import' ); ?>" title="<?php esc_attr_e( 'Move up', 'user-import' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
			<button type="button" class="button user-export-down" aria-label="<?php esc_attr_e( 'Move down', 'user-import' ); ?>" title="<?php esc_attr_e( 'Move down', 'user-import' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
		</li>
		<?php
	}

	/**
	 * Discover roles and WP Mailster groups for the current site.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * WP Mailster has no group API; trusted table names use the WordPress prefix.
	 * Read current membership without caching for each export.
	 *
	 * @return array<string, string> Selection keys and labels.
	 */
	private static function get_export_groups(): array {
		$groups = array();
		foreach ( User_Import_Plugin::get_available_roles() as $role => $label ) {
			$groups[ 'role:' . $role ] = $label;
		}
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$table = $wpdb->prefix . 'mailster_groups';
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$rows = $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY name" );
				foreach ( (array) $rows as $group ) {
					$groups[ 'mailster:' . $group->id ] = 'WP Mailster: ' . $group->name;
				}
			}
		}
		return $groups;
	}

	/**
	 * Read the nonce-protected selection and ordered field allowlist.
	 *
	 * phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 *
	 * @return array<string, mixed> Export form state.
	 */
	private static function get_export_state(): array {
		check_admin_referer( 'user_export' );
		$source         = isset( $_POST['user_export_source'] ) && is_string( $_POST['user_export_source'] ) ? sanitize_key( wp_unslash( $_POST['user_export_source'] ) ) : 'group';
		$fields         = isset( $_POST['user_export_fields'] ) && is_array( $_POST['user_export_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['user_export_fields'] ) ) : array();
		$allowed_fields = self::get_export_fields();
		return array(
			'source' => $source,
			'group'  => isset( $_POST['user_export_group'] ) && is_string( $_POST['user_export_group'] ) ? sanitize_text_field( wp_unslash( $_POST['user_export_group'] ) ) : '',
			'query'  => isset( $_POST['user_export_query'] ) && is_string( $_POST['user_export_query'] ) ? sanitize_text_field( wp_unslash( $_POST['user_export_query'] ) ) : '',
			'fields' => array_values( array_unique( array_filter( $fields, static fn( $field ): bool => is_string( $field ) && isset( $allowed_fields[ $field ] ) ) ) ),
		);
	}

	/**
	 * Validate the selector and build WordPress user query arguments.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * WP Mailster membership requires live reads from its prefixed table.
	 *
	 * @param array<string, mixed> $state Export form state.
	 * @return array<string, mixed>|WP_Error Query arguments or selection error.
	 */
	private static function get_export_query_args( array $state ): array|WP_Error {
		$args = array(
			'orderby' => 'ID',
			'order'   => 'ASC',
		);
		if ( 'query' === $state['source'] ) {
			if ( '' === $state['query'] ) {
				return new WP_Error( 'empty_query', __( 'Enter a user search query.', 'user-import' ) );
			}
			$args['search']         = '*' . $state['query'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		} elseif ( 'group' !== $state['source'] ) {
			return new WP_Error( 'invalid_source', __( 'Select a valid user selection method.', 'user-import' ) );
		} elseif ( '' !== $state['group'] ) {
			if ( ! isset( self::get_export_groups()[ $state['group'] ] ) ) {
				return new WP_Error( 'invalid_group', __( 'Select an available group or role.', 'user-import' ) );
			}
			if ( str_starts_with( $state['group'], 'role:' ) ) {
				$args['role'] = substr( $state['group'], 5 );
			} else {
				global $wpdb;
				$table           = $wpdb->prefix . 'mailster_group_users';
				$ids             = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE group_id = %d AND is_core_user = 1", (int) substr( $state['group'], 9 ) ) );
				$args['include'] = empty( $ids ) ? array( 0 ) : array_map( 'intval', $ids );
			}
		}
		return $args;
	}

	/**
	 * Download selected users before WordPress renders any admin HTML.
	 *
	 * phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	 * The CSV response uses a PHP output stream, not a filesystem file.
	 *
	 * @return void
	 */
	public static function download_users_csv(): void {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to export users.', 'user-import' ) );
		}
		check_admin_referer( 'user_export' );
		$state = self::get_export_state();
		$args  = self::get_export_query_args( $state );
		if ( is_wp_error( $args ) || empty( $state['fields'] ) ) {
			wp_die( esc_html( is_wp_error( $args ) ? $args->get_error_message() : __( 'Select at least one user field.', 'user-import' ) ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="users.csv"' );
		$output = fopen( 'php://output', 'wb' );
		self::write_users_csv( $output, $args, $state['fields'] );
		fclose( $output );
		exit;
	}

	/**
	 * Stream CSV rows in batches, preserving the selected field order.
	 *
	 * phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	 *
	 * @param resource             $output CSV output stream.
	 * @param array<string, mixed> $args User query arguments.
	 * @param array<string>        $fields Ordered field keys.
	 * @return void
	 */
	private static function write_users_csv( $output, array $args, array $fields ): void {
		fputcsv( $output, $fields, ',', '"', '' );
		$offset = 0;
		do {
			$query      = new WP_User_Query(
				array_merge(
					$args,
					array(
						'number'      => 500,
						'offset'      => $offset,
						'count_total' => false,
					)
				)
			);
			$users      = $query->get_results();
			$user_count = count( $users );
			foreach ( $users as $user ) {
				$row = array();
				foreach ( $fields as $field ) {
					$value = str_starts_with( $field, 'meta:' ) ? get_user_meta( $user->ID, substr( $field, 5 ), true ) : ( $user->$field ?? '' );
					$value = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
					$row[] = preg_match( '/^[\s]*[=+@-]/u', $value ) ? "'" . $value : $value;
				}
				fputcsv( $output, $row, ',', '"', '' );
			}
			$offset += 500;
		} while ( 500 === $user_count );
	}
}