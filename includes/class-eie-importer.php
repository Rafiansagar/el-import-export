<?php
/**
 * Reads a bundle made by EIE_Exporter: sideloads its media, creates the posts and
 * points every media reference at the new attachments.
 */

defined( 'ABSPATH' ) || exit;

class EIE_Importer {

	/** @var array Import options. */
	private $args;

	/** @var array Bundle manifest. */
	private $manifest;

	/** @var array Bundle media key => new attachment ID. */
	private $media_ids = array();

	/** @var array Old attachment ID => new attachment ID. */
	private $attachment_map = array();

	/** @var array Schemeless old URL => new attachment ID. */
	private $url_to_id = array();

	/** @var array Old URL => new URL, every scheme form. */
	private $url_map = array();

	/** @var array Old post ID => new post ID. */
	private $post_map = array();

	/** @var array Report shown after the import. */
	private $report = array(
		'posts'        => array(),
		'media_new'    => 0,
		'media_reused' => 0,
		'errors'       => array(),
	);

	/**
	 * @param array $args {
	 *     @type string $status        'original' keeps each post's status, anything else is used as the status.
	 *     @type bool   $replace_urls  Rewrite links to the source site to this site.
	 *     @type bool   $reuse_media   Reuse media already imported from an identical file.
	 * }
	 */
	public function __construct( array $args = array() ) {
		$this->args = wp_parse_args(
			$args,
			array(
				'status'       => 'draft',
				'replace_urls' => true,
				'reuse_media'  => true,
			)
		);
	}

