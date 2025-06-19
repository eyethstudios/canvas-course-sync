
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
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
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
        
        if (version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = array(
                'slug' => $this->plugin_basename,
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => 'https://github.com/' . $this->github_repo,
                'package' => $this->get_download_url()
            );
        }
        
        return $transient;
    }
    
    /**
     * Get remote version from GitHub
     */
    private function get_remote_version() {
        $request = wp_remote_get('https://api.github.com/repos/' . $this->github_repo . '/releases/latest');
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            if (isset($data['tag_name'])) {
                return ltrim($data['tag_name'], 'v');
            }
        }
        
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
        
        $request = wp_remote_get('https://api.github.com/repos/' . $this->github_repo);
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            $result = new stdClass();
            $result->name = 'Canvas Course Sync';
            $result->slug = $this->plugin_basename;
            $result->version = $this->get_remote_version();
            $result->author = 'Eyeth Studios';
            $result->homepage = $data['html_url'];
            $result->short_description = $data['description'];
            $result->sections = array(
                'description' => $data['description'],
                'changelog' => 'View changelog on GitHub'
            );
            $result->download_link = $this->get_download_url();
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
