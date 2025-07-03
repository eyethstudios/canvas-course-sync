=== Canvas Course Sync ===
Contributors: eyethstudios
Donate link: http://eyethstudios.com/donate
Tags: canvas, lms, course, sync, education
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 3.1.7
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

= 3.1.7 =
* Enhanced content handler debugging to identify why course-specific content isn't being used
* Improved content extraction logic to prioritize Canvas course descriptions over fallback content

= 3.1.6 =
* Added "Cleanup Deleted Courses" feature to update sync status from 'synced' to 'available' for deleted/trashed WordPress courses
* Enhanced database manager with cleanup functionality to maintain accurate sync status tracking

= 3.1.5 =
* Enhanced Canvas API pagination debugging and increased page limit to handle all courses
* Fixed issue where only 15 courses were retrieved instead of all available courses

= 3.1.4 =
* Enhanced GitHub updater debugging to identify why "Check for updates" link isn't showing
* Added comprehensive logging for plugin row meta functionality

= 3.1.3 =
* Enhanced content generation to pull actual course-specific content from Canvas modules
* Improved extraction of module descriptions and learning objectives from Canvas pages
* Fixed content handler to display real Canvas content instead of generic placeholders

= 3.1.2 =
* Fixed pagination to properly retrieve all courses using Canvas API Link headers
* Enhanced logging to show pagination progress

= 3.1.1 =
* Fixed Canvas API 500 error by simplifying enrollment_type parameter

= 3.1.0 =
* Fixed Canvas API endpoint for better course retrieval with multiple enrollment types
* Fixed "Check for updates" link visibility in plugins page
* Enhanced debugging for GitHub updater functionality
* Improved course content structure with Module Description, Learning Objectives, and Badge Information

= 3.0.0 =
* Major security improvements with proper AJAX nonce sanitization
* Complete plugin architecture refactoring for better WordPress compliance
* Replaced hard-coded course exclusions with dynamic catalog validation system
* Added user-configurable catalog URL support for flexible course validation
* Consolidated AJAX handlers into separate organized file for better maintainability
* Enhanced error handling and logging throughout the plugin
* Fixed critical security vulnerabilities in AJAX endpoints
* Improved plugin structure following WordPress coding standards
* Removed obsolete JavaScript modules and cleaned up asset organization
* Enhanced database manager with proper post status handling

= 2.4.8 =
* Fixed get courses button functionality with improved error handling
* Enhanced catalog validator with proper file existence checks
* Improved backward compatibility for course validation system

= 2.4.7 =
* Fixed double confirmation popup on course sync completion
* Corrected post type inconsistency - synced courses now appear correctly in WordPress admin
* Added automatic catalog validation against National Deaf Center approved courses
* Auto-omit functionality for courses not found in approved catalog
* Enhanced AJAX handlers with missing omit course nonces
* Improved sync functionality and course visibility in WordPress admin

= 2.4.6 =
* Enhanced WordPress compatibility and code cleanup
* Fixed nonce handling and duplicate event handlers
* Improved sync button functionality and error handling
* Enhanced enrollment URL generation and input sanitization
* Resolved duplicate sync confirmations and course duplication issues
* Fixed course description to show detailed content instead of modules
* Improved 'Omit Selected Courses' button functionality

= 2.4.5 =
* Enhanced WordPress compatibility with improved security and sanitization
* Fixed AJAX nonce verification and user capability checks
* Improved Canvas API integration with better error handling
* Enhanced media handling for course featured images
* Better content handling for course descriptions and enrollment links
* Strengthened plugin security throughout all components

= 2.4.4 =
* Fixed missing "Sync Selected Courses" and "Omit Selected Courses" buttons in course sync interface
* Improved button visibility and functionality after course loading
* Enhanced course selection interface reliability

= 2.4.3 =
* Fixed missing "Omit Selected Courses" button in sync controls
* Fixed non-functional sync selected courses button functionality
* Improved course selection and synchronization interface

= 2.4.2 =
* Added functionality to select and omit specific courses from syncing
* Users can now mark courses to be excluded from future sync operations
* Omitted courses are stored persistently and shown with visual indicators
* Enhanced course management with better user control over sync selections

