<?php
/**
 * Plugins sync module.
 *
 * @package automattic/jetpack-sync
 */

namespace Automattic\Jetpack\Sync\Modules;

use Automattic\Jetpack\Constants as Jetpack_Constants;
use WP_Error;

/**
 * Class to handle sync for plugins.
 */
class Plugins extends Module {
	/**
	 * Action handler callable.
	 *
	 * @access private
	 *
	 * @var callable
	 */
	private $action_handler;

	/**
	 * Information about plugins we store temporarily.
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $plugin_info = array();

	/**
	 * List of all plugins in the installation.
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $plugins = array();

	/**
	 * List of all updated plugins.
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $plugins_updated = array();

	/**
	 * State
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $state = array();

	/**
	 * Sync module name.
	 *
	 * @access public
	 *
	 * @return string
	 */
	public function name() {
		return 'plugins';
	}

	/**
	 * Initialize plugins action listeners.
	 *
	 * @access public
	 *
	 * @param callable $callable Action handler callable.
	 */
	public function init_listeners( $callable ) {
		$this->action_handler = $callable;

		add_action( 'deleted_plugin', array( $this, 'deleted_plugin' ), 10, 2 );
		add_action( 'activated_plugin', $callable, 10, 2 );
		add_action( 'deactivated_plugin', $callable, 10, 2 );
		add_action( 'delete_plugin', array( $this, 'delete_plugin' ) );
		add_filter( 'upgrader_pre_install', array( $this, 'populate_plugins' ), 10, 1 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_completion' ), 10, 2 );
		add_action( 'jetpack_plugin_installed', $callable, 10, 1 );
		add_action( 'jetpack_plugin_update_failed', $callable, 10, 4 );
		add_action( 'jetpack_plugins_updated', $callable, 10, 2 );
		add_action( 'jetpack_edited_plugin', $callable, 10, 2 );
		add_action( 'wp_ajax_edit-theme-plugin-file', array( $this, 'plugin_edit_ajax' ), 0 );

		// Note that we don't simply 'expand_plugin_data' on the 'delete_plugin' action here because the plugin file is deleted when that action finishes.
		add_filter( 'jetpack_sync_before_enqueue_activated_plugin', array( $this, 'expand_plugin_data' ) );
		add_filter( 'jetpack_sync_before_enqueue_deactivated_plugin', array( $this, 'expand_plugin_data' ) );
	}

	/**
	 * Fetch and populate all current plugins before upgrader installation.
	 *
	 * @access public
	 *
	 * @param bool|WP_Error $response Install response, true if successful, WP_Error if not.
	 */
	public function populate_plugins( $response ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$this->plugins = get_plugins();
		return $response;
	}

	/**
	 * Handler for the upgrader success finishes.
	 *
	 * @access public
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $details  Array of bulk item update data.
	 */
	public function on_upgrader_completion( $upgrader, $details ) {
		if ( ! isset( $details['type'] ) ) {
			return;
		}
		if ( 'plugin' !== $details['type'] ) {
			return;
		}

		if ( ! isset( $details['action'] ) ) {
			return;
		}

		$plugins = ( isset( $details['plugins'] ) ? $details['plugins'] : null );
		if ( empty( $plugins ) ) {
			$plugins = ( isset( $details['plugin'] ) ? array( $details['plugin'] ) : null );
		}

		// For plugin installer.
		if ( empty( $plugins ) && method_exists( $upgrader, 'plugin_info' ) ) {
			// @phan-suppress-next-line PhanUndeclaredMethod -- Checked above. See also https://github.com/phan/phan/issues/1204.
			$plugins = array( $upgrader->plugin_info() );
		}

		if ( empty( $plugins ) ) {
			return; // We shouldn't be here.
		}

		switch ( $details['action'] ) {
			case 'update':
				$this->state = array(
					'is_autoupdate' => Jetpack_Constants::is_true( 'JETPACK_PLUGIN_AUTOUPDATE' ),
				);
				$errors      = $this->get_errors( $upgrader->skin );
				if ( $errors ) {
					foreach ( $plugins as $slug ) {
						/**
						 * Sync that a plugin update failed
						 *
						 * @since 1.6.3
						 * @since-jetpack 5.8.0
						 *
						 * @module sync
						 *
						 * @param string $plugin , Plugin slug
						 * @param        string  Error code
						 * @param        string  Error message
						 */
						do_action( 'jetpack_plugin_update_failed', $this->get_plugin_info( $slug ), $errors['code'], $errors['message'], $this->state );
					}

					return;
				}

				$this->plugins_updated = array_map( array( $this, 'get_plugin_info' ), $plugins );
				add_action( 'shutdown', array( $this, 'sync_plugins_updated' ), 9 );

				break;
			case 'install':
				/**
				 * Signals to the sync listener that a plugin was installed and a sync action
				 * reflecting the installation and the plugin info should be sent
				 *
				 * @since 1.6.3
				 * @since-jetpack 5.8.0
				 *
				 * @module sync
				 *
				 * @param array () $plugin, Plugin Data
				 */
				do_action( 'jetpack_plugin_installed', array_map( array( $this, 'get_plugin_info' ), $plugins ) );
		}
	}

	/**
	 * Retrieve the plugin information by a plugin slug.
	 *
	 * @access private
	 *
	 * @param string $slug Plugin slug.
	 * @return array Plugin information.
	 */
	private function get_plugin_info( $slug ) {
		$plugins = get_plugins(); // Get the most up to date info.
		if ( isset( $plugins[ $slug ] ) ) {
			return array_merge( array( 'slug' => $slug ), $plugins[ $slug ] );
		}
		// Try grabbing the info from before the update.
		return isset( $this->plugins[ $slug ] ) ? array_merge( array( 'slug' => $slug ), $this->plugins[ $slug ] ) : array( 'slug' => $slug );
	}

	/**
	 * Retrieve upgrade errors.
	 *
	 * @access private
	 *
	 * @param \Automatic_Upgrader_Skin|\WP_Upgrader_Skin $skin The upgrader skin being used.
	 * @return array|boolean Error on error, false otherwise.
	 */
	private function get_errors( $skin ) {
		// @phan-suppress-next-line PhanUndeclaredMethod -- Checked before being called. See also https://github.com/phan/phan/issues/1204.
		$errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
		if ( is_wp_error( $errors ) ) {
			$error_code = $errors->get_error_code();
			if ( ! empty( $error_code ) ) {
				return array(
					'code'    => $error_code,
					'message' => $errors->get_error_message(),
				);
			}
		}

		if ( isset( $skin->result ) ) {
			$errors = $skin->result;
			if ( is_wp_error( $errors ) ) {
				return array(
					'code'    => $errors->get_error_code(),
					'message' => $errors->get_error_message(),
				);
			}

			if ( empty( $skin->result ) ) {
				return array(
					'code'    => 'unknown',
					'message' => __( 'Unknown Plugin Update Failure', 'jetpack-sync' ),
				);
			}
		}
		return false;
	}

	/**
	 * Handle plugin ajax edit in the administration.
	 *
	 * @access public
	 *
	 * @todo Update this method to use WP_Filesystem instead of fopen/fclose.
	 */
	public function plugin_edit_ajax() {
		// This validation is based on wp_edit_theme_plugin_file().
		if ( empty( $_POST['file'] ) ) {
			return;
		}

		$file = wp_unslash( $_POST['file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated manually just after.
		if ( 0 !== validate_file( $file ) ) {
			return;
		}

		if ( ! isset( $_POST['newcontent'] ) ) {
			return;
		}

		if ( ! isset( $_POST['nonce'] ) ) {
			return;
		}

		if ( empty( $_POST['plugin'] ) ) {
			return;
		}

		$plugin = wp_unslash( $_POST['plugin'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated manually just after.
		if ( ! current_user_can( 'edit_plugins' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['nonce'], 'edit-plugin_' . $file ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- WP core doesn't pre-sanitize nonces either.
			return;
		}
		$plugins = get_plugins();
		if ( ! array_key_exists( $plugin, $plugins ) ) {
			return;
		}

		if ( 0 !== validate_file( $file, get_plugin_files( $plugin ) ) ) {
			return;
		}

		$real_file = WP_PLUGIN_DIR . '/' . $file;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_writable( $real_file ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$file_pointer = fopen( $real_file, 'w+' );
		if ( false === $file_pointer ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $file_pointer );
		/**
		 * This action is documented already in this file
		 */
		do_action( 'jetpack_edited_plugin', $plugin, $plugins[ $plugin ] );
	}

	/**
	 * Handle plugin deletion.
	 *
	 * @access public
	 *
	 * @param string $plugin_path Path to the plugin main file.
	 */
	public function delete_plugin( $plugin_path ) {
		$full_plugin_path = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin_path;

		// Checking for file existence because some sync plugin module tests simulate plugin installation and deletion without putting file on disk.
		if ( file_exists( $full_plugin_path ) ) {
			$all_plugin_data = get_plugin_data( $full_plugin_path );
			$data            = array(
				'name'    => $all_plugin_data['Name'],
				'version' => $all_plugin_data['Version'],
			);
		} else {
			$data = array(
				'name'    => $plugin_path,
				'version' => 'unknown',
			);
		}

		$this->plugin_info[ $plugin_path ] = $data;
	}

	/**
	 * Invoked after plugin deletion.
	 *
	 * @access public
	 *
	 * @param string  $plugin_path Path to the plugin main file.
	 * @param boolean $is_deleted  Whether the plugin was deleted successfully.
	 */
	public function deleted_plugin( $plugin_path, $is_deleted ) {
		call_user_func( $this->action_handler, $plugin_path, $is_deleted, $this->plugin_info[ $plugin_path ] );
		unset( $this->plugin_info[ $plugin_path ] );
	}

	/**
	 * Expand the plugins within a hook before they are serialized and sent to the server.
	 *
	 * @access public
	 *
	 * @param array $args The hook parameters.
	 * @return array $args The expanded hook parameters.
	 */
	public function expand_plugin_data( $args ) {
		$plugin_path = $args[0];
		$plugin_data = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		if ( isset( $all_plugins[ $plugin_path ] ) ) {
			$all_plugin_data        = $all_plugins[ $plugin_path ];
			$plugin_data['name']    = $all_plugin_data['Name'];
			$plugin_data['version'] = $all_plugin_data['Version'];
		}

		return array(
			$args[0],
			$args[1],
			$plugin_data,
		);
	}

	/**
	 * Helper method for firing the 'jetpack_plugins_updated' action on shutdown.
	 *
	 * @access public
	 */
	public function sync_plugins_updated() {
		/**
		 * Sync that a plugin update
		 *
		 * @since 1.6.3
		 * @since-jetpack 5.8.0
		 *
		 * @module sync
		 *
		 * @param array () $plugin, Plugin Data
		 */
		do_action( 'jetpack_plugins_updated', $this->plugins_updated, $this->state );
	}
}
