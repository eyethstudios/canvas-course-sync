
=== Canvas Course Sync ===
Contributors: eyethstudios
Donate link: http://eyethstudios.com/donate
Tags: canvas, lms, course, sync, education
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.2.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize courses from Canvas LMS to WordPress with full API integration and course management.

== Description ==

Canvas Course Sync allows you to easily synchronize courses from your Canvas LMS instance to WordPress. This plugin provides:

* Secure API integration with Canvas LMS
* Selective course synchronization
* Automatic course import and management
* Comprehensive logging system
* Admin interface for easy configuration

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/canvas-course-sync` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Canvas Sync screen to configure your Canvas API settings
4. Enter your Canvas domain and API token
5. Test the connection and start syncing courses

== Frequently Asked Questions ==

= How do I get a Canvas API token? =

Visit your Canvas instance, go to Account > Settings > Approved Integrations > New Access Token.

= What permissions does the API token need? =

The token needs read access to courses and basic user information.

== Screenshots ==

1. Main plugin configuration page
2. Course synchronization interface
3. Logging and status monitoring

== Changelog ==

= 2.2.8 =
* Fixed version detection in GitHub updater to use correct CCS_VERSION constant
* Enhanced update checking accuracy and reliability

= 2.2.7 =
* Fixed refresh and clear logs button functionality
* Enhanced logs display AJAX handling
* Improved error handling for logs operations

= 2.2.6 =
* Fixed JavaScript module loading issues for admin interface
* Enhanced debugging and error handling for connection testing
* Improved button functionality for test connection and get courses

= 2.2.5 =
* Fixed GitHub updater version detection issues
* Enhanced update checking mechanism
* Improved admin JavaScript loading

= 2.2.3 =
* Updated plugin author information and GitHub repository links
* Fixed broken plugin site and GitHub links

= 1.0.0 =
* Initial release
* Canvas API integration
* Course synchronization
* Admin interface
* Logging system

== Upgrade Notice ==

= 2.2.8 =
Fixed version detection accuracy in GitHub updater.

= 2.2.7 =
Fixed logs functionality and improved button reliability.

= 2.2.6 =
Fixed JavaScript functionality and improved admin interface reliability.

= 2.2.5 =
Enhanced GitHub updater and version detection.

= 2.2.3 =
Updated author information and repository links.

= 1.0.0 =
Initial release of Canvas Course Sync plugin.