= 2.4.1 =
* Fixed JavaScript duplication issues between admin.js and courses.js modules
* Improved GitHub updater with better version comparison and error handling
* Enhanced Canvas API class with comprehensive error handling and validation
* Fixed security issues with proper nonce verification and user capability checks
* Improved plugin structure and code organization

= 2.4.0 =
* Fixed course filtering and sorting controls display
* Fixed sync button placement below course list
* Improved course manager JavaScript module loading
* Enhanced admin interface functionality

= 2.3.9 =
* Fixed plugin update mechanism and GitHub integration
* Improved version detection and update checking reliability
* Enhanced plugin installation and upgrade process

= 2.3.8 =
* Updated version tracking protocol for consistent version management

= 2.3.7 =
* Restored filtering and sorting controls for course management
* Fixed course status filtering (New/Existing/Already Synced)
* Fixed course sorting by date, name, and status
* Improved course display with proper status badges
* Moved sync buttons below course listing for better UX
* Corrected plugin author information

= 2.3.6 =
* Fixed plugin updater functionality
* Improved version detection and cache management
* Better WordPress integration for updates
* Enhanced GitHub release detection

= 2.3.5 =
* Fixed "no courses found" display issue
* Improved JavaScript response handling for course data
* Better error messaging for course loading

= 2.3.4 =
* Enhanced course data debugging and response handling
* Improved AJAX response format detection for get courses functionality
* Added detailed console logging for course retrieval troubleshooting

= 2.3.3 =
* Enhanced course data debugging and response handling
* Improved AJAX response format detection for get courses functionality
* Added detailed console logging for course retrieval troubleshooting

= 2.3.2 =
* Fixed plugin update version detection and improved GitHub update checking reliability

= 2.3.1 =
* Fixed admin button functionality and improved JavaScript handling in admin interface

= 2.3.0 =
* Fixed critical JavaScript issues preventing admin buttons from working properly

= 2.2.9 =
* Enhanced GitHub updater with improved version comparison and update detection
* Added automatic version normalization for consistent comparisons
* Improved update checking reliability and GitHub API integration
* Enhanced debugging and logging for update processes

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

= 3.0.0 =
Major security and architecture improvements with dynamic catalog validation and enhanced WordPress compliance.

= 2.4.8 =
Fixed get courses button and improved catalog validation error handling.

= 2.4.7 =
Fixed sync issues, added catalog validation, and improved course visibility in WordPress admin.

= 2.4.6 =
Enhanced WordPress compatibility with improved code cleanup, fixed sync functionality, and resolved duplicate issues.

= 2.4.5 =
Enhanced WordPress compatibility with improved security, better API integration, and strengthened plugin security throughout all components.

= 2.4.4 =
Fixed missing sync and omit course buttons in the course synchronization interface.

= 2.4.3 =
Fixed missing omit courses button and sync functionality issues.

= 2.4.2 =
Added functionality to select and omit specific courses from syncing operations.

= 2.4.1 =
Fixed JavaScript duplication issues and improved GitHub updater functionality.

= 2.4.0 =
Fixed course filtering, sorting controls and sync button placement for better user experience.

= 2.3.9 =
Fixed plugin update mechanism and improved GitHub integration for reliable updates.

= 2.3.8 =
Updated version tracking protocol for consistent version management.

= 2.3.7 =
Restored filtering and sorting controls for course management.

= 2.3.6 =
Fixed plugin updater functionality and improved plugin update detection.

= 2.3.5 =
Fixed JavaScript response handling issue for course retrieval operations.

= 2.3.4 =
Enhanced course data debugging and improved response handling for better troubleshooting.

= 2.3.3 =
Enhanced course data debugging and improved response handling for better troubleshooting.

= 2.3.2 =
Fixed plugin update version detection and improved GitHub update checking reliability.

= 2.3.1 =
Fixed admin button functionality and improved JavaScript handling in admin interface.

= 2.3.0 =
Fixed critical JavaScript issues preventing admin buttons from working properly.

= 2.2.9 =
Enhanced GitHub updater with improved version comparison and update detection.

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
