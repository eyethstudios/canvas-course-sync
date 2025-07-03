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
    
    private $plugin_file;
    private $github_repo;
    private $version;
    private $plugin_slug;
    private $plugin_basename;
    
    public function __construct($plugin_file, $github_repo, $version) {
        $this->plugin_file = $plugin_file;
        $this->github_repo = $github_repo;
        $this->version = $version;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_basename = dirname($this->plugin_slug);
        
        // Debug output to check plugin identification
        error_log('CCS Debug: GitHub Updater initialized - Plugin Slug: ' . $this->plugin_slug);
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'source_selection'), 10, 4);
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        add_action('wp_ajax_ccs_check_updates', array($this, 'ajax_check_updates'));
        
        if (is_admin()) {
            add_action('admin_notices', array($this, 'update_notice'));
        }
    }
    
    /**
     * Get current plugin version with fallback
     */
    private function get_current_version() {
        return defined('CCS_VERSION') ? CCS_VERSION : $this->version;
    }
    
    /**
     * Add plugin row meta
     */
    public function plugin_row_meta($links, $file) {
        error_log('CCS Debug: plugin_row_meta called - File: ' . $file . ' | Expected: ' . $this->plugin_slug);
        
        if ($file === $this->plugin_slug) {
            error_log('CCS Debug: Adding plugin row meta links');
            $links[] = '<a href="https://github.com/' . $this->github_repo . '" target="_blank">' . __('View on GitHub', 'canvas-course-sync') . '</a>';
            $links[] = '<a href="javascript:void(0);" onclick="ccsCheckForUpdates(); return false;" style="color: #2271b1;">' . __('Check for updates', 'canvas-course-sync') . '</a>';
        }
        return $links;
    }
    
    /**
     * Show update notice if available
     */
    public function update_notice() {
        // Only show on plugins page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }
        
        $remote_version = $this->get_remote_version();
        $current_version = $this->get_current_version();
        
        if ($this->is_update_available($current_version, $remote_version)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>' . sprintf(
                __('Canvas Course Sync version %s is available. <a href="%s">Update now</a>', 'canvas-course-sync'),
                esc_html($remote_version),
                esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . $this->plugin_slug), 'upgrade-plugin_' . $this->plugin_slug))
            ) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Improved version comparison
     */
    private function is_update_available($current_version, $remote_version) {
        $current_clean = $this->normalize_version($current_version);
        $remote_clean = $this->normalize_version($remote_version);
        
        error_log('CCS Debug: Version comparison - Current: ' . $current_clean . ' vs Remote: ' . $remote_clean);
        
        return version_compare($current_clean, $remote_clean, '<');
    }
    
    /**
     * Normalize version string for comparison
     */
    private function normalize_version($version) {
        $version = trim($version);
        $version = ltrim($version, 'v');
        
        // Extract semantic version pattern
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            return sprintf('%d.%d.%d', intval($matches[1]), intval($matches[2]), intval($matches[3]));
        }
        
        return $version;
    }
    
    /**
     * Force update check with better cache management
     */
    public function force_update_check() {
        delete_transient('ccs_github_version_' . md5($this->github_repo));
        delete_transient('ccs_github_releases_' . md5($this->github_repo));
        delete_site_transient('update_plugins');
        wp_clean_update_cache();
        
        error_log('CCS Debug: Forced update check completed - cleared all caches');
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
        
        if ($this->is_update_available($current_version, $remote_version)) {
            $update_data = array(
                'slug' => $this->plugin_basename,
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => 'https://github.com/' . $this->github_repo,
                'package' => $this->get_download_url($remote_version),
                'tested' => get_bloginfo('version'),
                'requires_php' => '7.4',
                'compatibility' => new stdClass()
            );
            
            $transient->response[$this->plugin_slug] = (object) $update_data;
            error_log('CCS Debug: Update available - added to WordPress update system');
        } else {
            // Make sure to remove from no_update as well
            if (isset($transient->response[$this->plugin_slug])) {
                unset($transient->response[$this->plugin_slug]);
            }
            if (isset($transient->no_update[$this->plugin_slug])) {
                unset($transient->no_update[$this->plugin_slug]);
            }
        }
        
        return $transient;
    }
    
    /**
     * Get remote version from GitHub with improved error handling
     */
    private function get_remote_version($force_fresh = false) {
        $cache_key = 'ccs_github_version_' . md5($this->github_repo);
        
        if (!$force_fresh) {
            $cached_version = get_transient($cache_key);
            if ($cached_version !== false) {
                error_log('CCS Debug: Using cached version: ' . $cached_version);
                return $cached_version;
            }
        }
        
        error_log('CCS Debug: Fetching fresh version from GitHub API');
        $request_url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
        
        $request = wp_remote_get($request_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Canvas-Course-Sync-Updater/' . $this->get_current_version()
            )
        ));
        
        if (is_wp_error($request)) {
            error_log('CCS Debug: GitHub API request failed: ' . $request->get_error_message());
            return $this->get_current_version();
        }
        
        $response_code = wp_remote_retrieve_response_code($request);
        $response_body = wp_remote_retrieve_body($request);
        
        error_log('CCS Debug: GitHub API response code: ' . $response_code);
        
        if ($response_code === 200 && !empty($response_body)) {
            $data = json_decode($response_body, true);
            error_log('CCS Debug: GitHub API response data: ' . print_r($data, true));
            
            if (isset($data['tag_name']) && !empty($data['tag_name'])) {
                $clean_version = $this->normalize_version($data['tag_name']);
                
                if (!empty($clean_version)) {
                    error_log('CCS Debug: Setting cached version: ' . $clean_version);
                    set_transient($cache_key, $clean_version, 30 * MINUTE_IN_SECONDS); // Shorter cache
                    return $clean_version;
                }
            }
        }
        
        error_log('CCS Debug: Failed to get GitHub version, using current version. Response code: ' . $response_code);
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
     * Improved source selection with better error handling
     */
    public function source_selection($source, $remote_source, $upgrader, $hook_extra = null) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $source;
        }
        
        global $wp_filesystem;
        
        if (!$wp_filesystem || !$wp_filesystem->is_dir($source)) {
            return new WP_Error('source_not_dir', 'Source is not a valid directory');
        }
        
        $plugin_folder = $this->plugin_basename;
        
        if (basename($source) === $plugin_folder) {
            return $source;
        }
        
        $files = $wp_filesystem->dirlist($source);
        
        if (!empty($files)) {
            foreach ($files as $file => $file_data) {
                if ($file_data['type'] === 'd') {
                    $potential_source = trailingslashit($source) . $file;
                    
                    if ($wp_filesystem->exists($potential_source . '/' . basename($this->plugin_file))) {
                        $corrected_source = trailingslashit($remote_source) . $plugin_folder;
                        
                        if ($wp_filesystem->move($potential_source, $corrected_source)) {
                            return $corrected_source;
                        }
                    }
                }
            }
        }
        
        return $source;
    }
    
    /**
     * AJAX handler for manual update checks
     */
    public function ajax_check_updates() {
        // Verify nonce and capabilities
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'ccs_check_updates')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Force fresh check
        $this->force_update_check();
        
        $current_version = $this->get_current_version();
        $remote_version = $this->get_remote_version(true);
        
        error_log('CCS Debug: Manual update check - Current: ' . $current_version . ', Remote: ' . $remote_version);
        
        if ($this->is_update_available($current_version, $remote_version)) {
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
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Canvas-Course-Sync-Updater'
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
            $result->homepage = $data['html_url'] ?? 'https://github.com/' . $this->github_repo;
            $result->short_description = $data['description'] ?? 'Synchronize courses from Canvas LMS to WordPress';
            $result->sections = array(
                'description' => $data['description'] ?? 'Synchronize courses from Canvas LMS to WordPress with full API integration and course management.',
                'changelog' => 'View changelog on <a href="' . ($data['html_url'] ?? 'https://github.com/' . $this->github_repo) . '/releases" target="_blank">GitHub</a>'
            );
            $result->download_link = $this->get_download_url();
            $result->requires = '5.0';
            $result->tested = get_bloginfo('version');
            $result->requires_php = '7.4';
            $result->last_updated = $data['updated_at'] ?? date('Y-m-d');
        }
        
        return $result;
    }
}
