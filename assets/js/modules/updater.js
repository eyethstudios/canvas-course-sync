
/**
 * GitHub updater functionality
 */

// Global function for check updates link
window.ccsCheckForUpdates = function() {
    console.log('CCS Debug: Manual update check started');
    
    // Show loading state
    const links = document.querySelectorAll('a[onclick*="ccsCheckForUpdates"]');
    const originalTexts = [];
    
    links.forEach((link, index) => {
        originalTexts[index] = link.innerHTML;
        link.innerHTML = 'Checking...';
        link.style.pointerEvents = 'none';
        link.style.opacity = '0.6';
    });
    
    // Prepare form data
    const formData = new FormData();
    formData.append('action', 'ccs_check_updates');
    formData.append('nonce', typeof ccsUpdaterNonce !== 'undefined' ? ccsUpdaterNonce : '');
    
    // Make AJAX request with better error handling
    fetch(ajaxurl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => {
        console.log('CCS Debug: Update check response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('CCS Debug: Update check response data:', data);
        
        // Restore links
        links.forEach((link, index) => {
            link.innerHTML = originalTexts[index];
            link.style.pointerEvents = 'auto';
            link.style.opacity = '1';
        });
        
        if (data.success) {
            alert(data.data.message);
            
            // If update is available, suggest refresh
            if (data.data.update_available) {
                if (confirm('Would you like to refresh the page to see the update notice?')) {
                    location.reload();
                }
            }
        } else {
            const errorMsg = data.data && data.data.message ? data.data.message : 'Failed to check for updates. Please try again.';
            console.error('CCS Debug: Update check failed:', errorMsg);
            alert('Update check failed: ' + errorMsg);
        }
    })
    .catch(error => {
        console.error('CCS Debug: Update check error:', error);
        
        // Restore links
        links.forEach((link, index) => {
            link.innerHTML = originalTexts[index];
            link.style.pointerEvents = 'auto';
            link.style.opacity = '1';
        });
        
        alert('Network error while checking for updates: ' + error.message + '. Please try again.');
    });
};

// Ensure the function is available globally
if (typeof window !== 'undefined') {
    window.ccsCheckForUpdates = window.ccsCheckForUpdates;
}

console.log('CCS Debug: Updater module loaded successfully');
