
# Canvas Course Sync

A WordPress plugin to synchronize courses from Canvas LMS to a WordPress custom post type.

## Description

Canvas Course Sync creates a bridge between your Canvas Learning Management System and WordPress. It allows you to import courses from Canvas into a custom post type called "courses" in WordPress.

The plugin will:
- Connect to the Canvas API using your credentials
- Fetch course data including titles, descriptions, and images
- Compare with existing WordPress courses (by title) and skip duplicates
- Import new courses as WordPress posts
- Set featured images from Canvas course images when available
- Log all activities and provide a detailed admin interface

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- A custom post type named "courses" must exist in your WordPress installation
- Canvas LMS account with API access

## Installation

1. Upload the `canvas-course-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Canvas Sync menu in the WordPress admin area
4. Configure your Canvas API settings

## Configuration

1. Navigate to Canvas Sync in your WordPress admin menu
2. Enter your Canvas Domain (e.g., https://canvas.instructure.com)
3. Enter your Canvas API Token 
4. Click "Test Connection" to verify your settings
5. Once connected, click "Sync Courses Now" to begin importing

## Usage

After configuration, simply click the "Sync Courses Now" button whenever you want to import new courses from Canvas. The plugin will:

- Connect to your Canvas instance
- Retrieve all courses
- Skip courses that already exist in WordPress (matched by title)
- Import new courses with their descriptions and featured images
- Display import statistics and logs

## Logs

The plugin maintains detailed logs of all operations. You can view recent logs in the admin interface or access the full log file in your WordPress uploads directory.

## Customization

If you need to customize how courses are imported or mapped to WordPress, you can modify the CCS_Importer class in `includes/importer.php`.

## Support

For support or feature requests, please open an issue in the plugin's repository.

## License

This plugin is licensed under the GPL v2 or later.
