/**
 * Enhanced Online Detector
 * Detects internet connectivity by pinging Google
 */
class OnlineDetector {
    constructor(options = {}) {
        this.options = {
            checkUrl: 'https://www.google.com/generate_204',
            checkInterval: 5000,
            timeout: 3000,
            popupDuration: 7000,
            popupCooldown: 60000,
            ...options
        };
        
        this.isOnline = null;
        this.lastPopupTime = 0;
        this.popupTimeout = null;
        this.detectorButton = null;
        this.detailsPanel = null;
        this.headerBadge = null;
        this.subscriptionBadge = null;
        
        this.init();
    }
    
    init() {
        // Create popup element if it doesn't exist
        if (!document.querySelector('.connection-status-popup')) {
            this.createPopupElement();
        }
        
        // Create detector button if it doesn't exist
        this.detectorButton = this.createDetectorButton();
        
        // Initial check
        this.checkConnection();
        
        // Set interval for periodic checks
        setInterval(() => this.checkConnection(), this.options.checkInterval);
        
        // Add page visibility change listener to check when page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.checkConnection();
            }
        });
    }
    
    createPopupElement() {
        const popup = document.createElement('div');
        popup.className = 'connection-status-popup';
        
        const icon = document.createElement('i');
        icon.className = 'connection-status-popup-icon fas';
        
        const text = document.createElement('div');
        text.className = 'connection-status-popup-text';
        
        popup.appendChild(icon);
        popup.appendChild(text);
        document.body.appendChild(popup);
    }
    
    createDetectorButton() {
        // Check if button already exists
        let button = document.querySelector('.online-detector-button');
        
        if (!button) {
            button = document.createElement('div');
            button.className = 'online-detector-button checking';
            document.body.appendChild(button);
            
            // Add click event to toggle details panel
            button.addEventListener('click', () => {
                this.toggleDetailsPanel();
            });
        }
        
        return button;
    }
    
    toggleDetailsPanel() {
        // Check if panel exists, create if not
        if (!this.detailsPanel) {
            this.detailsPanel = document.createElement('div');
            this.detailsPanel.className = 'online-detector-details';
            document.body.appendChild(this.detailsPanel);
            
            // Create panel content
            this.updateDetailsPanel();
        }
        
        // Toggle panel visibility
        this.detailsPanel.classList.toggle('show');
        
        // Update content if showing
        if (this.detailsPanel.classList.contains('show')) {
            this.updateDetailsPanel();
        }
    }
    
    updateDetailsPanel() {
        if (!this.detailsPanel) return;
        
        const isOnline = this.isOnline;
        
        // Create header with close button
        const header = document.createElement('div');
        header.className = 'details-header';
        
        const headerText = document.createElement('span');
        headerText.innerHTML = `<i class="fas fa-${isOnline ? 'wifi' : 'wifi-slash'}"></i> ${isOnline ? 'Connected' : 'Disconnected'}`;
        
        const closeButton = document.createElement('span');
        closeButton.className = 'details-close';
        closeButton.innerHTML = '&times;';
        closeButton.addEventListener('click', (e) => {
            e.stopPropagation();
            this.detailsPanel.classList.remove('show');
        });
        
        header.appendChild(headerText);
        header.appendChild(closeButton);
        
        // Create content
        const content = document.createElement('div');
        content.className = 'details-content';
        
        if (isOnline) {
            content.innerHTML = `
                <p>You are connected to the internet.</p>
                <p>Last checked: ${new Date().toLocaleTimeString()}</p>
                <button class="details-button" id="check-connection-btn">Check Again</button>
            `;
        } else {
            content.innerHTML = `
                <p>You are currently offline. Please check your internet connection.</p>
                <p>Last checked: ${new Date().toLocaleTimeString()}</p>
                <button class="details-button" id="check-connection-btn">Check Again</button>
            `;
        }
        
        // Clear and append new content
        this.detailsPanel.innerHTML = '';
        this.detailsPanel.appendChild(header);
        this.detailsPanel.appendChild(content);
        
        // Add event listener to check button
        const checkButton = this.detailsPanel.querySelector('#check-connection-btn');
        if (checkButton) {
            checkButton.addEventListener('click', () => {
                checkButton.textContent = 'Checking...';
                checkButton.disabled = true;
                
                this.checkConnection(true).then(() => {
                    this.updateDetailsPanel();
                });
            });
        }
    }
    
    async checkConnection(userInitiated = false) {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.options.timeout);
            
            const startTime = Date.now();
            const response = await fetch(this.options.checkUrl, {
                method: 'HEAD',
                mode: 'no-cors',
                cache: 'no-store',
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            const endTime = Date.now();
            const responseTime = endTime - startTime;
            
            const wasOnline = this.isOnline;
            this.isOnline = true;
            
            // Update detector button
            this.updateDetectorButton();
            
            // Update header badge if exists
            if (this.headerBadge) {
                this.updateHeaderBadge();
            }
            
            // Show popup if status changed or user initiated
            if (wasOnline === false || userInitiated) {
                this.showPopup(true, responseTime);
            }
            
            return true;
            
        } catch (error) {
            const wasOnline = this.isOnline;
            this.isOnline = false;
            
            // Update detector button
            this.updateDetectorButton();
            
            // Update header badge if exists
            if (this.headerBadge) {
                this.updateHeaderBadge();
            }
            
            // Show popup if status changed or user initiated
            if (wasOnline === true || userInitiated) {
                this.showPopup(false);
            }
            
            return false;
        }
    }
    
    updateDetectorButton() {
        if (!this.detectorButton) return;
        
        // Remove all status classes
        this.detectorButton.classList.remove('online', 'offline', 'checking');
        
        // Add current status class
        if (this.isOnline === true) {
            this.detectorButton.classList.add('online');
            this.detectorButton.title = 'Connected to the internet';
        } else if (this.isOnline === false) {
            this.detectorButton.classList.add('offline');
            this.detectorButton.title = 'Not connected to the internet';
        } else {
            this.detectorButton.classList.add('checking');
            this.detectorButton.title = 'Checking connection...';
        }
        
        // Add animation if status changed
        this.detectorButton.classList.add('status-changed');
        setTimeout(() => this.detectorButton.classList.remove('status-changed'), 600);
    }
    
    showPopup(isOnline, responseTime = null) {
        // Don't show popups too frequently unless status is critical
        if (!isOnline && Date.now() - this.lastPopupTime < this.options.popupCooldown) {
            return;
        }
        
        this.lastPopupTime = Date.now();
        
        const popup = document.querySelector('.connection-status-popup');
        if (!popup) return;
        
        // Clear any existing popup timeout
        if (this.popupTimeout) {
            clearTimeout(this.popupTimeout);
        }
        
        // Set icon based on status
        const icon = popup.querySelector('.connection-status-popup-icon');
        if (icon) {
            icon.className = `connection-status-popup-icon fas fa-${isOnline ? 'wifi' : 'wifi-slash'}`;
        }
        
        // Set message
        const text = popup.querySelector('.connection-status-popup-text');
        if (text) {
            if (isOnline) {
                text.textContent = responseTime ? 
                    `Connected to the internet (${responseTime}ms)` : 
                    'Connected to the internet';
            } else {
                text.textContent = 'You are offline. Please check your internet connection.';
            }
        }
        
        // Set popup class based on status
        popup.className = 'connection-status-popup';
        popup.classList.add(isOnline ? 'online' : 'offline');
        
        // Show popup
        popup.classList.add('show');
        
        // Hide popup after duration
        this.popupTimeout = setTimeout(() => {
            popup.classList.remove('show');
        }, this.options.popupDuration);
    }
    
    replaceHeaderBadge(selector) {
        const badge = document.querySelector(selector);
        if (!badge) return null;
        
        // Store reference to badge
        this.headerBadge = badge;
        
        // Make sure it has the right structure
        if (!badge.querySelector('.connection-status-text')) {
            const icon = badge.querySelector('i') || document.createElement('i');
            icon.className = 'fas fa-circle';
            
            const text = document.createElement('span');
            text.className = 'connection-status-text';
            text.textContent = 'Checking...';
            
            // Clear and rebuild badge
            badge.innerHTML = '';
            badge.appendChild(icon);
            badge.appendChild(text);
        }
        
        // Add tooltip
        if (!badge.querySelector('.status-tooltip')) {
            const tooltip = document.createElement('span');
            tooltip.className = 'status-tooltip';
            tooltip.textContent = 'Checking connection status...';
            badge.appendChild(tooltip);
        }
        
        // Update badge
        this.updateHeaderBadge();
        
        // Add click handler for manual check
        badge.addEventListener('click', () => this.checkConnection(true));
        
        return badge;
    }
    
    updateHeaderBadge() {
        if (!this.headerBadge) return;
        
        const statusText = this.headerBadge.querySelector('.connection-status-text');
        const tooltip = this.headerBadge.querySelector('.status-tooltip');
        
        if (!statusText || !tooltip) return;
        
        // Remove all status classes
        this.headerBadge.classList.remove('online', 'offline', 'checking');
        
        // Update based on current status
        if (this.isOnline === true) {
            this.headerBadge.classList.add('online');
            statusText.textContent = 'Online';
            tooltip.textContent = 'You are connected to the internet';
        } else if (this.isOnline === false) {
            this.headerBadge.classList.add('offline');
            statusText.textContent = 'Offline';
            tooltip.textContent = 'You are not connected to the internet';
        } else {
            this.headerBadge.classList.add('checking');
            statusText.textContent = 'Checking...';
            tooltip.textContent = 'Checking connection status...';
        }
       
	   
        this.headerBadge.classList.add('status-changed');
        setTimeout(() => this.headerBadge.classList.remove('status-changed'), 600);
    }
}