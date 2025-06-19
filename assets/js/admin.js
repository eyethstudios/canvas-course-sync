
// Import all module functions
import { initConnectionTester } from './modules/connection.js';
import { initCourseManager } from './modules/courses.js';
import { initSyncManager } from './modules/sync.js';
import { initAutoSyncManager } from './modules/auto-sync.js';
import { initLogManager } from './modules/logs.js';

jQuery(document).ready(function($) {
    console.log('CCS Debug: Admin JS loaded');
    console.log('CCS Debug: ccsAjax available:', typeof ccsAjax !== 'undefined');
    console.log('CCS Debug: jQuery available:', typeof $ !== 'undefined');
    
    if (typeof ccsAjax === 'undefined') {
        console.error('CCS Debug: ccsAjax object not available - AJAX calls will fail');
        return;
    }
    
    console.log('CCS Debug: ccsAjax object:', ccsAjax);
    
    // Test if buttons exist
    console.log('CCS Debug: Test connection button exists:', $('#ccs-test-connection').length > 0);
    console.log('CCS Debug: Get courses button exists:', $('#ccs-get-courses').length > 0);
    
    // Initialize all modules
    try {
        initConnectionTester($);
        console.log('CCS Debug: Connection tester initialized');
    } catch (error) {
        console.error('CCS Debug: Failed to initialize connection tester:', error);
    }
    
    try {
        initCourseManager($);
        console.log('CCS Debug: Course manager initialized');
    } catch (error) {
        console.error('CCS Debug: Failed to initialize course manager:', error);
    }
    
    try {
        initSyncManager($);
        console.log('CCS Debug: Sync manager initialized');
    } catch (error) {
        console.error('CCS Debug: Failed to initialize sync manager:', error);
    }
    
    try {
        initAutoSyncManager($);
        console.log('CCS Debug: Auto-sync manager initialized');
    } catch (error) {
        console.error('CCS Debug: Failed to initialize auto-sync manager:', error);
    }
    
    try {
        initLogManager($);
        console.log('CCS Debug: Log manager initialized');
    } catch (error) {
        console.error('CCS Debug: Failed to initialize log manager:', error);
    }
    
    console.log('CCS Debug: All modules initialization attempted');
});
