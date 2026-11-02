<?php
/**
 * Builds a ZIP bundle: manifest.json plus every media file the exported posts reference.
 */

defined( 'ABSPATH' ) || exit;

class EIE_Exporter {

	/** @var array Media entries keyed by bundle key (m1, m2, ...). */
	private $media = array();

	/** @var array Attachment ID => bundle key. */
	private $by_id = array();

	/** @var array Schemeless URL => bundle key, or false when the URL cannot be bundled. */
	private $by_url = array();

	/** @var array Result of wp_get_upload_dir(). */
	private $uploads;

	/** @var string Regex matching media URLs inside the uploads directory. */
	private $url_pattern;

	public function __construct() {
		$this->uploads = wp_get_upload_dir();

		$base              = preg_quote( ltrim( EIE_Util::schemeless( $this->uploads['baseurl'] ), '/' ), '#' );
		$base              = str_replace( '/', '(?:/|\\\\/)', $base );
		$this->url_pattern = '#(?:https?:)?(?://|\\\\/\\\\/)' . $base . '(?:/|\\\\/)[^\s"\'<>()?\#,]+?\.(?:' . EIE_Util::MEDIA_EXTENSIONS . ')(?![\w.])#i';
	}

	/**
	 * Export posts to a temporary ZIP file.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return string|WP_Error Path of the ZIP file.
	 */
	public function export( array $post_ids ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'eie_no_zip', __( 'The PHP zip extension is required.', 'el-import-exporter' ) );
		}

		EIE_Util::raise_limits();

		$posts = array();
		foreach ( array_unique( array_map( 'absint', $post_ids ) ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$posts[] = $this->export_post( $post );
		}

		if ( ! $posts ) {
			return new WP_Error( 'eie_nothing', __( 'Nothing to export.', 'el-import-exporter' ) );
		}

		$manifest = array(
			'bundle_version'    => EIE_Util::BUNDLE_VERSION,
			'plugin_version'    => EIE_VERSION,
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'generated'         => gmdate( 'c' ),
			'source_home'       => home_url(),
			'source_uploads'    => $this->uploads['baseurl'],
			'posts'             => $posts,
			'media'             => array(),
		);

		// The server path stays out of the manifest.
		foreach ( $this->media as $entry ) {
			unset( $entry['source_path'] );
			$manifest['media'][] = $entry;
		}

		$path = wp_tempnam( 'eie-export.zip' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'eie_zip_open', __( 'Could not create the ZIP file.', 'el-import-exporter' ) );
		}

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		foreach ( $this->media as $entry ) {
			$zip->addFile( $entry['source_path'], $entry['file'] );
		}
		$zip->close();

		return $path;
	}

	/**
	 * Serialize one post and collect the media it uses.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function export_post( WP_Post $post ) {
		$elementor_data = null;
		$raw            = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded        = json_decode( $raw, true );
			$elementor_data = is_array( $decoded ) ? $decoded : $raw;
		} elseif ( is_array( $raw ) ) {
			$elementor_data = $raw;
		}

		$meta = array();
		$skip = EIE_Util::skipped_meta_keys();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			$meta[ $key ] = array_map( 'maybe_unserialize', $values );
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$object_terms = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $object_terms ) || ! $object_terms ) {
				continue;
			}
			foreach ( $object_terms as $term ) {
				$terms[ $taxonomy ][] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
		}

		$this->collect( $elementor_data );
		$this->collect( $meta );
		$this->collect( $post->post_content );
		$this->collect( $post->post_excerpt );

		$thumbnail_key = '';
		$thumbnail_id  = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail_id ) {
			$thumbnail_key = (string) $this->add_attachment( $thumbnail_id );
		}

		return array(
			'old_id'         => $post->ID,
			'post_type'      => $post->post_type,
			'post_title'     => $post->post_title,
			'post_name'      => $post->post_name,
			'post_status'    => $post->post_status,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_date'      => $post->post_date,
			'post_parent'    => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_password'  => $post->post_password,
			'thumbnail'      => $thumbnail_key,
			'elementor_data' => $elementor_data,
			'meta'           => $meta,
			'terms'          => $terms,
		);
	}

	/**
	 * Find media references in any nested value.
	 *
	 * @param mixed $data Value.
	 */
	private function collect( $data ) {
		EIE_Util::map_deep(
			$data,
			function ( $node ) {
				if ( is_array( $node ) ) {
					// Elementor media controls: { "id": 123, "url": "..." }.
					if ( isset( $node['id'], $node['url'] ) && is_numeric( $node['id'] ) && is_string( $node['url'] ) && '' !== $node['url'] ) {
						$this->add_attachment( (int) $node['id'], $node['url'] );
					}
				} elseif ( is_string( $node ) && false !== stripos( $node, 'uploads' ) ) {
					if ( preg_match_all( $this->url_pattern, $node, $matches ) ) {
						foreach ( $matches[0] as $url ) {
							$this->add_url( str_replace( '\/', '/', $url ) );
						}
					}
				}
				return $node;
			}
		);
	}

	/**
	 * Bundle an attachment by ID.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $url_hint URL the reference points at, used when the ID is stale.
	 * @return string|null Bundle key.
	 */
	private function add_attachment( $id, $url_hint = '' ) {
		if ( isset( $this->by_id[ $id ] ) ) {
			return $this->by_id[ $id ];
		}

		if ( 'attachment' !== get_post_type( $id ) ) {
			return $url_hint ? $this->add_url( $url_hint ) : null;
		}

		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : false;
		if ( ! $path || ! file_exists( $path ) ) {
			$path = get_attached_file( $id );
		}
		if ( ! $path || ! file_exists( $path ) ) {
			$this->by_id[ $id ] = null;
			return $url_hint ? $this->add_url( $url_hint ) : null;
		}

		$key  = 'm' . ( count( $this->media ) + 1 );
		$full = wp_get_attachment_url( $id );

		$sizes = array( 'full' => $full );
		if ( wp_attachment_is_image( $id ) ) {
			foreach ( get_intermediate_image_sizes() as $size ) {
				$src = wp_get_attachment_image_src( $id, $size );
				if ( $src && $src[0] !== $full ) {
					$sizes[ $size ] = $src[0];
				}
			}
			if ( function_exists( 'wp_get_original_image_url' ) ) {
				$original = wp_get_original_image_url( $id );
				if ( $original && $original !== $full ) {
					$sizes['original'] = $original;
				}
			}
		}

		$attachment         = get_post( $id );
		$this->media[ $key ] = array(
			'key'         => $key,
			'old_id'      => $id,
			'file'        => 'media/' . $key . '/' . wp_basename( $path ),
			'source_path' => $path,
			'mime'        => get_post_mime_type( $id ),
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'sizes'       => $sizes,
		);

		$this->by_id[ $id ] = $key;
		foreach ( $sizes as $url ) {
			$this->by_url[ EIE_Util::schemeless( $url ) ] = $key;
		}
		if ( $url_hint ) {
			$this->by_url[ EIE_Util::schemeless( $url_hint ) ] = $key;
		}

		return $key;
	}

	/**
	 * Bundle a file by URL: resolved to its attachment when possible, else copied as a loose file.
	 *
	 * @param string $url URL inside the uploads directory.
	 * @return string|null Bundle key.
	 */
	private function add_url( $url ) {
		$schemeless = EIE_Util::schemeless( $url );
		if ( array_key_exists( $schemeless, $this->by_url ) ) {
			return $this->by_url[ $schemeless ] ? $this->by_url[ $schemeless ] : null;
		}

		$full_url = ( is_ssl() ? 'https:' : 'http:' ) . $schemeless;
		$id       = attachment_url_to_postid( $full_url );
		if ( ! $id ) {
			// Resized or scaled variant: photo-300x200.jpg, photo-scaled.jpg.
			$base = preg_replace( '#-(?:\d+x\d+|scaled)(\.\w+)$#', '$1', $full_url );
			if ( $base !== $full_url ) {
				$id = attachment_url_to_postid( $base );
			}
		}

		if ( $id ) {
			$key = $this->add_attachment( $id );
			if ( $key ) {
				$this->by_url[ $schemeless ] = $key;
				return $key;
			}
		}

		// Not in the media library: bundle the raw file if it exists on disk.
		$base_schemeless = EIE_Util::schemeless( $this->uploads['baseurl'] );
		$relative        = ltrim( substr( $schemeless, strlen( $base_schemeless ) ), '/' );
		$path            = wp_normalize_path( $this->uploads['basedir'] . '/' . rawurldecode( $relative ) );

		if ( 0 !== strpos( $schemeless, $base_schemeless ) || false !== strpos( $relative, '..' ) || ! is_file( $path ) ) {
			$this->by_url[ $schemeless ] = false;
			return null;
		}

		$key                 = 'm' . ( count( $this->media ) + 1 );
		$type                = wp_check_filetype( $path );
		$this->media[ $key ] = array(
			'key'         => $key,
			'old_id'      => 0,
			'file'        => 'media/' . $key . '/' . wp_basename( $path ),
			'source_path' => $path,
			'mime'        => (string) $type['type'],
			'title'       => pathinfo( $path, PATHINFO_FILENAME ),
			'caption'     => '',
			'description' => '',
			'alt'         => '',
			'sizes'       => array( 'full' => $url ),
		);

		$this->by_url[ $schemeless ] = $key;

		return $key;
	}
}
