<?php

use BaghelSoft\S3Uploads\Aws\S3\Transfer;
use BaghelSoft\S3Uploads\Aws\Exception\AwsException;
use BaghelSoft\S3Uploads\Aws\Exception\S3Exception;
use BaghelSoft\S3Uploads\Aws\Middleware;
use BaghelSoft\S3Uploads\Aws\ResultInterface;

class S3Uploads_WP_CLI_Command extends WP_CLI_Command {

	/**
	 * Verifies the site is connected and uploads and downloads from the s3Uploads cloud are working.
	 *
	 * @subcommand verify
	 */
	public function verify_api_keys() {
		// Verify first that we have the necessary access keys to connect to S3.
		if ( ! $this->verify_s3_access_constants() ) {
			return;
		}

		// Get S3 Upload instance.
		$instance = S3Uploads::get_instance();

		// Create a path in the base directory, with a random file name to avoid potentially overwriting existing data.
		$upload_dir = wp_upload_dir();
		$s3_path    = $upload_dir['basedir'] . '/' . mt_rand() . '.txt';

		// Attempt to copy the local Canola test file to the generated path on s3Uploads cloud.
		WP_CLI::print_value( 'Attempting to upload file ' . $s3_path );

		$copy = copy(
			dirname( dirname( __FILE__ ) ) . '/readme.txt',
			$s3_path
		);

		// Check that the copy worked.
		if ( ! $copy ) {
			WP_CLI::error( 'Failed to copy / write to s3Uploads cloud - check your policy?' );

			return;
		}

		WP_CLI::print_value( 'File uploaded to s3Uploads cloud successfully.' );

		// Delete the file off s3Uploads cloud.
		WP_CLI::print_value( 'Attempting to delete file. ' . $s3_path );
		$delete = unlink( $s3_path );

		// Check that the delete worked.
		if ( ! $delete ) {
			WP_CLI::error( 'Failed to delete ' . $s3_path );

			return;
		}

		WP_CLI::print_value( 'File deleted from s3Uploads cloud successfully.' );

		WP_CLI::success( 'Looks like your configuration is correct!' );
	}

	/**
	 * Verify that the required constants for the s3Uploads cloud connections are set.
	 *
	 * @return bool true if all constants are set, else false.
	 */
	private function verify_s3_access_constants() {
		if ( ! S3Uploads::get_instance()->bucket ) {
			WP_CLI::error( sprintf( 'This site is not yet connected to the s3Uploads cloud. Please connect using the settings page: %s', S3Uploads_Admin::get_instance()->settings_url() ), false );

			return false;
		}

		return true;
	}

	/**
	 * List the files in the s3Uploads cloud. Optionally filter to the provided path.
	 *
	 * @synopsis [<path>]
	 */
	public function ls( $args ) {

		// Verify first that we have the necessary access keys to connect to S3.
		if ( ! $this->verify_s3_access_constants() ) {
			return;
		}

		$s3 = S3Uploads::get_instance()->s3();

		$prefix = '';

		if ( strpos( S3Uploads::get_instance()->bucket, '/' ) ) {
			$prefix = trailingslashit( str_replace( strtok( S3Uploads::get_instance()->bucket, '/' ) . '/', '', S3Uploads::get_instance()->bucket ) );
		}

		if ( isset( $args[0] ) ) {
			$prefix .= trailingslashit( ltrim( $args[0], '/' ) );
		}

		try {
 				$this->sync_debug_log('test4 =>>>>>>>>>>>>>>>>>');

			$objects = $s3->getIterator( 'ListObjects', [
				'Bucket' => strtok( S3Uploads::get_instance()->bucket, '/' ),
				'Prefix' => $prefix,
			] );
			foreach ( $objects as $object ) {
				WP_CLI::line( str_replace( $prefix, '', $object['Key'] ) . "\t" . size_format( $object['Size'] ) . "\t" . $object['LastModified']->__toString() );
			}
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

	}

	/**
	 * Copy files to / from the uploads directory. Use iu://bucket/location for s3Uploads cloud
	 *
	 * @synopsis <from> <to>
	 */
	private function cp( $args ) {

		$from = $args[0];
		$to   = $args[1];

		if ( is_dir( $from ) ) {
			$this->recurse_copy( $from, $to );
		} else {
			copy( $from, $to );
		}

		WP_CLI::success( sprintf( 'Completed copy from %s to %s', $from, $to ) );
	}

	private function recurse_copy( $src, $dst ) {
		$dir = opendir( $src );
		@mkdir( $dst );
		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( ( '.' !== $file ) && ( '..' !== $file ) ) {
				if ( is_dir( $src . '/' . $file ) ) {
					$this->recurse_copy( $src . '/' . $file, $dst . '/' . $file );
				} else {
					WP_CLI::line( sprintf( 'Copying from %s to %s', $src . '/' . $file, $dst . '/' . $file ) );
					copy( $src . '/' . $file, $dst . '/' . $file );
				}
			}
		}
		closedir( $dir );
	}

