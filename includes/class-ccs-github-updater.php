
<?php
/**
 * GitHub Updater for Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Updater Class
 */
class CCS_GitHub_Updater {
    
    /**
     * Plugin file path
     */
    private $plugin_file;
    
    /**
     * GitHub repository
     */
    private $github_repo;
    
    /**
     * Plugin version
     */
    private $version;
    
    /**
     * Plugin slug
     */
    private $plugin_slug;
    
    /**
     * Plugin basename
     */
    private $plugin_basename;
    
    /**
     * Constructor
     */
    public function __construct($plugin_file, $github_repo, $version) {
        $this->plugin_file = $plugin_file;
        $this->github_repo = $github_repo;
        $this->version = $version;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_basename = dirname($this->plugin_slug);
        
        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Add custom update checker
        add_action('load-plugins.php', array($this, 'load_plugins_page'));
        add_action('load-update-core.php', array($this, 'load_plugins_page'));
        
        // Add plugin row meta for GitHub link
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        
        // Add AJAX handler for manual update check
        add_action('wp_ajax_ccs_check_updates', array($this, 'ajax_check_updates'));
    }
    
    /**
     * Load plugins page hooks
     */
    public function load_plugins_page() {
        add_action('admin_notices', array($this, 'update_notice'));
    }
    
    /**
     * Add plugin row meta
     */
    public function plugin_row_meta($links, $file) {
        if ($file === $this->plugin_slug) {
            $links[] = '<a href="https://github.com/' . $this->github_repo . '" target="_blank">' . __('View on GitHub', 'canvas-course-sync') . '</a>';
            $links[] = '<a href="#" onclick="ccsCheckForUpdates(); return false;" style="color: #2271b1;">' . __('Check for updates', 'canvas-course-sync') . '</a>';
        }
        return $links;
    }
    
    /**
     * Show update notice if available
     */
    public function update_notice() {
        $remote_version = $this->get_remote_version();
        if (version_compare($this->version, $remote_version, '<')) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>' . sprintf(
                __('Canvas Course Sync version %s is available. <a href="%s">Update now</a>', 'canvas-course-sync'),
                $remote_version,
                wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . $this->plugin_slug), 'upgrade-plugin_' . $this->plugin_slug)
            ) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get remote version
        $remote_version = $this->get_remote_version();
        
        // Debug logging
        error_log('CCS Debug: Current version: ' . $this->version);
        error_log('CCS Debug: Remote version: ' . $remote_version);
        error_log('CCS Debug: Version comparison: ' . (version_compare($this->version, $remote_version, '<') ? 'UPDATE AVAILABLE' : 'UP TO DATE'));
        
        if (version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => $this->plugin_basename,
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => 'https://github.com/' . $this->github_repo,
                'package' => $this->get_download_url(),
                'tested' => '6.4',
                'requires_php' => '7.4',
                'compatibility' => new stdClass()
            );
            
            error_log('CCS Debug: Update added to transient');
        }
        
        return $transient;
    }
    
    /**
     * Get remote version from GitHub
     */
    private function get_remote_version() {
        // For manual checks, always bypass cache
        $bypass_cache = isset($_POST['action']) && $_POST['action'] === 'ccs_check_updates';
        
        // Check cache first (unless bypassing)
        $cache_key = 'ccs_github_version_' . md5($this->github_repo);
        if (!$bypass_cache) {
            $cached_version = get_transient($cache_key);
            if ($cached_version !== false) {
                return $cached_version;
            }
        }
        
        // Make GitHub API request
        $request = wp_remote_get('https://api.github.com/repos/' . $this->github_repo . '/releases/latest', array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Canvas-Course-Sync'
            )
        ));
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            if (isset($data['tag_name'])) {
                // Clean version number (remove 'v' prefix if present)
                $version = ltrim($data['tag_name'], 'v');
                
                // Validate version format
                if (preg_match('/^\d+\.\d+(\.\d+)?/', $version)) {
                    // Cache for 6 hours (shorter cache for more frequent checks)
                    set_transient($cache_key, $version, 6 * HOUR_IN_SECONDS);
                    return $version;
                }
            }
        }
        
        // If we can't get remote version, return current version
        return $this->version;
    }
    
    /**
     * Get download URL
     */
    private function get_download_url() {
        return 'https://github.com/' . $this->github_repo . '/archive/refs/heads/main.zip';
    }
    
    /**
     * Plugin information for the update screen
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_basename) {
            return $result;
        }
        
        $request = wp_remote_get('https://api.github.com/repos/' . $this->github_repo, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json'
            )
        ));
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            $result = new stdClass();
            $result->name = 'Canvas Course Sync';
            $result->slug = $this->plugin_basename;
            $result->version = $this->get_remote_version();
            $result->author = '<a href="http://eyethstudios.com">Eyeth Studios</a>';
            $result->homepage = $data['html_url'];
            $result->short_description = $data['description'] ?? 'Synchronize courses from Canvas LMS to WordPress';
            $result->sections = array(
                'description' => $data['description'] ?? 'Synchronize courses from Canvas LMS to WordPress with full API integration and course management.',
                'changelog' => 'View changelog on <a href="' . $data['html_url'] . '/releases" target="_blank">GitHub</a>'
            );
            $result->download_link = $this->get_download_url();
            $result->requires = '5.0';
            $result->tested = '6.4';
            $result->requires_php = '7.4';
            $result->last_updated = $data['updated_at'] ?? date('Y-m-d');
        }
        
        return $result;
    }
    
    /**
     * Post install actions
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        if ($this->plugin_basename === $hook_extra['plugin']) {
            $result['destination_name'] = $this->plugin_basename;
        }
        
        return $result;
    }
    
    /**
     * AJAX handler for manual update check
     */
    public function ajax_check_updates() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ccs_check_updates')) {
            wp_die('Security check failed');
        }
        
        // Clear cached version to force fresh check
        $cache_key = 'ccs_github_version_' . md5($this->github_repo);
        delete_transient($cache_key);
        
        // Clear WordPress update cache too
        delete_site_transient('update_plugins');
        
        // Get fresh version from GitHub
        $remote_version = $this->get_remote_version();
        
        // Log the check
        error_log('CCS Debug: Manual update check - Current: ' . $this->version . ', Remote: ' . $remote_version);
        
        if (version_compare($this->version, $remote_version, '<')) {
            wp_send_json_success(array(
                'message' => sprintf(__('Update available! Version %s is ready to install.', 'canvas-course-sync'), $remote_version),
                'update_available' => true,
                'current_version' => $this->version,
                'remote_version' => $remote_version
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(__('Plugin is up to date! Current version: %s', 'canvas-course-sync'), $this->version),
                'update_available' => false,
                'current_version' => $this->version,
                'remote_version' => $remote_version
            ));
        }
    }
}
