/**
 * Subscription Status Handler
 * Checks and displays subscription status
 */
class SubscriptionStatus {
    constructor(options = {}) {
        this.options = {
            checkInterval: 1000, // Check every 1 second
            popupDuration: 5000,  // Popup duration in ms
            popupCooldown: 60000, // 1 minute between popups
            ...options
        };
        
        this.subscriptionStatus = "checking";
        this.subscriptionData = {};
        this.lastPopupTime = 0;
        this.popupTimeout = null;
        this.lastCheckTime = 0;
        this.badge = null;
        
        this.init();
    }
    
    init() {
        // Initial check
        this.checkSubscription();
        
        // Set interval for periodic checks
        setInterval(() => this.checkSubscription(), this.options.checkInterval);
    }
    
    addSubscriptionBadge(selector) {
        const adjacentElement = document.querySelector(selector);
        if (!adjacentElement) return null;
        
        // Create badge if it doesn't exist
        let badge = document.getElementById('subscription-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.id = 'subscription-badge';
            badge.className = 'badge checking';
            badge.innerHTML = `
                <i class="fas fa-ticket-alt"></i>
                <span class="subscription-status-text">Checking...</span>
                <span class="status-tooltip">Checking subscription status...</span>
                <div class="data-usage-bar"></div>
            `;
            
            // Insert after the adjacent element
            adjacentElement.parentNode.insertBefore(badge, adjacentElement.nextSibling);
            
            // Add click handler for manual check
            badge.addEventListener('click', () => this.checkSubscription(true));
        }
        
        this.badge = badge;
        return badge;
    }
    
    async checkSubscription(userInitiated = false) {
        // Don't check too frequently unless user initiated
        if (!userInitiated && Date.now() - this.lastCheckTime < 1000) {
            return;
        }
        
        this.lastCheckTime = Date.now();
        
        if (!this.badge) {
            this.badge = document.getElementById('subscription-badge');
            if (!this.badge) return;
        }
        
        const statusText = this.badge.querySelector('.subscription-status-text');
        const tooltip = this.badge.querySelector('.status-tooltip');
        const dataBar = this.badge.querySelector('.data-usage-bar');
        
        if (!statusText || !tooltip || !dataBar) return;
        
        // Set to checking state if not user initiated
        if (!userInitiated) {
            statusText.textContent = "Checking...";
            tooltip.textContent = "Checking subscription status...";
        }
        
        try {
            const response = await fetch('../check-subscription.php');
            if (!response.ok) {
                throw new Error('Failed to fetch subscription data');
            }
            
            const data = await response.json();
            
            // Store previous data for comparison
            const previousData = JSON.stringify(this.subscriptionData);
            this.subscriptionData = data;
            const dataChanged = previousData !== JSON.stringify(data);
            
            // Determine subscription status
            let status, message, icon;
            
            if (!data.hasSubscription) {
                status = "none";
                message = "No subscription";
                icon = "fas fa-ticket-alt";
            } else if (data.isExpired) {
                status = "expired";
                message = "Expired";
                icon = "fas fa-calendar-times";
            } else if (data.isDataDepleted) {
                status = "depleted";
                message = "Data depleted";
                icon = "fas fa-tachometer-alt";
            } else {
                status = "active";
                message = "Active plan";
                icon = "fas fa-ticket-alt";
            }
            
            // Update badge
            const previousStatus = this.subscriptionStatus;
            this.subscriptionStatus = status;
            
            this.badge.className = `badge ${status}`;
            this.badge.querySelector('i').className = icon;
            statusText.textContent = message;
            
            // Update data usage bar if applicable
            if (data.type === 'quota' && data.mbQuota && data.mbUsage) {
                const usedData = parseFloat(data.mbUsage || 0);
                const totalData = parseFloat(data.mbQuota || 0);
                const usagePercentage = Math.min(100, (usedData / totalData) * 100);
                
                dataBar.style.width = `${usagePercentage}%`;
                dataBar.style.display = 'block';
            } else {
                dataBar.style.display = 'none';
            }
            
            // Update tooltip
            if (status === "active") {
                let tooltipText = "";
                if (data.type === 'quota') {
                    const usedData = parseFloat(data.mbUsage || 0);
                    const totalData = parseFloat(data.mbQuota || 0);
                    const remainingData = Math.max(0, totalData - usedData);
                    const usagePercentage = Math.min(100, (usedData / totalData) * 100);
                    
                    tooltipText = `${remainingData.toFixed(2)} MB remaining of ${totalData} MB (${usagePercentage.toFixed(1)}% used)`;
                } else {
                    const expiryDate = data.expiryTime ? new Date(data.expiryTime) : null;
                    if (expiryDate) {
                        const now = new Date();
                        const daysRemaining = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
                        
                        tooltipText = `Expires on ${expiryDate.toLocaleDateString()} (${daysRemaining} days remaining)`;
                    }
                }
                
                tooltip.textContent = tooltipText;
            } else if (status === "expired") {
                tooltip.textContent = "Your subscription has expired. Please renew.";
            } else if (status === "depleted") {
                tooltip.textContent = "You've used all your data. Please purchase more.";
            } else {
                tooltip.textContent = "You don't have an active subscription. Purchase a plan to get started.";
            }
            
            // Add animation if status changed
            if (previousStatus !== status) {
                this.badge.classList.add('status-changed');
                setTimeout(() => this.badge.classList.remove('status-changed'), 600);
            }
            
            // Show popup if status changed OR if data changed significantly
            if (userInitiated || previousStatus !== status || dataChanged) {
                this.showSubscriptionPopup(status, data);
            }
            
        } catch (error) {
            console.error('Error checking subscription:', error);
            statusText.textContent = "Unknown";
            tooltip.textContent = "Could not determine subscription status";
            this.badge.className = "badge";
        }
    }
    
