<?php
/**
 * Plugin Name: Canvas Course Sync
 * Plugin URI: https://github.com/eyethstudios/canvas-course-sync
 * Description: Sync course information from Canvas LMS to WordPress
 * Version: 4.0.1
 * Author: Eyeth Studios
 * Author URI: http://eyethstudios.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: canvas-course-sync
 * Domain Path: /languages
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'CCS_VERSION', '4.0.1' );
define( 'CCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CCS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CCS_PLUGIN_FILE', __FILE__ );
define( 'CCS_GITHUB_REPO', 'eyethstudios/canvas-course-sync' );
define( 'CCS_DEFAULT_CATALOG_URL', 'https://learn.nationaldeafcenter.org/' );

/**
 * Main plugin class
 */
class Canvas_Course_Sync {
	/**
	 * Single instance
	 */
	private static $instance = null;

	/**
	 * Plugin components
	 */
	public $logger;
	public $catalogApi;
	public $importer;
	public $admin_menu;
	public $scheduler;
	public $github_updater;

	/**
	 * Initialization flags
	 */
	private $components_loaded = false;
	private $hooks_registered = false;

	/**
	 * Get instance (singleton pattern)
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - Initialize plugin carefully
	 */
	private function __construct() {
		// Prevent multiple initialization
		if ( $this->hooks_registered ) {
			return;
		}

		// Register shutdown function to handle fatal errors
		register_shutdown_function( array( $this, 'handle_fatal_error' ) );

		// Hook into WordPress with error handling
		try {
			add_action( 'init', array( $this, 'init' ), 10 );
			add_action( 'plugins_loaded', array( $this, 'load_plugin' ), 10 );
			
			if ( is_admin() ) {
				add_action( 'admin_init', array( $this, 'admin_init' ), 10 );
				add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 10 );
				add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ), 10 );
			}

			add_action( 'wp_loaded', array( $this, 'wp_loaded' ), 10 );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );

			$this->hooks_registered = true;

		} catch ( Exception $e ) {
			$this->log_error( 'Failed to register hooks: ' . $e->getMessage() );
		}
	}

	/**
	 * Handle fatal errors
	 */
	public function handle_fatal_error() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ) ) ) {
			if ( strpos( $error['file'], 'canvas-course-sync' ) !== false ) {
				// Log the fatal error
				error_log( 'CCS Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'] );
			}
		}
	}

	/**
	 * Safe error logging
	 */
	private function log_error( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'CCS Error: ' . $message );
		}
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		try {
			// Load text domain
			load_plugin_textdomain( 'canvas-course-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			// Register post type if it doesn't exist
			if ( ! post_type_exists( 'courses' ) ) {
				$this->register_courses_post_type();
			}

		} catch ( Exception $e ) {
			$this->log_error( 'Init failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Register courses post type safely
	 */
	private function register_courses_post_type() {
		try {
			register_post_type(
				'courses',
				array(
					'labels' => array(
						'name' => 'Courses',
						'singular_name' => 'Course',
						'menu_name' => 'Courses',
						'add_new' => 'Add Course',
						'edit_item' => 'Edit Course',
						'view_item' => 'View Course',
						'all_items' => 'All Courses',
					),
					'public' => true,
					'has_archive' => true,
					'rewrite' => array( 'slug' => 'courses' ),
					'show_in_rest' => true,
					'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
					'menu_icon' => 'dashicons-book',
				)
			);
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to register post type: ' . $e->getMessage() );
		}
	}

	/**
	 * Load plugin components safely
	 */
	public function load_plugin() {
		if ( $this->components_loaded ) {
			return;
		}

		try {
			$this->load_required_files();
			add_action( 'wp_loaded', array( $this, 'init_components' ), 20 );
			
			if ( is_admin() ) {
				$this->register_ajax_handlers();
				add_action( 'plugins_loaded', array( $this, 'init_github_updater' ), 15 );
			}

			$this->components_loaded = true;

		} catch ( Exception $e ) {
			$this->log_error( 'Load plugin failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Load required files with error handling
	 */
	private function load_required_files() {
		$required_files = array(
			'includes/functions.php',
			'includes/logger.php',
			'includes/canvas-api.php',
			'includes/catalog-api.php',
			'includes/importer.php',
			'includes/class-ccs-scheduler.php',
			'includes/class-ccs-database-manager.php',
			'includes/class-ccs-slug-generator.php',
			'includes/handlers/class-ccs-media-handler.php',
		);

		foreach ( $required_files as $file ) {
			$file_path = CCS_PLUGIN_DIR . $file;
			if ( file_exists( $file_path ) ) {
				try {
					require_once $file_path;
				} catch ( Exception $e ) {
					$this->log_error( 'Failed to load file ' . $file . ': ' . $e->getMessage() );
				}
			}
		}

		// Load AJAX handlers
		$ajax_file = CCS_PLUGIN_DIR . 'includes/ajax-handlers.php';
		if ( file_exists( $ajax_file ) ) {
			try {
				require_once $ajax_file;
			} catch ( Exception $e ) {
				$this->log_error( 'Failed to load AJAX handlers: ' . $e->getMessage() );
			}
		}

		// Load admin files if in admin
		if ( is_admin() ) {
			$admin_files = array(
				'includes/admin/class-ccs-admin-menu.php',
				'includes/admin/class-ccs-admin-page.php',
				'includes/admin/class-ccs-logs-display.php',
				'includes/admin/class-ccs-email-settings.php',
				'includes/class-ccs-github-updater.php',
			);

			foreach ( $admin_files as $file ) {
				$file_path = CCS_PLUGIN_DIR . $file;
				if ( file_exists( $file_path ) ) {
					try {
						require_once $file_path;
					} catch ( Exception $e ) {
						$this->log_error( 'Failed to load admin file ' . $file . ': ' . $e->getMessage() );
					}
				}
			}
		}
	}

	/**
	 * Initialize components with proper error handling and dependency checking
	 */
	public function init_components() {
		try {
			// Initialize logger first (no dependencies)
			if ( class_exists( 'CCS_Logger' ) ) {
				$this->logger = new CCS_Logger();
			}

			// Initialize API with logger dependency
			if ( class_exists( 'CCS_Catalog_API' ) && $this->logger ) {
				$this->catalogApi = new CCS_Catalog_API( $this->logger );
			}

			// Initialize importer with all dependencies
			if ( $this->should_init_importer() ) {
				$this->init_importer_safely();
			}

			// Initialize scheduler
			if ( class_exists( 'CCS_Scheduler' ) && $this->logger && $this->catalogApi ) {
				$this->scheduler = new CCS_Scheduler( $this->logger, $this->catalogApi, $this->importer );
			}

			// Initialize admin components
			if ( is_admin() && class_exists( 'CCS_Admin_Menu' ) ) {
				$this->admin_menu = new CCS_Admin_Menu();
			}

		} catch ( Exception $e ) {
			$this->log_error( 'Component initialization failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Check if we should initialize the importer
	 */
	private function should_init_importer() {
		return class_exists( 'CCS_Importer' ) 
			&& $this->logger 
			&& $this->catalogApi 
			&& class_exists( 'CCS_Media_Handler' )
			&& class_exists( 'CCS_Database_Manager' )
			&& class_exists( 'CCS_Slug_Generator' );
	}

	/**
	 * Initialize importer safely with all dependencies
	 */
	private function init_importer_safely() {
		try {
			$media_handler = new CCS_Media_Handler();
			$db_manager = new CCS_Database_Manager( $this->logger );
			$slug_generator = new CCS_Slug_Generator( $this->logger );

			$this->importer = new CCS_Importer(
				$this->logger,
				$this->catalogApi,
				$media_handler,
				$db_manager,
				$slug_generator
			);
		} catch ( Exception $e ) {
			$this->log_error( 'Importer initialization failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Initialize GitHub updater safely
	 */
	public function init_github_updater() {
		if ( class_exists( 'CCS_GitHub_Updater' ) ) {
			try {
				$this->github_updater = new CCS_GitHub_Updater( CCS_PLUGIN_FILE, CCS_GITHUB_REPO, CCS_VERSION );
			} catch ( Exception $e ) {
				$this->log_error( 'GitHub updater initialization failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * WordPress loaded hook
	 */
	public function wp_loaded() {
		try {
			do_action( 'ccs_loaded' );
		} catch ( Exception $e ) {
			$this->log_error( 'wp_loaded hook failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Admin initialization with error handling
	 */
	public function admin_init() {
		try {
			// Register settings safely
			$this->register_plugin_settings();
		} catch ( Exception $e ) {
			$this->log_error( 'Admin init failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Register plugin settings safely
	 */
	private function register_plugin_settings() {
		$settings = array(
			'ccs_catalog_token' => array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
			),
			'ccs_notification_email' => array(
				'type' => 'string', 
				'sanitize_callback' => 'sanitize_email',
				'default' => get_option( 'admin_email' ),
			),
			'ccs_auto_sync_enabled' => array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false,
			),
			'ccs_catalog_url' => array(
				'type' => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default' => CCS_DEFAULT_CATALOG_URL,
			),
		);

		foreach ( $settings as $setting => $args ) {
			register_setting( 'ccs_settings', $setting, $args );
		}
	}

	/**
	 * Add plugin action links
	 */
	public function add_plugin_action_links( $links ) {
		try {
			$settings_link = '<a href="' . admin_url( 'admin.php?page=canvas-course-sync' ) . '">' . __( 'Settings', 'canvas-course-sync' ) . '</a>';
			array_unshift( $links, $settings_link );
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to add action links: ' . $e->getMessage() );
		}
		return $links;
	}

	/**
	 * Add admin menu with error handling
	 */
	public function add_admin_menu() {
		try {
			if ( $this->admin_menu && method_exists( $this->admin_menu, 'add_menu' ) ) {
				$this->admin_menu->add_menu();
			} else {
				// Fallback menu creation
				add_menu_page(
					__( 'Canvas Course Sync', 'canvas-course-sync' ),
					__( 'Canvas Sync', 'canvas-course-sync' ),
					'manage_options',
					'canvas-course-sync',
					array( $this, 'display_admin_page' ),
					'dashicons-update',
					30
				);

				add_submenu_page(
					'canvas-course-sync',
					__( 'Sync Logs', 'canvas-course-sync' ),
					__( 'Logs', 'canvas-course-sync' ),
					'manage_options',
					'canvas-course-sync-logs',
					array( $this, 'display_logs_page' )
				);
			}
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to add admin menu: ' . $e->getMessage() );
		}
	}

	/**
	 * Display admin page safely
	 */
	public function display_admin_page() {
		try {
			if ( class_exists( 'CCS_Admin_Page' ) ) {
				$admin_page = new CCS_Admin_Page();
				$admin_page->render();
			} else {
				echo '<div class="wrap">';
				echo '<h1>' . esc_html__( 'Canvas Course Sync', 'canvas-course-sync' ) . '</h1>';
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Admin page class not found. Please check plugin installation.', 'canvas-course-sync' );
				echo '</p></div>';
				echo '</div>';
			}
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to display admin page: ' . $e->getMessage() );
			echo '<div class="wrap"><h1>Canvas Course Sync</h1><div class="notice notice-error"><p>An error occurred loading the admin page.</p></div></div>';
		}
	}

	/**
	 * Display logs page safely
	 */
	public function display_logs_page() {
		try {
			if ( class_exists( 'CCS_Logs_Display' ) ) {
				$logs_display = new CCS_Logs_Display();
				$logs_display->render();
			} else {
				echo '<div class="wrap">';
				echo '<h1>' . esc_html__( 'Canvas Course Sync - Logs', 'canvas-course-sync' ) . '</h1>';
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Logs display class not found. Please check plugin installation.', 'canvas-course-sync' );
				echo '</p></div>';
				echo '</div>';
			}
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to display logs page: ' . $e->getMessage() );
			echo '<div class="wrap"><h1>Canvas Course Sync - Logs</h1><div class="notice notice-error"><p>An error occurred loading the logs page.</p></div></div>';
		}
	}

	/**
	 * Enqueue admin scripts safely
	 */
	public function enqueue_admin_scripts( $hook ) {
		try {
			// Define plugin pages where scripts should load
			$plugin_pages = array(
				'canvas-course-sync',
				'canvas-course-sync-settings', 
				'canvas-course-sync-logs',
			);

			$is_plugin_page = false;
			foreach ( $plugin_pages as $page ) {
				if ( strpos( $hook, $page ) !== false ) {
					$is_plugin_page = true;
					break;
				}
			}

			// Only load on plugin pages or plugins.php for updater
			if ( ! $is_plugin_page && $hook !== 'plugins.php' ) {
				return;
			}

			// Enqueue styles
			wp_enqueue_style(
				'ccs-admin-css',
				CCS_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				CCS_VERSION
			);

			// Enqueue scripts based on page
			if ( $is_plugin_page ) {
				$this->enqueue_plugin_scripts();
			}

			if ( $hook === 'plugins.php' ) {
				$this->enqueue_updater_script();
			}

		} catch ( Exception $e ) {
			$this->log_error( 'Failed to enqueue admin scripts: ' . $e->getMessage() );
		}
	}

	/**
	 * Enqueue main plugin scripts safely
	 */
	private function enqueue_plugin_scripts() {
		try {
			wp_enqueue_script(
				'ccs-admin-js',
				CCS_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				CCS_VERSION,
				true
			);

			wp_localize_script( 'ccs-admin-js', 'ccsAjax', $this->get_ajax_data() );
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to enqueue plugin scripts: ' . $e->getMessage() );
		}
	}

	/**
	 * Enqueue updater script safely
	 */
	private function enqueue_updater_script() {
		try {
			wp_enqueue_script(
				'ccs-updater-js',
				CCS_PLUGIN_URL . 'assets/js/updater.js',
				array(),
				CCS_VERSION,
				true
			);

			wp_localize_script(
				'ccs-updater-js',
				'ccsUpdaterData',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'ccs_check_updates' ),
				)
			);
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to enqueue updater script: ' . $e->getMessage() );
		}
	}

	/**
	 * Get AJAX data safely
	 */
	private function get_ajax_data() {
		try {
			return array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces' => array(
					'testConnection' => wp_create_nonce( 'ccs_test_connection' ),
					'getCourses' => wp_create_nonce( 'ccs_get_courses' ),
					'syncCourses' => wp_create_nonce( 'ccs_sync_courses' ),
					'syncStatus' => wp_create_nonce( 'ccs_sync_status' ),
					'clearLogs' => wp_create_nonce( 'ccs_clear_logs' ),
					'refreshLogs' => wp_create_nonce( 'ccs_refresh_logs' ),
					'runAutoSync' => wp_create_nonce( 'ccs_run_auto_sync' ),
					'omitCourses' => wp_create_nonce( 'ccs_omit_courses' ),
					'restoreOmitted' => wp_create_nonce( 'ccs_restore_omitted' ),
					'logError' => wp_create_nonce( 'ccs_log_js_error' ),
					'toggleAutoSync' => wp_create_nonce( 'ccs_toggle_auto_sync' ),
					'checkUpdates' => wp_create_nonce( 'ccs_check_updates' ),
					'cleanupDeleted' => wp_create_nonce( 'ccs_cleanup_deleted' ),
					'refreshCatalog' => wp_create_nonce( 'ccs_refresh_catalog' ),
				),
				'messages' => array(
					'confirmSync' => __( 'Are you sure you want to sync the selected courses?', 'canvas-course-sync' ),
					'confirmOmit' => __( 'Are you sure you want to omit the selected courses?', 'canvas-course-sync' ),
					'confirmRestore' => __( 'Are you sure you want to restore all omitted courses?', 'canvas-course-sync' ),
					'confirmClearLogs' => __( 'Are you sure you want to clear all logs?', 'canvas-course-sync' ),
					'noCoursesSelected' => __( 'Please select at least one course.', 'canvas-course-sync' ),
					'connectionSuccess' => __( 'Connection successful!', 'canvas-course-sync' ),
					'connectionFailed' => __( 'Connection failed. Please check your settings.', 'canvas-course-sync' ),
				),
				'settings' => array(
					'debugMode' => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'pluginVersion' => CCS_VERSION,
				),
			);
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to get AJAX data: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Register AJAX handlers (placeholder for compatibility)
	 */
	private function register_ajax_handlers() {
		// AJAX handlers are registered in includes/ajax-handlers.php
		// This method exists for backward compatibility
	}
}

/**
 * Get plugin instance safely
 */
function canvas_course_sync() {
	try {
		return Canvas_Course_Sync::get_instance();
	} catch ( Exception $e ) {
		error_log( 'CCS: Failed to get plugin instance: ' . $e->getMessage() );
		return null;
	}
}

/**
 * Plugin activation hook with error handling
 */
function ccs_activate_plugin() {
	try {
		// Set default options
		add_option( 'ccs_catalog_token', '' );
		add_option( 'ccs_notification_email', get_option( 'admin_email' ) );
		add_option( 'ccs_auto_sync_enabled', 0 );
		add_option( 'ccs_catalog_url', CCS_DEFAULT_CATALOG_URL );

		// Create logger table
		if ( class_exists( 'CCS_Logger' ) ) {
			$logger = new CCS_Logger();
			$logger->ensure_table_exists();
		}

		// Flush rewrite rules
		flush_rewrite_rules();

		// Set flag to indicate plugin should stay active after updates
		update_option( 'ccs_should_stay_active', 1 );

		do_action( 'ccs_plugin_activated' );

	} catch ( Exception $e ) {
		error_log( 'CCS: Activation failed: ' . $e->getMessage() );
	}
}

/**
 * Plugin deactivation hook with error handling
 */
function ccs_deactivate_plugin() {
	try {
		// Only clear the stay active flag for manual deactivation
		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			delete_option( 'ccs_should_stay_active' );
		}

		// Flush rewrite rules
		flush_rewrite_rules();

		do_action( 'ccs_plugin_deactivated' );

	} catch ( Exception $e ) {
		error_log( 'CCS: Deactivation failed: ' . $e->getMessage() );
	}
}

/**
 * Check if plugin should be reactivated after update
 */
function ccs_maybe_reactivate_after_update() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	try {
		if ( get_option( 'ccs_should_stay_active' ) && ! is_plugin_active( CCS_PLUGIN_BASENAME ) ) {
			activate_plugin( CCS_PLUGIN_BASENAME );

			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-success is-dismissible">';
				echo '<p><strong>Canvas Course Sync:</strong> Plugin was automatically reactivated after update.</p>';
				echo '</div>';
			});
		}
	} catch ( Exception $e ) {
		error_log( 'CCS: Reactivation check failed: ' . $e->getMessage() );
	}
}

// Hook to check for reactivation
add_action( 'admin_init', 'ccs_maybe_reactivate_after_update' );

// Initialize plugin
add_action( 'plugins_loaded', function() {
	try {
		canvas_course_sync();
	} catch ( Exception $e ) {
		error_log( 'CCS: Plugin initialization failed: ' . $e->getMessage() );
	}
}, 1 );

// Register activation/deactivation hooks
register_activation_hook( __FILE__, 'ccs_activate_plugin' );
register_deactivation_hook( __FILE__, 'ccs_deactivate_plugin' );
