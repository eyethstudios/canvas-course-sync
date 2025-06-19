
/**
 * GitHub updater functionality
 */

// Global function for check updates link
window.ccsCheckForUpdates = function() {
    console.log('CCS Debug: Check for updates clicked');
    
    // Show loading state
    const links = document.querySelectorAll('a[onclick*="ccsCheckForUpdates"]');
    links.forEach(link => {
        link.innerHTML = 'Checking...';
        link.style.pointerEvents = 'none';
    });
    
    // Make AJAX request
    fetch(ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'ccs_check_updates',
            nonce: ccsUpdaterNonce || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('CCS Debug: Update check response:', data);
        
        // Restore links
        links.forEach(link => {
            link.innerHTML = 'Check for updates';
            link.style.pointerEvents = 'auto';
        });
        
        if (data.success) {
            alert(data.data.message);
            
            // If update is available, refresh the page to show update notice
            if (data.data.update_available) {
                location.reload();
            }
        } else {
            alert('Failed to check for updates. Please try again.');
        }
    })
    .catch(error => {
        console.error('CCS Debug: Update check error:', error);
        
        // Restore links
        links.forEach(link => {
            link.innerHTML = 'Check for updates';
            link.style.pointerEvents = 'auto';
        });
        
        alert('Failed to check for updates. Please try again.');
    });
};

console.log('CCS Debug: Updater module loaded');