	/**
	 * Upload a directory to s3Uploads cloud
	 *
	 * @subcommand upload-directory
	 * @synopsis <from> [<to>] [--concurrency=<concurrency>] [--verbose]
	 */
	private function upload_directory( $args, $args_assoc ) {

		$from = $args[0];
		$to   = '';
		if ( isset( $args[1] ) ) {
			$to = $args[1];
		}

		$s3         = S3Uploads::get_instance()->s3();
		$args_assoc = wp_parse_args( $args_assoc, [ 'concurrency' => 20, 'verbose' => false ] );

		$transfer_args = [
			'concurrency' => $args_assoc['concurrency'],
			'debug'       => (bool) $args_assoc['verbose'],
			'before'      => function ( BaghelSoft\S3Uploads\Aws\Command $command ) {
				if ( in_array( $command->getName(), [ 'PutObject', 'CreateMultipartUpload' ], true ) ) {
					/// Expires:
					if ( defined( 'S3UPLOADS_HTTP_EXPIRES' ) ) {
						$command['Expires'] = S3UPLOADS_HTTP_EXPIRES;
					}
					// Cache-Control:
					if ( defined( 'S3UPLOADS_HTTP_CACHE_CONTROL' ) ) {
						if ( is_numeric( S3UPLOADS_HTTP_CACHE_CONTROL ) ) {
							$command['CacheControl'] = 'max-age=' . S3UPLOADS_HTTP_CACHE_CONTROL;
						} else {
							$command['CacheControl'] = S3UPLOADS_HTTP_CACHE_CONTROL;
						}
					}
				}
			},
		];
		try {
			$manager = new Transfer( $s3, $from, 's3://' . S3Uploads::get_instance()->bucket . '/' . $to, $transfer_args );
			$manager->transfer();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Sync the uploads directory to s3Uploads cloud storage.
	 *
	 * @subcommand sync
	 * @synopsis [--concurrency=<concurrency>] [--noscan] [--verbose]
	 */
	public function sync( $args, $args_assoc ) {
		global $wpdb;

		// Verify first that we have the necessary access keys to connect to S3.
		if ( ! $this->verify_s3_access_constants() ) {
			return;
		}

		$instance   = S3Uploads::get_instance();
		$s3         = $instance->s3();
		$args_assoc = wp_parse_args( $args_assoc, [ 'concurrency' => 20, 'noscan' => false, 'verbose' => false ] );

		$path = $instance->get_original_upload_dir_root();

		if ( ! $args_assoc['noscan'] ) {
			$this->build_scan();
			$stats = $instance->get_sync_stats();
			WP_CLI::line( sprintf( esc_html__( '%s files (%s) remaining to be synced.', 's3uploads' ), $stats['remaining_files'], $stats['remaining_size'] ) );
		}

		//begin transfer
		$synced       = $wpdb->get_var( "SELECT count(*) AS files FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 1" );
		$unsynced     = $wpdb->get_var( "SELECT count(*) AS files FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 0" );
		$progress_bar = null;
		if ( ! $args_assoc['verbose'] ) {
			$progress_bar = \WP_CLI\Utils\make_progress_bar( esc_html__( 'Copying to the cloud...', 's3uploads' ), $synced + $unsynced );
			for ( $i = 0; $i < $synced; $i ++ ) {
				$progress_bar->tick();
			}
		}

		$progress = get_site_option( 'iup_files_scanned' );
		if ( ! $progress['sync_started'] ) {
			$progress['sync_started'] = time();
			update_site_option( 'iup_files_scanned', $progress );
		}

		$uploaded = 0;
		$break    = false;
		while ( ! $break ) {
			$to_sync = $wpdb->get_col( "SELECT file FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 0 AND errors < 3 LIMIT 1000" );
			//build full paths
			$to_sync_full = [];
			foreach ( $to_sync as $key => $file ) {
				$to_sync_full[] = $path['basedir'] . $file;
			}

			$obj  = new ArrayObject( $to_sync_full );
			$from = $obj->getIterator();

			$transfer_args = [
				'concurrency' => $args_assoc['concurrency'],
				'base_dir'    => $path['basedir'],
				'before'      => function ( BaghelSoft\S3Uploads\Aws\Command $command ) use ( $args_assoc, $progress_bar, $wpdb, $unsynced, &$uploaded ) {
					if ( in_array( $command->getName(), [ 'PutObject', 'CreateMultipartUpload' ], true ) ) {
						/// Expires:
						if ( defined( 'S3UPLOADS_HTTP_EXPIRES' ) ) {
							$command['Expires'] = S3UPLOADS_HTTP_EXPIRES;
						}
						// Cache-Control:
						if ( defined( 'S3UPLOADS_HTTP_CACHE_CONTROL' ) ) {
							if ( is_numeric( S3UPLOADS_HTTP_CACHE_CONTROL ) ) {
								$command['CacheControl'] = 'max-age=' . S3UPLOADS_HTTP_CACHE_CONTROL;
							} else {
								$command['CacheControl'] = S3UPLOADS_HTTP_CACHE_CONTROL;
							}
						}
					}
					//add middleware to intercept result of each file upload
					if ( in_array( $command->getName(), [ 'PutObject', 'CompleteMultipartUpload' ], true ) ) {
						$command->getHandlerList()->appendSign(
							Middleware::mapResult( function ( ResultInterface $result ) use ( $args_assoc, $progress_bar, $command, $wpdb, $unsynced, &$uploaded ) {
								$uploaded ++;
								$file = urldecode( strstr( substr( $result['@metadata']["effectiveUri"], ( strrpos( $result['@metadata']["effectiveUri"], S3Uploads::get_instance()->bucket ) + strlen( S3Uploads::get_instance()->bucket ) ) ), '?', true ) ?: substr( $result['@metadata']["effectiveUri"], ( strrpos( $result['@metadata']["effectiveUri"], S3Uploads::get_instance()->bucket ) + strlen( S3Uploads::get_instance()->bucket ) ) ) );
 							      
  						        //WP_CLI::success( sprintf('File uploaded %s', $file));
								$wpdb->update( "{$wpdb->base_prefix}s3uploads_files", [ 'synced' => 1, 'errors' => 0 ], [ 'file' => $file ] );

								if ( $args_assoc['verbose'] ) {
									WP_CLI::success( sprintf( esc_html__( '%s - Synced %s of %s files.', 's3uploads' ), $file, number_format_i18n( $uploaded ), number_format_i18n( $unsynced ) ) );
								} else {
									$progress_bar->tick();
								}

								return $result;
							} )
						);
					}
				},
			];
			try {
				$manager = new Transfer( $s3, $from, 's3://' . S3Uploads::get_instance()->bucket . '/', $transfer_args );
				$manager->transfer();
			} catch ( Exception $e ) {
				if ( method_exists( $e, 'getRequest' ) ) {
					$file        = str_replace( trailingslashit( S3Uploads::get_instance()->bucket ), '', $e->getRequest()->getRequestTarget() );
					$error_count = $wpdb->get_var( $wpdb->prepare( "SELECT errors FROM `{$wpdb->base_prefix}s3uploads_files` WHERE file = %s", $file ) );
					$error_count ++;
					if ( $error_count >= 3 ) {
						WP_CLI::warning( sprintf( esc_html__( 'Error uploading %s. Retries exceeded.', 's3uploads' ), $file ) );
					} else {
						WP_CLI::warning( sprintf( esc_html__( '%s error uploading %s. Queued for retry.', 's3uploads' ), $e->getAwsErrorCode(), $file ) );
					}
					$wpdb->update( "{$wpdb->base_prefix}s3uploads_files", [ 'errors' => $error_count ], [ 'file' => $file ] );

				} else {
 			      WP_CLI::warning( sprintf('Error uploading %s', $file));
 			        
					//WP_CLI::warning( sprintf( esc_html__( '%s error uploading %s. Queued for retry.', 's3uploads' ), $e->getAwsErrorCode(), $file ) );
				}
			}

			$is_done = ! (bool) $wpdb->get_var( "SELECT count(*) FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 0 AND errors < 3" );
			if ( $is_done ) {
				$break                     = true;
				$progress                  = get_site_option( 'iup_files_scanned' );
				$progress['sync_finished'] = time();
				update_site_option( 'iup_files_scanned', $progress );
				if ( ! $args_assoc['verbose'] ) {
					$progress_bar->finish();
				}

				$error_count = $wpdb->get_var( "SELECT count(*) FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 0 AND errors >= 3" );
				if ( $error_count ) {
					WP_CLI::warning( sprintf( esc_html__( 'Unable to upload %s files.', 's3uploads' ), number_format_i18n( $error_count ) ) );
				}
				WP_CLI::success( esc_html__( 'Sync complete!', 's3uploads' ) );
			}

		}
	}

	private function build_scan() {
		global $wpdb;
		$instance = S3Uploads::get_instance();
		$s3       = $instance->s3();
		$path     = $instance->get_original_upload_dir_root();

		WP_CLI::line( esc_html__( 'Scanning local filesystem...', 's3uploads' ) );
		$filelist = new S3Uploads_Filelist( $path['basedir'], 9999, [] );
		$filelist->start();

		$stats = $instance->get_sync_stats();
		WP_CLI::line( sprintf( esc_html__( '%s files (%s) found in uploads.', 's3uploads' ), $stats['local_files'], $stats['local_size'] ) );

		WP_CLI::line( esc_html__( 'Comparing to the cloud...', 's3uploads' ) );
		$prefix = '';

		if ( strpos( S3Uploads::get_instance()->bucket, '/' ) ) {
			$prefix = trailingslashit( str_replace( strtok( S3Uploads::get_instance()->bucket, '/' ) . '/', '', S3Uploads::get_instance()->bucket ) );
		}

		$args = [
			'Bucket' => strtok( S3Uploads::get_instance()->bucket, '/' ),
			'Prefix' => $prefix,
		];

		//set flag
		$progress                    = get_site_option( 'iup_files_scanned' );
		$progress['compare_started'] = time();
		update_site_option( 'iup_files_scanned', $progress );

		try {
			$results = $s3->getPaginator( 'ListObjectsV2', $args );
 					$this->sync_debug_log('test5 =>>>>>>>>>>>>>>>>>');

			foreach ( $results as $result ) {
				$cloud_only_files = [];
				if ( $result['Contents'] ) {
					foreach ( $result['Contents'] as $object ) {
						$local_key = str_replace( untrailingslashit( $prefix ), '', $object['Key'] );
						$file      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}s3uploads_files WHERE file = %s", $local_key ) );
						if ( $file && ! $file->synced && $file->size == $object['Size'] ) {
							$wpdb->update( "{$wpdb->base_prefix}s3uploads_files", [ 'synced' => 1 ], [ 'file' => $local_key ] );
						}
						if ( ! $file ) {
							$cloud_only_files[] = [
								'name'  => $local_key,
								'size'  => $object['Size'],
								'mtime' => strtotime( $object['LastModified']->__toString() ),
								'type'  => $instance->get_file_type( $local_key ),
							];
						}
					}
				}
				//flush new files to db
				if ( count( $cloud_only_files ) ) {
					$values = [];
					foreach ( $cloud_only_files as $file ) {
						$values[] = $wpdb->prepare( "(%s,%d,%d,%s,1,1)", $file['name'], $file['size'], $file['mtime'], $file['type'] );
					}

					$query = "INSERT INTO {$wpdb->base_prefix}s3uploads_files (file, size, modified, type, synced, deleted) VALUES ";
					$query .= implode( ",\n", $values );
					$query .= " ON DUPLICATE KEY UPDATE size = VALUES(size), modified = VALUES(modified), type = VALUES(type), synced = 1, deleted = 1, errors = 0";
					$wpdb->query( $query );
				}
			}

			//set flag
			$progress                     = get_site_option( 'iup_files_scanned' );
			$progress['compare_finished'] = time();
			update_site_option( 'iup_files_scanned', $progress );

		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Delete all files from the uploads directory that have been synced to s3Uploads cloud storage.
	 *
	 * @subcommand delete
	 * @synopsis [--noscan] [--verbose]
	 */
	public function delete( $args, $args_assoc ) {
		global $wpdb;

		// Verify first that we have the necessary access keys to connect to S3.
		if ( ! $this->verify_s3_access_constants() ) {
			return;
		}

		$instance   = S3Uploads::get_instance();
		$args_assoc = wp_parse_args( $args_assoc, [ 'noscan' => false, 'verbose' => false ] );

		$path = $instance->get_original_upload_dir_root();

		if ( ! $args_assoc['noscan'] ) {
			$this->build_scan();
			$stats = $instance->get_sync_stats();
			WP_CLI::line( sprintf( esc_html__( '%s files (%s) remaining to be synced.', 's3uploads' ), $stats['remaining_files'], $stats['remaining_size'] ) );
		}

		//begin deleting
		$deleted      = 0;
		$to_delete    = $wpdb->get_col( "SELECT file FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 1 AND deleted = 0" );
		$progress_bar = null;
		if ( ! $args_assoc['verbose'] ) {
			$progress_bar = \WP_CLI\Utils\make_progress_bar( esc_html__( 'Deleting local copies of synced files...', 's3uploads' ), count( $to_delete ) );
		}

		foreach ( $to_delete as $file ) {
			if ( @unlink( $path['basedir'] . $file ) ) {
				$wpdb->update( "{$wpdb->base_prefix}s3uploads_files", [ 'deleted' => 1 ], [ 'file' => $file ] );
				$deleted ++;
				if ( $args_assoc['verbose'] ) {
					WP_CLI::success( sprintf( esc_html__( '%s - Deleted %s of %s files.', 's3uploads' ), $file, number_format_i18n( $deleted ), number_format_i18n( count( $to_delete ) ) ) );
				} else {
					$progress_bar->tick();
				}
			} else {
				WP_CLI::warning( sprintf( esc_html__( 'Could not delete %s.', 's3uploads' ), $file ) );
			}
		}

		if ( ! $args_assoc['verbose'] ) {
			$progress_bar->finish();
		}
		WP_CLI::success( esc_html__( 'Delete complete!', 's3uploads' ) );
	}

	/**
	 * Download all files only in s3Uploads cloud storage to the local uploads directory.
	 *
	 * @subcommand download
	 * @synopsis [--concurrency=<concurrency>] [--noscan] [--verbose]
	 */
	public function download( $args, $args_assoc ) {
		global $wpdb;

		// Verify first that we have the necessary access keys to connect to S3.
		if ( ! $this->verify_s3_access_constants() ) {
			return;
		}

		$instance   = S3Uploads::get_instance();
		$s3         = $instance->s3();
		$args_assoc = wp_parse_args( $args_assoc, [ 'concurrency' => 20, 'noscan' => false, 'verbose' => false ] );

		$path = $instance->get_original_upload_dir_root();

		if ( ! $args_assoc['noscan'] ) {
			$this->build_scan();
			$unsynced = $wpdb->get_row( "SELECT count(*) AS files, SUM(`size`) as size FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 1 AND deleted = 1" );
			WP_CLI::line( sprintf( esc_html__( '%s files (%s) remaining to be downloaded.', 's3uploads' ), $unsynced->files, size_format( $unsynced->size, 2 ) ) );
		}

		//begin transfer
		if ( empty( $unsynced ) ) {
			$unsynced = $wpdb->get_row( "SELECT count(*) AS files, SUM(`size`) as size FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 1 AND deleted = 1" );
		}
		$progress_bar = null;
		if ( ! $args_assoc['verbose'] ) {
			$progress_bar = \WP_CLI\Utils\make_progress_bar( esc_html__( 'Downloading from the cloud...', 's3uploads' ), $unsynced->files );
		}

		$progress = get_site_option( 'iup_files_scanned' );
		if ( empty( $progress['download_started'] ) ) {
			$progress['download_started'] = time();
			update_site_option( 'iup_files_scanned', $progress );
		}

		$downloaded = 0;
		$break      = false;
		while ( ! $break ) {
			$to_sync = $wpdb->get_col( "SELECT file FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 1 AND deleted = 1 AND errors < 3 LIMIT 1000" );
			//build full paths
			$to_sync_full = [];
			foreach ( $to_sync as $key => $file ) {
				$to_sync_full[] = 's3://' . untrailingslashit( S3Uploads::get_instance()->bucket ) . $file;
			}

			$obj  = new ArrayObject( $to_sync_full );
			$from = $obj->getIterator();

			$transfer_args = [
				'concurrency' => $args_assoc['concurrency'],
				'base_dir'    => 's3://' . S3Uploads::get_instance()->bucket,
				'before'      => function ( BaghelSoft\S3Uploads\Aws\Command $command ) use ( $args_assoc, $progress_bar, $wpdb, $unsynced, &$downloaded ) {
					//add middleware to intercept result of each file upload
					if ( in_array( $command->getName(), [ 'GetObject' ], true ) ) {
						$command->getHandlerList()->appendSign(
							Middleware::mapResult( function ( ResultInterface $result ) use ( $args_assoc, $progress_bar, $command, $wpdb, $unsynced, &$downloaded ) {
								$downloaded ++;
								$file = '/' . urldecode( strstr( substr( $result['@metadata']["effectiveUri"], ( strrpos( $result['@metadata']["effectiveUri"], S3Uploads::get_instance()->bucket ) + strlen( S3Uploads::get_instance()->bucket ) ) ), '?', true ) ?: substr( $result['@metadata']["effectiveUri"], ( strrpos( $result['@metadata']["effectiveUri"], S3Uploads::get_instance()->bucket ) + strlen( S3Uploads::get_instance()->bucket ) ) ) );
								$wpdb->update( "{$wpdb->base_prefix}s3uploads_files", [ 'deleted' => 0, 'errors' => 0 ], [ 'file' => $file ] );

								if ( $args_assoc['verbose'] ) {
									WP_CLI::success( sprintf( esc_html__( '%s - Downloaded %s of %s files.', 's3uploads' ), $file, number_format_i18n( $downloaded ), number_format_i18n( $unsynced->files ) ) );
								} else {
									$progress_bar->tick();
								}

								return $result;
							} )
						);
					}
				},
			];
			try {
				$manager = new Transfer( $s3, $from, $path['basedir'], $transfer_args );
				$manager->transfer();
			} catch ( Exception $e ) {
				if ( method_exists( $e, 'getRequest' ) ) {
					$file        = str_replace( untrailingslashit( $path['basedir'] ), '', str_replace( trailingslashit( S3Uploads::get_instance()->bucket ), '', $e->getRequest()->getRequestTarget() ) );
					$error_count = $wpdb->get_var( $wpdb->prepare( "SELECT errors FROM `{$wpdb->base_prefix}s3uploads_files` WHERE file = %s", $file ) );
					$error_count ++;
					if ( $error_count >= 3 ) {
						WP_CLI::warning( sprintf( esc_html__( 'Error downloading %s. Retries exceeded.', 's3uploads' ), $file ) );
					} else {
						WP_CLI::warning( sprintf( esc_html__( 'Error downloading %s. Queued for retry.', 's3uploads' ), $file ) );
					}
					$wpdb->update( "{$wpdb->base_prefix}s3uploads_files", [ 'errors' => $error_count ], [ 'file' => $file ] );
				} else {
					WP_CLI::warning( sprintf( esc_html__( '%s error downloading %s. Queued for retry.', 's3uploads' ), $e->getAwsErrorCode(), $file ) );
				}
			}

			$is_done = ! (bool) $wpdb->get_var( "SELECT count(*) FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 1 AND deleted = 1 AND errors < 3" );
			if ( $is_done ) {
				$break                         = true;
				$progress                      = get_site_option( 'iup_files_scanned' );
				$progress['download_finished'] = time();
				update_site_option( 'iup_files_scanned', $progress );
				if ( ! $args_assoc['verbose'] ) {
					$progress_bar->finish();
				}
				$error_count = $wpdb->get_var( "SELECT count(*) FROM `{$wpdb->base_prefix}s3uploads_files` WHERE synced = 1 AND deleted = 1 AND errors >= 3" );
				if ( $error_count ) {
					WP_CLI::warning( sprintf( esc_html__( 'Unable to download %s files.', 's3uploads' ), number_format_i18n( $error_count ) ) );
				}
				WP_CLI::success( esc_html__( 'Download complete!', 's3uploads' ) );
			}

		}
	}

	/**
	 * Delete files from s3Uploads cloud
	 *
	 * @synopsis <path> [--regex=<regex>]
	 */
	public function rm( $args, $args_assoc ) {

		// Verify first that we have the necessary access keys to connect to S3.
		if ( ! $this->verify_s3_access_constants() ) {
			return;
		}

		$s3 = S3Uploads::get_instance()->s3();

		$prefix = '';
		$regex  = isset( $args_assoc['regex'] ) ? $args_assoc['regex'] : '';

		if ( strpos( S3Uploads::get_instance()->bucket, '/' ) ) {
			$prefix = trailingslashit( str_replace( strtok( S3Uploads::get_instance()->bucket, '/' ) . '/', '', S3Uploads::get_instance()->bucket ) );
		}

		if ( isset( $args[0] ) ) {
			$prefix .= ltrim( $args[0], '/' );

			if ( strpos( $args[0], '.' ) === false ) {
				$prefix = trailingslashit( $prefix );
			}
		}

		try {
			$objects = $s3->deleteMatchingObjects(
				strtok( S3Uploads::get_instance()->bucket, '/' ),
				$prefix,
				$regex,
				[
					'before_delete',
					function () {
						WP_CLI::line( sprintf( 'Deleting file' ) );
					},
				]
			);

		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( sprintf( 'Successfully deleted %s', $prefix ) );
	}

	/**
	 * Enable the auto-rewriting of media links to s3Uploads cloud
	 */
	public function enable( $args, $assoc_args ) {
		// Verify first that we have the necessary access keys to connect to S3.
		if ( ! $this->verify_s3_access_constants() ) {
			return;
		}

		S3Uploads::get_instance()->toggle_cloud( true );

		WP_CLI::success( 'Media URL rewriting enabled.' );
	}

	/**
	 * Disable the auto-rewriting of media links to s3Uploads cloud
	 */
	public function disable( $args, $assoc_args ) {
		S3Uploads::get_instance()->toggle_cloud( false );

		WP_CLI::success( 'Media URL rewriting disabled.' );
	}
}

WP_CLI::add_command( 's3uploads', 'S3Uploads_WP_CLI_Command' );
