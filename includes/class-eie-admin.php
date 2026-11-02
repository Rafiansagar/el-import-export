<?php
/**
 * Tools page, row and bulk actions, and the export/import request handlers.
 */

defined( 'ABSPATH' ) || exit;

class EIE_Admin {

	const PAGE = 'el-import-exporter';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_eie_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_eie_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_list_actions' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EIE_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'tools.php' ) );
	}

	public static function menu() {
		add_management_page(
			__( 'Elementor Import / Export', 'el-import-exporter' ),
			__( 'Elementor Import / Export', 'el-import-exporter' ),
			'export',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open', 'el-import-exporter' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'eie-admin', EIE_URL . 'assets/admin.css', array(), EIE_VERSION );
		wp_enqueue_script( 'eie-admin', EIE_URL . 'assets/admin.js', array(), EIE_VERSION, true );
	}

	/**
	 * "Export with media" row action and bulk action on every supported list table.
	 */
	public static function register_list_actions() {
		if ( ! current_user_can( 'export' ) ) {
			return;
		}

		$types = EIE_Util::post_types();

		$row_action = function ( $actions, $post ) use ( $types ) {
			if ( in_array( $post->post_type, $types, true ) && current_user_can( 'edit_post', $post->ID ) ) {
				$actions['eie_export'] = '<a href="' . esc_url( self::export_url( array( $post->ID ) ) ) . '">' . esc_html__( 'Export with media', 'el-import-exporter' ) . '</a>';
			}
			return $actions;
		};
		add_filter( 'post_row_actions', $row_action, 10, 2 );
		add_filter( 'page_row_actions', $row_action, 10, 2 );

		foreach ( $types as $type ) {
			add_filter(
				'bulk_actions-edit-' . $type,
				function ( $actions ) {
					$actions['eie_export'] = __( 'Export with media', 'el-import-exporter' );
					return $actions;
				}
			);
			add_filter(
				'handle_bulk_actions-edit-' . $type,
				function ( $redirect, $action, $ids ) {
					if ( 'eie_export' === $action ) {
						self::send_export( $ids );
					}
					return $redirect;
				},
				10,
				3
			);
		}
	}

	public static function export_url( array $ids ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'eie_export',
					'ids'    => implode( ',', array_map( 'absint', $ids ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'eie_export'
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( 'export' ) ) {
			wp_die( esc_html__( 'You are not allowed to export.', 'el-import-exporter' ), 403 );
		}
		check_admin_referer( 'eie_export' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to ints below.
		$ids = isset( $_REQUEST['ids'] ) ? wp_unslash( $_REQUEST['ids'] ) : array();
		if ( ! is_array( $ids ) ) {
			$ids = explode( ',', (string) $ids );
		}

		self::send_export( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Build the bundle and stream it to the browser.
	 *
	 * @param int[]  $ids      Post IDs.
	 */
	private static function send_export( array $ids ) {
		$exporter = new EIE_Exporter();
		$path     = $exporter->export( $ids );

		if ( is_wp_error( $path ) ) {
			self::flash( 'error', $path->get_error_message() );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$name = 'elementor-export-' . gmdate( 'Ymd-His' ) . '.zip';
		if ( 1 === count( $ids ) ) {
			$slug = get_post_field( 'post_name', reset( $ids ) );
			$name = 'elementor-' . sanitize_file_name( $slug ? $slug : 'post-' . reset( $ids ) ) . '-' . gmdate( 'Ymd' ) . '.zip';
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $path );
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( 'import' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You are not allowed to import.', 'el-import-exporter' ), 403 );
		}
		check_admin_referer( 'eie_import' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- file array, checked below.
		$file = isset( $_FILES['eie_bundle'] ) ? $_FILES['eie_bundle'] : null;

		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::flash( 'error', __( 'Upload failed. Check the file size against upload_max_filesize and post_max_size.', 'el-import-exporter' ) );
			wp_safe_redirect( self::page_url() );
			exit;
		}
		if ( 'zip' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
			self::flash( 'error', __( 'Please upload a .zip bundle.', 'el-import-exporter' ) );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$status = isset( $_POST['eie_status'] ) ? sanitize_key( wp_unslash( $_POST['eie_status'] ) ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'publish', 'original' ), true ) ) {
			$status = 'draft';
		}

		$importer = new EIE_Importer(
			array(
				'status'       => $status,
				'replace_urls' => ! empty( $_POST['eie_replace_urls'] ),
				'reuse_media'  => ! empty( $_POST['eie_reuse_media'] ),
			)
		);
		$report = $importer->import( $file['tmp_name'] );

		if ( is_wp_error( $report ) ) {
			self::flash( 'error', $report->get_error_message() );
		} else {
			set_transient( 'eie_report_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	private static function flash( $type, $message ) {
		set_transient(
			'eie_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	public static function render() {
		$user_id = get_current_user_id();
		$notice  = get_transient( 'eie_notice_' . $user_id );
		$report  = get_transient( 'eie_report_' . $user_id );
		delete_transient( 'eie_notice_' . $user_id );
		delete_transient( 'eie_report_' . $user_id );

		$types = EIE_Util::post_types();
		$posts = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => 1000,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		?>
		<div class="wrap eie">
			<h1><?php esc_html_e( 'Elementor Import / Export', 'el-import-exporter' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Moves pages, posts and Elementor templates between sites as one ZIP. Every image, background, gallery item and featured image they use travels inside the ZIP and is added to the media library on import.', 'el-import-exporter' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $report ) : ?>
				<div class="notice notice-success eie-report">
					<p><strong>
						<?php
						printf(
							/* translators: 1: posts, 2: new media, 3: reused media */
							esc_html__( 'Imported %1$d item(s). Media: %2$d added, %3$d reused.', 'el-import-exporter' ),
							count( $report['posts'] ),
							(int) $report['media_new'],
							(int) $report['media_reused']
						);
						?>
					</strong></p>
					<?php if ( $report['posts'] ) : ?>
						<ul>
							<?php foreach ( $report['posts'] as $item ) : ?>
								<li>
									<?php echo esc_html( $item['title'] ? $item['title'] : '#' . $item['id'] ); ?>
									<span class="eie-type"><?php echo esc_html( $item['type'] ); ?></span>
									&mdash; <a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'el-import-exporter' ); ?></a>
									<?php if ( 'builder' === get_post_meta( $item['id'], '_elementor_edit_mode', true ) ) : ?>
										| <a href="<?php echo esc_url( add_query_arg( array( 'post' => $item['id'], 'action' => 'elementor' ), admin_url( 'post.php' ) ) ); ?>"><?php esc_html_e( 'Edit with Elementor', 'el-import-exporter' ); ?></a>
									<?php endif; ?>
									<?php if ( 'elementor_library' !== $item['type'] ) : ?>
										| <a href="<?php echo esc_url( get_permalink( $item['id'] ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'el-import-exporter' ); ?></a>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( $report['errors'] ) : ?>
						<p><strong><?php esc_html_e( 'Warnings', 'el-import-exporter' ); ?></strong></p>
						<ul class="eie-errors">
							<?php foreach ( $report['errors'] as $error ) : ?>
								<li><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The PHP zip extension is not enabled. Export and import need it.', 'el-import-exporter' ); ?></p></div>
			<?php endif; ?>

			<div class="eie-grid">
				<div class="eie-card eie-export">
					<h2><?php esc_html_e( 'Export', 'el-import-exporter' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="eie_export">
						<?php wp_nonce_field( 'eie_export' ); ?>

						<div class="eie-filters">
							<select id="eie-type-filter">
								<option value=""><?php esc_html_e( 'All types', 'el-import-exporter' ); ?></option>
								<?php foreach ( $types as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( get_post_type_object( $type )->labels->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="search" id="eie-search" placeholder="<?php esc_attr_e( 'Search titles…', 'el-import-exporter' ); ?>">
							<label><input type="checkbox" id="eie-elementor-only" checked> <?php esc_html_e( 'Built with Elementor only', 'el-import-exporter' ); ?></label>
						</div>

						<div class="eie-table-wrap">
							<table class="widefat striped eie-table">
								<thead>
									<tr>
										<td class="check-column"><input type="checkbox" id="eie-check-all"></td>
										<th><?php esc_html_e( 'Title', 'el-import-exporter' ); ?></th>
										<th><?php esc_html_e( 'Type', 'el-import-exporter' ); ?></th>
										<th><?php esc_html_e( 'Status', 'el-import-exporter' ); ?></th>
										<th><?php esc_html_e( 'Modified', 'el-import-exporter' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $posts as $post ) : ?>
										<?php
										$is_elementor  = 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
										$template_type = 'elementor_library' === $post->post_type ? get_post_meta( $post->ID, '_elementor_template_type', true ) : '';
										?>
										<tr data-type="<?php echo esc_attr( $post->post_type ); ?>" data-elementor="<?php echo $is_elementor ? '1' : '0'; ?>">
											<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $post->ID ); ?>"></th>
											<td class="eie-title"><?php echo esc_html( $post->post_title ? $post->post_title : __( '(no title)', 'el-import-exporter' ) ); ?></td>
											<td>
												<?php echo esc_html( get_post_type_object( $post->post_type )->labels->singular_name ); ?>
												<?php if ( $template_type ) : ?>
													<span class="eie-type"><?php echo esc_html( $template_type ); ?></span>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $post->post_status ); ?></td>
											<td><?php echo esc_html( get_the_modified_date( '', $post ) ); ?></td>
										</tr>
									<?php endforeach; ?>
									<tr class="eie-empty" hidden><td colspan="5"><?php esc_html_e( 'Nothing matches.', 'el-import-exporter' ); ?></td></tr>
								</tbody>
							</table>
						</div>

						<p class="eie-actions">
							<button type="submit" class="button button-primary" id="eie-export-btn" disabled><?php esc_html_e( 'Download ZIP', 'el-import-exporter' ); ?></button>
							<span class="eie-count" id="eie-count"></span>
						</p>
					</form>
				</div>

				<div class="eie-card eie-import">
					<h2><?php esc_html_e( 'Import', 'el-import-exporter' ); ?></h2>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="eie_import">
						<?php wp_nonce_field( 'eie_import' ); ?>

						<p><input type="file" name="eie_bundle" accept=".zip,application/zip" required></p>
						<p class="description">
							<?php
							printf(
								/* translators: %s: max upload size */
								esc_html__( 'Maximum upload size: %s.', 'el-import-exporter' ),
								esc_html( size_format( wp_max_upload_size() ) )
							);
							?>
						</p>

						<p>
							<label for="eie-status"><strong><?php esc_html_e( 'Status of imported items', 'el-import-exporter' ); ?></strong></label><br>
							<select name="eie_status" id="eie-status">
								<option value="draft"><?php esc_html_e( 'Draft', 'el-import-exporter' ); ?></option>
								<option value="original"><?php esc_html_e( 'Same as source', 'el-import-exporter' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Published', 'el-import-exporter' ); ?></option>
							</select>
						</p>
						<p><label><input type="checkbox" name="eie_reuse_media" value="1" checked> <?php esc_html_e( 'Reuse images already imported from an identical file', 'el-import-exporter' ); ?></label></p>
						<p><label><input type="checkbox" name="eie_replace_urls" value="1" checked> <?php esc_html_e( 'Rewrite links that point at the source site to this site', 'el-import-exporter' ); ?></label></p>

						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import ZIP', 'el-import-exporter' ); ?></button></p>
					</form>
					<p class="description"><?php esc_html_e( 'Imports always create new items; nothing on this site is overwritten. Elementor CSS is regenerated afterwards.', 'el-import-exporter' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
