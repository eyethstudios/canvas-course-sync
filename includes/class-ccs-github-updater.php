
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
        
        // Check for updates on admin_init (less aggressive)
        add_action('admin_init', array($this, 'maybe_check_updates'));
    }
    
    /**
     * Maybe check for updates (throttled)
     */
    public function maybe_check_updates() {
        // Only check once per hour
        $last_check = get_transient('ccs_last_update_check');
        if ($last_check === false) {
            $this->force_update_check();
            set_transient('ccs_last_update_check', time(), HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Get current plugin version
     */
    private function get_current_version() {
        return defined('CCS_VERSION') ? CCS_VERSION : $this->version;
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
        $current_version = $this->get_current_version();
        
        if ($this->is_update_available($current_version, $remote_version)) {
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
     * Check if update is available
     */
    private function is_update_available($current_version, $remote_version) {
        $current_clean = $this->clean_version($current_version);
        $remote_clean = $this->clean_version($remote_version);
        
        error_log('CCS Debug: Version comparison - Current: ' . $current_clean . ' vs Remote: ' . $remote_clean);
        
        return version_compare($current_clean, $remote_clean, '<');
    }
    
    /**
     * Clean version string
     */
    private function clean_version($version) {
        $version = trim($version);
        $version = ltrim($version, 'v');
        
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            return $matches[1];
        }
        
        return $version;
    }
    
    /**
     * Force update check
     */
    public function force_update_check() {
        // Clear relevant caches
        delete_transient('ccs_github_version_' . md5($this->github_repo));
        delete_site_transient('update_plugins');
        
        // Force WordPress to check for updates
        wp_clean_update_cache();
        
        error_log('CCS Debug: Forced update check completed');
    }
    
    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $remote_version = $this->get_remote_version();
        $current_version = $this->get_current_version();
        
        error_log('CCS Debug: Update check - Current: ' . $current_version . ', Remote: ' . $remote_version);
        
        if ($this->is_update_available($current_version, $remote_version)) {
            $update_data = array(
                'slug' => $this->plugin_basename,
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => 'https://github.com/' . $this->github_repo,
                'package' => $this->get_download_url($remote_version),
                'tested' => '6.4',
                'requires_php' => '7.4',
                'compatibility' => new stdClass(),
                'icons' => array(
                    'default' => plugin_dir_url($this->plugin_file) . 'assets/icon-128x128.png'
                )
            );
            
            $transient->response[$this->plugin_slug] = (object) $update_data;
            error_log('CCS Debug: Update available - added to WordPress update system');
        } else {
            // Remove from response if it was previously there
            if (isset($transient->response[$this->plugin_slug])) {
                unset($transient->response[$this->plugin_slug]);
            }
        }
        
        return $transient;
    }
    
    /**
     * Get remote version from GitHub
     */
    private function get_remote_version($force_fresh = false) {
        $cache_key = 'ccs_github_version_' . md5($this->github_repo);
        
        if (!$force_fresh) {
            $cached_version = get_transient($cache_key);
            if ($cached_version !== false) {
                error_log('CCS Debug: Using cached GitHub version: ' . $cached_version);
                return $cached_version;
            }
        }
        
        error_log('CCS Debug: Fetching fresh version from GitHub: ' . $this->github_repo);
        
        $request_url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
        
        $request = wp_remote_get($request_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Canvas-Course-Sync-Updater'
            )
        ));
        
        if (is_wp_error($request)) {
            error_log('CCS Debug: GitHub API request failed: ' . $request->get_error_message());
            return $this->get_current_version();
        }
        
        $response_code = wp_remote_retrieve_response_code($request);
        $response_body = wp_remote_retrieve_body($request);
        
        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            
            if (isset($data['tag_name'])) {
                $raw_version = $data['tag_name'];
                $clean_version = $this->clean_version($raw_version);
                
                error_log('CCS Debug: GitHub version found - Raw: ' . $raw_version . ', Clean: ' . $clean_version);
                
                if (preg_match('/^\d+\.\d+\.\d+$/', $clean_version)) {
                    // Cache for 1 hour
                    set_transient($cache_key, $clean_version, HOUR_IN_SECONDS);
                    return $clean_version;
                }
            }
        }
        
        error_log('CCS Debug: Failed to get GitHub version, using current version');
        return $this->get_current_version();
    }
    
    /**
     * Get download URL
     */
    private function get_download_url($version = null) {
        if ($version) {
            return 'https://github.com/' . $this->github_repo . '/archive/refs/tags/v' . $version . '.zip';
        }
        
        return 'https://github.com/' . $this->github_repo . '/archive/refs/heads/main.zip';
    }
    
    /**
     * AJAX handler for manual update checks
     */
    public function ajax_check_updates() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ccs_check_updates')) {
            wp_die('Security check failed');
        }
        
        error_log('CCS Debug: Manual update check started');
        
        // Force fresh check
        $current_version = $this->get_current_version();
        $remote_version = $this->get_remote_version(true);
        
        error_log('CCS Debug: Manual check results - Current: ' . $current_version . ', Remote: ' . $remote_version);
        
        if ($this->is_update_available($current_version, $remote_version)) {
            // Clear WordPress update cache to force refresh
            delete_site_transient('update_plugins');
            
            wp_send_json_success(array(
                'message' => sprintf(__('Update available! Version %s is ready. Please refresh this page to see the update.', 'canvas-course-sync'), $remote_version),
                'update_available' => true,
                'current_version' => $current_version,
                'remote_version' => $remote_version
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(__('Plugin is up to date! Current: %s, Latest: %s', 'canvas-course-sync'), $current_version, $remote_version),
                'update_available' => false,
                'current_version' => $current_version,
                'remote_version' => $remote_version
            ));
        }
    }
    
    /**
     * Plugin information for update screen
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
}
