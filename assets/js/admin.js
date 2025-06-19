
import { initConnectionTester } from './modules/connection.js';
import { initCourseFetcher } from './modules/courses.js';
import { initSyncManager } from './modules/sync.js';
import { initAutoSyncManager } from './modules/auto-sync.js';
import { initLogManager } from './modules/logs.js';

jQuery(document).ready(function($) {
    console.log('CCS Debug: Admin JS loaded');
    console.log('CCS Debug: ccsAjax available:', typeof ccsAjax !== 'undefined');
    
    if (typeof ccsAjax === 'undefined') {
        console.error('CCS Debug: ccsAjax object not available');
        return;
    }
    
    // Initialize all modules
    initConnectionTester($);
    initCourseFetcher($);
    initSyncManager($);
    initAutoSyncManager($);
    initLogManager($);
    
    console.log('CCS Debug: All modules initialized');
});
