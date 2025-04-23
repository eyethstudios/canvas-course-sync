
/**
 * Canvas Course Sync Admin JavaScript
 */
import { initConnectionTester } from './modules/connection.js';
import { initLogManager } from './modules/logs.js';
import { initCourseManager } from './modules/courses.js';
import { initSyncManager } from './modules/sync.js';

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Initialize all modules
        initConnectionTester($);
        initLogManager($);
        initCourseManager($);
        initSyncManager($);
    });
})(jQuery);