	/**
	 * Import a bundle.
	 *
	 * @param string $zip_path Path of the uploaded ZIP.
	 * @return array|WP_Error Report.
	 */
	public function import( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'eie_no_zip', __( 'The PHP zip extension is required.', 'el-import-exporter' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'eie_zip_open', __( 'The file is not a valid ZIP archive.', 'el-import-exporter' ) );
		}

		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['posts'] ) || ! is_array( $manifest['posts'] ) ) {
			$zip->close();
			return new WP_Error( 'eie_manifest', __( 'This ZIP is not an EL Import Exporter bundle.', 'el-import-exporter' ) );
		}
		if ( (int) ( $manifest['bundle_version'] ?? 0 ) > EIE_Util::BUNDLE_VERSION ) {
			$zip->close();
			return new WP_Error( 'eie_version', __( 'This bundle was made by a newer version of the plugin.', 'el-import-exporter' ) );
		}

		$this->manifest = wp_parse_args(
			$manifest,
			array(
				'source_home' => '',
				'media'       => array(),
			)
		);

		EIE_Util::raise_limits();

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		foreach ( (array) $this->manifest['media'] as $entry ) {
			if ( is_array( $entry ) ) {
				$this->import_media( $zip, $entry );
			}
		}
		$zip->close();

		if ( $this->args['replace_urls'] && $this->manifest['source_home'] ) {
			EIE_Util::add_url_pair( $this->url_map, untrailingslashit( $this->manifest['source_home'] ), untrailingslashit( home_url() ) );
		}

		$this->import_posts( $this->manifest['posts'] );
		$this->flush_elementor();

		return $this->report;
	}

	/**
	 * Same site the bundle came from?
	 *
	 * @return bool
	 */
	private function is_same_site() {
		return EIE_Util::schemeless( untrailingslashit( $this->manifest['source_home'] ) ) === EIE_Util::schemeless( untrailingslashit( home_url() ) );
	}

	/**
	 * Bring one media file into the library, or find the copy that is already there.
	 *
	 * @param ZipArchive $zip   Bundle.
	 * @param array      $entry Media entry.
	 */
	private function import_media( ZipArchive $zip, array $entry ) {
		$entry = wp_parse_args(
			$entry,
			array(
				'key'         => '',
				'old_id'      => 0,
				'file'        => '',
				'title'       => '',
				'caption'     => '',
				'description' => '',
				'alt'         => '',
				'sizes'       => array(),
			)
		);

		if ( ! preg_match( '#^media/m\d+/[^/\\\\]+$#', $entry['file'] ) ) {
			$this->report['errors'][] = sprintf( __( 'Skipped media with an invalid path: %s', 'el-import-exporter' ), $entry['file'] );
			return;
		}

		// Exported from this very site: keep the original attachment, and loose files where they are.
		$old_id = (int) $entry['old_id'];
		if ( ! $old_id && $this->is_same_site() ) {
			return;
		}
		if ( $old_id && $this->is_same_site() && 'attachment' === get_post_type( $old_id ) && get_attached_file( $old_id ) && file_exists( get_attached_file( $old_id ) ) ) {
			$this->map_media( $entry, $old_id );
			++$this->report['media_reused'];
			return;
		}

		$contents = $zip->getFromName( $entry['file'] );
		if ( false === $contents ) {
			$this->report['errors'][] = sprintf( __( 'Missing file in bundle: %s', 'el-import-exporter' ), $entry['file'] );
			return;
		}

		$hash = md5( $contents );
		if ( $this->args['reuse_media'] ) {
			$existing = get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'inherit',
					'meta_key'         => '_eie_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'           => 'ids',
					'numberposts'      => 1,
					'suppress_filters' => true,
				)
			);
			if ( $existing ) {
				$this->map_media( $entry, (int) $existing[0] );
				++$this->report['media_reused'];
				return;
			}
		}

		$name = sanitize_file_name( wp_basename( $entry['file'] ) );
		$tmp  = wp_tempnam( $name );
		file_put_contents( $tmp, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		unset( $contents );

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$new_id = media_handle_sideload(
			$file_array,
			0,
			$entry['title'],
			array(
				'post_excerpt' => $entry['caption'],
				'post_content' => $entry['description'],
			)
		);

		if ( is_wp_error( $new_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			$this->report['errors'][] = sprintf( '%s: %s', $name, $new_id->get_error_message() );
			return;
		}

		update_post_meta( $new_id, '_eie_hash', $hash );
		if ( '' !== $entry['alt'] ) {
			update_post_meta( $new_id, '_wp_attachment_image_alt', wp_slash( $entry['alt'] ) );
		}

		$this->map_media( $entry, (int) $new_id );
		++$this->report['media_new'];
	}

	/**
	 * Record how the old URLs and ID of an entry translate to a local attachment.
	 *
	 * @param array $entry  Media entry.
	 * @param int   $new_id Attachment ID on this site.
	 */
	private function map_media( array $entry, $new_id ) {
		$this->media_ids[ $entry['key'] ] = $new_id;
		if ( $entry['old_id'] ) {
			$this->attachment_map[ (int) $entry['old_id'] ] = $new_id;
		}

		$full = wp_get_attachment_url( $new_id );
		foreach ( (array) $entry['sizes'] as $size => $old_url ) {
			if ( ! is_string( $old_url ) || '' === $old_url ) {
				continue;
			}

			if ( 'full' === $size ) {
				$new_url = $full;
			} elseif ( 'original' === $size ) {
				$new_url = function_exists( 'wp_get_original_image_url' ) ? wp_get_original_image_url( $new_id ) : $full;
			} else {
				$src     = wp_get_attachment_image_src( $new_id, $size );
				$new_url = $src ? $src[0] : $full;
			}

			$this->url_to_id[ EIE_Util::schemeless( $old_url ) ] = $new_id;
			EIE_Util::add_url_pair( $this->url_map, $old_url, $new_url ? $new_url : $full );
		}
	}

	/**
	 * Create every post first so references between them can be remapped, then fill them in.
	 *
	 * @param array $posts Post entries.
	 */
	private function import_posts( array $posts ) {
		$created = array();

		foreach ( $posts as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['post_type'] ) ) {
				continue;
			}

			if ( ! post_type_exists( $entry['post_type'] ) ) {
				$this->report['errors'][] = sprintf( __( 'Skipped "%1$s": post type %2$s does not exist here.', 'el-import-exporter' ), $entry['post_title'] ?? '', $entry['post_type'] );
				continue;
			}

			$status = 'original' === $this->args['status'] ? ( $entry['post_status'] ?? 'draft' ) : $this->args['status'];
			if ( 'auto-draft' === $status || 'trash' === $status || 'inherit' === $status ) {
				$status = 'draft';
			}

			$new_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => $entry['post_type'],
						'post_title'     => (string) ( $entry['post_title'] ?? '' ),
						'post_name'      => (string) ( $entry['post_name'] ?? '' ),
						'post_status'    => $status,
						'post_date'      => (string) ( $entry['post_date'] ?? '' ),
						'menu_order'     => (int) ( $entry['menu_order'] ?? 0 ),
						'comment_status' => (string) ( $entry['comment_status'] ?? '' ),
						'ping_status'    => (string) ( $entry['ping_status'] ?? '' ),
						'post_password'  => (string) ( $entry['post_password'] ?? '' ),
					)
				),
				true
			);

			if ( is_wp_error( $new_id ) ) {
				$this->report['errors'][] = sprintf( '%s: %s', $entry['post_title'] ?? '', $new_id->get_error_message() );
				continue;
			}

			$this->post_map[ (int) ( $entry['old_id'] ?? 0 ) ] = $new_id;
			$created[ $new_id ] = $entry;
		}

		foreach ( $created as $new_id => $entry ) {
			$this->fill_post( $new_id, $entry );
		}
	}

	/**
	 * Write content, meta, Elementor data, terms and the featured image of an imported post.
	 *
	 * @param int   $post_id Post ID on this site.
	 * @param array $entry   Post entry.
	 */
	private function fill_post( $post_id, array $entry ) {
		$old_parent = (int) ( $entry['post_parent'] ?? 0 );

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $this->replace_content( (string) ( $entry['post_content'] ?? '' ) ),
					'post_excerpt' => $this->replace_urls( (string) ( $entry['post_excerpt'] ?? '' ) ),
					'post_parent'  => isset( $this->post_map[ $old_parent ] ) ? $this->post_map[ $old_parent ] : 0,
				)
			)
		);

		$skip = EIE_Util::skipped_meta_keys();
		foreach ( (array) ( $entry['meta'] ?? array() ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) || ! is_array( $values ) ) {
				continue;
			}

			delete_post_meta( $post_id, $key );
			foreach ( $values as $value ) {
				$value = '_elementor_conditions' === $key ? $this->replace_conditions( $value ) : $this->replace_deep( $value );
				add_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		if ( isset( $entry['elementor_data'] ) && null !== $entry['elementor_data'] ) {
			$data = $this->replace_deep( $entry['elementor_data'] );
			$json = is_array( $data ) ? wp_json_encode( $data ) : (string) $data;
			update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		}

		if ( ! empty( $entry['thumbnail'] ) && isset( $this->media_ids[ $entry['thumbnail'] ] ) ) {
			set_post_thumbnail( $post_id, $this->media_ids[ $entry['thumbnail'] ] );
		}

		foreach ( (array) ( $entry['terms'] ?? array() ) as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( (array) $terms as $term ) {
				if ( empty( $term['slug'] ) ) {
					continue;
				}
				$found = get_term_by( 'slug', $term['slug'], $taxonomy );
				if ( ! $found ) {
					$inserted = wp_insert_term( $term['name'] ?? $term['slug'], $taxonomy, array( 'slug' => $term['slug'] ) );
					if ( is_wp_error( $inserted ) ) {
						continue;
					}
					$term_ids[] = (int) $inserted['term_id'];
				} else {
					$term_ids[] = (int) $found->term_id;
				}
			}

			if ( $term_ids ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}

		update_post_meta(
			$post_id,
			'_eie_source',
			array(
				'home'   => $this->manifest['source_home'],
				'old_id' => (int) ( $entry['old_id'] ?? 0 ),
			)
		);

		$this->report['posts'][] = array(
			'id'    => $post_id,
			'title' => get_the_title( $post_id ),
			'type'  => get_post_type( $post_id ),
		);
	}

	/**
	 * Remap media and post references anywhere in a nested value.
	 *
	 * @param mixed $data Value.
	 * @return mixed
	 */
	private function replace_deep( $data ) {
		return EIE_Util::map_deep(
			$data,
			function ( $node ) {
				if ( is_array( $node ) ) {
					// Media control: the ID follows the URL, which the scalar pass rewrites next.
					if ( isset( $node['id'], $node['url'] ) && is_numeric( $node['id'] ) && is_string( $node['url'] ) ) {
						$url = EIE_Util::schemeless( $node['url'] );
						if ( isset( $this->url_to_id[ $url ] ) ) {
							$node['id'] = $this->url_to_id[ $url ];
						} elseif ( isset( $this->attachment_map[ (int) $node['id'] ] ) ) {
							$node['id'] = $this->attachment_map[ (int) $node['id'] ];
						}
					}

					// Template widget, global widgets, loop templates.
					foreach ( array( 'template_id', 'templateID' ) as $key ) {
						if ( isset( $node[ $key ] ) && is_numeric( $node[ $key ] ) && isset( $this->post_map[ (int) $node[ $key ] ] ) ) {
							$node[ $key ] = is_string( $node[ $key ] ) ? (string) $this->post_map[ (int) $node[ $key ] ] : $this->post_map[ (int) $node[ $key ] ];
						}
					}

					return $node;
				}

				// Text and HTML widgets hold the same markup as post content.
				return is_string( $node ) ? $this->replace_content( $node ) : $node;
			}
		);
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private function replace_urls( $text ) {
		return $this->url_map && '' !== $text ? strtr( $text, $this->url_map ) : $text;
	}

	/**
	 * Post content: URLs plus the attachment IDs WordPress writes into image classes and blocks.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function replace_content( $content ) {
		$content = $this->replace_urls( $content );
		if ( ! $this->attachment_map ) {
			return $content;
		}

		$map = $this->attachment_map;

		$content = preg_replace_callback(
			'/\bwp-image-(\d+)\b/',
			function ( $m ) use ( $map ) {
				return isset( $map[ (int) $m[1] ] ) ? 'wp-image-' . $map[ (int) $m[1] ] : $m[0];
			},
			$content
		);

		// Block comments: <!-- wp:image {"id":123,...} -->.
		return preg_replace_callback(
			'/(<!-- wp:(?:image|cover|media-text|video|audio|file)\s+\{[^}]*?"(?:id|mediaId)":)(\d+)/',
			function ( $m ) use ( $map ) {
				return isset( $map[ (int) $m[2] ] ) ? $m[1] . $map[ (int) $m[2] ] : $m[0];
			},
			$content
		);
	}

	/**
	 * Elementor Pro display conditions end in a post ID, e.g. include/singular/page/123.
	 *
	 * @param mixed $conditions Conditions.
	 * @return mixed
	 */
	private function replace_conditions( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			return $conditions;
		}

		foreach ( $conditions as $i => $condition ) {
			if ( is_string( $condition ) && preg_match( '#^(.*/)(\d+)$#', $condition, $m ) && isset( $this->post_map[ (int) $m[2] ] ) ) {
				$conditions[ $i ] = $m[1] . $this->post_map[ (int) $m[2] ];
			}
		}

		return $conditions;
	}

	/**
	 * Drop generated CSS and rebuild the Theme Builder conditions cache.
	 */
	private function flush_elementor() {
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
			if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
				\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();
			}
		} catch ( \Throwable $e ) {
			$this->report['errors'][] = $e->getMessage();
		}
	}
}
