
/**
 * Canvas Course Sync Admin JavaScript
 */
import { initConnectionTester } from './modules/connection.js';
import { initSyncManager } from './modules/sync.js';
import { initCoursesLoader } from './modules/courses.js';
import { initLogsManager } from './modules/logs.js';
import { initAutoSync } from './modules/auto-sync.js';

jQuery(document).ready(function($) {
    // Initialize all modules
    initConnectionTester($);
    initSyncManager($);
    initCoursesLoader($);
    initLogsManager($);
    initAutoSync($);
});