    showSubscriptionPopup(status, data = {}) {
        // Don't show popups too frequently unless user initiated or status is critical
        if (status !== 'expired' && status !== 'depleted' && 
            Date.now() - this.lastPopupTime < this.options.popupCooldown) {
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
            if (status === "expired") {
                icon.className = "connection-status-popup-icon fas fa-calendar-times";
            } else if (status === "depleted") {
                icon.className = "connection-status-popup-icon fas fa-tachometer-alt";
            } else if (status === "none") {
                icon.className = "connection-status-popup-icon fas fa-ticket-alt";
            } else {
                icon.className = "connection-status-popup-icon fas fa-check-circle";
            }
        }
        
        // Set message
        const text = popup.querySelector('.connection-status-popup-text');
        if (text) {
            let message = "";
            
            if (status === "expired") {
                message = "Your subscription has expired. Please renew to continue using the service.";
            } else if (status === "depleted") {
                message = "You've used all your data. Please purchase more data to continue.";
            } else if (status === "none") {
                message = "You don't have an active subscription. Purchase a plan to get started.";
            } else if (status === "active") {
                if (data.type === 'quota') {
                    const usedData = parseFloat(data.mbUsage || 0);
                    const totalData = parseFloat(data.mbQuota || 0);
                    const remainingData = Math.max(0, totalData - usedData);
                    const usagePercentage = Math.min(100, (usedData / totalData) * 100);
                    
                    message = `Active plan: ${remainingData.toFixed(2)} MB remaining of ${totalData} MB (${usagePercentage.toFixed(1)}% used)`;
                } else {
                    const expiryDate = data.expiryTime ? new Date(data.expiryTime) : null;
                    if (expiryDate) {
                        const now = new Date();
                        const daysRemaining = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
                        
                        message = `Active plan: Expires on ${expiryDate.toLocaleDateString()} (${daysRemaining} days remaining)`;
                    } else {
                        message = "Active plan";
                    }
                }
            }
            
            text.textContent = message;
        }
        
        // Set popup class based on status
        popup.className = 'connection-status-popup';
        popup.classList.add(status);
        
        // Show popup
        popup.classList.add('show');
        
        // Hide popup after duration
        this.popupTimeout = setTimeout(() => {
            popup.classList.remove('show');
        }, this.options.popupDuration);
    }
}