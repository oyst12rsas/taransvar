document.addEventListener("DOMContentLoaded", () => {
 
  removeExistingAlertHandlers()


  setupFormPersistence()


  setupPasswordToggles()


  setupPhoneValidation()


  setupMpesaValidation()

  setupModalNotifications()

  setupTermsModal()
})


function removeExistingAlertHandlers() {

  window.originalAlert = window.alert
  window.alert = (message) => {
    console.log("Alert suppressed:", message)

  }
}


function setupModalNotifications() {
  // Check URL parameters for error or success messages
  const urlParams = new URLSearchParams(window.location.search)
  const error = urlParams.get("error")
  const success = urlParams.get("success")
  const loginParam = urlParams.get("login")
  const registerParam = urlParams.get("register")
  const recoverParam = urlParams.get("recover")


  const createNotification = (type, message, context = "") => {
    const alertClass = type === "error" ? "alert-danger" : "alert-success"
    const icon = type === "error" ? "fa-exclamation-circle" : "fa-check-circle"

    let contextPrefix = ""
    if (context === "quick") {
      contextPrefix = "<strong>Quick Login:</strong> "
    } else if (context === "account") {
      contextPrefix = "<strong>Account Login:</strong> "
    } else if (context === "register") {
      contextPrefix = "<strong>Registration:</strong> "
    } else if (context === "recover") {
      contextPrefix = "<strong>Password Recovery:</strong> "
    }

    return `
            <div class="alert ${alertClass} alert-dismissible fade show mb-3" role="alert">
                <i class="fas ${icon} me-2"></i> ${contextPrefix}${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `
  }


  if (loginParam && (error || success)) {
    const loginModalElement = document.getElementById("loginModal")
    if (loginModalElement) {
      const loginModal = new bootstrap.Modal(loginModalElement)

      // Determine which tab is active
      const quickLoginTab = document.getElementById("quick-login-tab")
      const accountLoginTab = document.getElementById("account-login-tab")
      let activeTab = "quick" // Default

      if (quickLoginTab && accountLoginTab) {
        if (accountLoginTab.classList.contains("active")) {
          activeTab = "account"
        }
      }


      const notificationArea = document.createElement("div")
      notificationArea.id = "loginNotification"
      notificationArea.className = "notification-area"

      const modalBody = document.querySelector("#loginModal .modal-body")
      if (modalBody) {
        // Remove any existing notification areas
        if (modalBody.querySelector(".notification-area")) {
          modalBody.querySelector(".notification-area").remove()
        }

        modalBody.insertBefore(notificationArea, modalBody.firstChild)

        if (error) {
          notificationArea.innerHTML = createNotification("error", decodeURIComponent(error), activeTab)
        } else if (success) {
          notificationArea.innerHTML = createNotification("success", decodeURIComponent(success), activeTab)
        }

        loginModal.show()
      }
    }
  }

  // Handle
  if (registerParam && (error || success)) {
    const registerModalElement = document.getElementById("registerModal")
    if (registerModalElement) {
      const registerModal = new bootstrap.Modal(registerModalElement)

      const notificationArea = document.createElement("div")
      notificationArea.id = "registerNotification"
      notificationArea.className = "notification-area"

      const modalBody = document.querySelector("#registerModal .modal-body")
      if (modalBody) {
        // Remove any existing notification areas
        if (modalBody.querySelector(".notification-area")) {
          modalBody.querySelector(".notification-area").remove()
        }

        modalBody.insertBefore(notificationArea, modalBody.firstChild)

        if (error) {
          notificationArea.innerHTML = createNotification("error", decodeURIComponent(error), "register")
        } else if (success) {
          notificationArea.innerHTML = createNotification("success", decodeURIComponent(success), "register")
        }

        registerModal.show()
      }
    }
  }

  // Handle recover modal notifications
  if (recoverParam && (error || success)) {
    const recoverModalElement = document.getElementById("recoverModal")
    if (recoverModalElement) {
      const recoverModal = new bootstrap.Modal(recoverModalElement)

      const notificationArea = document.createElement("div")
      notificationArea.id = "recoverNotification"
      notificationArea.className = "notification-area"

      const modalBody = document.querySelector("#recoverModal .modal-body")
      if (modalBody) {
        // Remove any existing notification areas
        if (modalBody.querySelector(".notification-area")) {
          modalBody.querySelector(".notification-area").remove()
        }

        modalBody.insertBefore(notificationArea, modalBody.firstChild)

        if (error) {
          notificationArea.innerHTML = createNotification("error", decodeURIComponent(error), "recover")
        } else if (success) {
          notificationArea.innerHTML = createNotification("success", decodeURIComponent(success), "recover")
        }

        recoverModal.show()
      }
    }
  }
}

// Function to setup form persistence
function setupFormPersistence() {
  // Store form data in sessionStorage when form is submitted
  const forms = document.querySelectorAll("form")
  forms.forEach((form) => {
    form.addEventListener("submit", function () {
      const formData = new FormData(this)
      const formObject = {}

      formData.forEach((value, key) => {
        // Don't store passwords
        if (key !== "password" && key !== "confirm_password") {
          formObject[key] = value
        }
      })

      sessionStorage.setItem(this.id + "_data", JSON.stringify(formObject))
    })

    // Restore form data from sessionStorage
    const storedData = sessionStorage.getItem(form.id + "_data")
    if (storedData) {
      const formObject = JSON.parse(storedData)

      Object.keys(formObject).forEach((key) => {
        const input = form.querySelector(`[name="${key}"]`)
        if (input) {
          if (input.type === "checkbox") {
            input.checked = formObject[key] === "on"
          } else {
            input.value = formObject[key]
          }
        }
      })
    }
  })

  // Clear form data when modal is closed
  document.querySelectorAll(".modal").forEach((modal) => {
    modal.addEventListener("hidden.bs.modal", function () {
      const forms = this.querySelectorAll("form")
      forms.forEach((form) => {
        // Don't clear form data if there's an error
        const urlParams = new URLSearchParams(window.location.search)
        if (!urlParams.get("error")) {
          sessionStorage.removeItem(form.id + "_data")
        }
      })
    })
  })
}


function setupPasswordToggles() {
  const passwordFields = document.querySelectorAll('input[type="password"]')

  passwordFields.forEach((field) => {


    if (field.parentNode.classList.contains("input-group")) {
      return
    }

    const toggleButton = document.createElement("button")
    toggleButton.type = "button"
    toggleButton.className = "btn btn-outline-secondary password-toggle"
    toggleButton.innerHTML = '<i class="fas fa-eye"></i>'
    toggleButton.title = "Show password"

    // Wrap field in input group
    const parent = field.parentNode
    const wrapper = document.createElement("div")
    wrapper.className = "input-group"

    // Insert wrapper before field
    parent.insertBefore(wrapper, field)

    // Move field into wrapper and append toggle button
    wrapper.appendChild(field)
    wrapper.appendChild(toggleButton)

    // Add event listener to toggle button
    toggleButton.addEventListener("click", function () {
      if (field.type === "password") {
        field.type = "text"
        this.innerHTML = '<i class="fas fa-eye-slash"></i>'
        this.title = "Hide password"
      } else {
        field.type = "password"
        this.innerHTML = '<i class="fas fa-eye"></i>'
        this.title = "Show password"
      }
    })
  })
}


function setupPhoneValidation() {
  const phoneFields = document.querySelectorAll('input[type="tel"]')

  phoneFields.forEach((field) => {
    field.addEventListener("input", function () {
 
      let value = this.value.replace(/\D/g, "")

      // Format the phone number
      if (value.startsWith("254")) {
      
        if (value.length > 12) {
          value = value.substring(0, 12)
        }
      } else if (value.startsWith("0")) {
      
        if (value.length > 10) {
          value = value.substring(0, 10)
        }
      } else if (value.length > 0) {
        // If doesn't start with 254 or 0, prepend 0
        value = "0" + value
        if (value.length > 10) {
          value = value.substring(0, 10)
        }
      }

      this.value = value
    })

    field.addEventListener("blur", function () {
      // Validate phone number format
      const value = this.value
      if (value) {
        if (!(value.startsWith("254") && value.length === 12) && !(value.startsWith("0") && value.length === 10)) {
          this.setCustomValidity("Please enter a valid phone number (254XXXXXXXXX or 0XXXXXXXXX)")
        } else {
          this.setCustomValidity("")
        }
      }
    })
  })
}

// Function to setup M-Pesa code validation
function setupMpesaValidation() {
  const mpesaField = document.querySelector("#mpesaCode")

  if (mpesaField) {
    mpesaField.addEventListener("input", function () {
      // Convert to uppercase
      this.value = this.value.toUpperCase()


      const mpesaRegex = /^[A-Z0-9]{10}$/
      if (this.value && !mpesaRegex.test(this.value)) {
        this.setCustomValidity("Please enter a valid M-Pesa receipt code (10 characters)")
      } else {
        this.setCustomValidity("")
      }
    })
  }
}

// Function to setup terms modal
function setupTermsModal() {
  // Create terms modal if it doesn't exist
  if (!document.getElementById("termsModal")) {
    const termsModal = document.createElement("div")
    termsModal.className = "modal fade"
    termsModal.id = "termsModal"
    termsModal.tabIndex = "-1"
    termsModal.setAttribute("aria-hidden", "true")
    termsModal.setAttribute("data-bs-backdrop", "static")

    termsModal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Terms of Service</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body terms-content">
                        <!-- Terms content will be loaded here -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" id="acceptTerms">Accept</button>
                    </div>
                </div>
            </div>
        `

    document.body.appendChild(termsModal)

    // Load terms content
    const termsContent = document.querySelector(".terms-content")
    termsContent.innerHTML = `
            <h2 class="mb-4">Taransvar WiFi Hotspot Terms of Service</h2>
            
            <h3>1. Acceptance of Terms</h3>
            <p>By accessing or using the Taransvar WiFi Hotspot service ("Service"), you agree to be bound by these Terms of Service ("Terms"). If you do not agree to these Terms, you may not use the Service.</p>
            
            <h3>2. Description of Service</h3>
            <p>Taransvar provides WiFi internet access services through its hotspot network. The Service is available to customers who have purchased a valid subscription plan and have successfully authenticated through our portal.</p>
            
            <h3>3. User Accounts</h3>
            <p>3.1. You may access the Service either through a quick login using your phone number and M-Pesa receipt code, or by creating a user account.</p>
            <p>3.2. If you create an account, you are responsible for maintaining the confidentiality of your account information and password.</p>
            <p>3.3. You agree to notify us immediately of any unauthorized use of your account or any other breach of security.</p>
            
            <h3>4. Payment and Billing</h3>
            <p>4.1. The Service is provided on a prepaid basis according to the plan you select.</p>
            <p>4.2. All payments are processed through M-Pesa and are non-refundable.</p>
            <p>4.3. Each M-Pesa receipt code can only be used once to access the Service.</p>
            
            <h3>5. Acceptable Use Policy</h3>
            <p>5.1. You agree to use the Service only for lawful purposes and in accordance with these Terms.</p>
            <p>5.2. Prohibited activities include but are not limited to:</p>
            <ul>
                <li>Violating any applicable laws or regulations</li>
                <li>Infringing on intellectual property rights</li>
                <li>Distributing malware or engaging in hacking activities</li>
                <li>Accessing, transmitting, or distributing illegal content</li>
                <li>Interfering with other users' enjoyment of the Service</li>
                <li>Attempting to gain unauthorized access to any part of the Service</li>
            </ul>
            
            <h3>6. Privacy Policy</h3>
            <p>6.1. Our Privacy Policy, which is incorporated into these Terms by reference, explains how we collect, use, and protect your information.</p>
            <p>6.2. By using the Service, you consent to the collection and use of your information as described in the Privacy Policy.</p>
            
            <h3>7. Limitation of Liability</h3>
            <p>7.1. The Service is provided on an "as is" and "as available" basis without warranties of any kind.</p>
            <p>7.2. Taransvar shall not be liable for any direct, indirect, incidental, special, consequential, or exemplary damages resulting from your use of or inability to use the Service.</p>
            
            <h3>8. Termination</h3>
            <p>8.1. Taransvar reserves the right to suspend or terminate your access to the Service at any time for any reason without notice.</p>
            <p>8.2. Upon termination, your right to use the Service will immediately cease.</p>
            
            <h3>9. Changes to Terms</h3>
            <p>9.1. Taransvar reserves the right to modify these Terms at any time.</p>
            <p>9.2. Continued use of the Service after any such changes constitutes your acceptance of the new Terms.</p>
            
            <h3>10. Contact Information</h3>
            <p>If you have any questions about these Terms, please contact us at support@taransvar.no.</p>
            
            <p class="mt-4"><strong>Last Updated:</strong> March 24, 2025</p>
        `

    // Setup terms link click handler
    document.querySelectorAll('a[href="terms.html"], a[href="terms.php"]').forEach((link) => {
      link.addEventListener("click", (e) => {
        e.preventDefault()

        // Get the current active modal
        const currentModal = document.querySelector(".modal.show")
        let currentModalInstance
        if (currentModal) {
          currentModalInstance = bootstrap.Modal.getInstance(currentModal)
          if (currentModalInstance) {
            currentModalInstance.hide()
          }
        }

 
        const termsModalElement = document.getElementById("termsModal")
        const termsModal = new bootstrap.Modal(termsModalElement)
        termsModal.show()


        document.getElementById("termsModal").style.zIndex = "1060"
      })
    })

    document.getElementById("acceptTerms").addEventListener("click", () => {
      // Check the terms checkbox in the registration form
      const termsCheck = document.getElementById("termsCheck")
      if (termsCheck) {
        termsCheck.checked = true
      }

      // Hide terms modal
      const termsModalElement = document.getElementById("termsModal")
      const termsModalInstance = bootstrap.Modal.getInstance(termsModalElement)
      if (termsModalInstance) {
        termsModalInstance.hide()
      }


      const registerModalElement = document.getElementById("registerModal")
      const registerModal = new bootstrap.Modal(registerModalElement)
      registerModal.show()
    })

 
    document.getElementById("termsModal").addEventListener("hidden.bs.modal", () => {
      const registerModalElement = document.getElementById("registerModal")
      const registerModal = new bootstrap.Modal(registerModalElement)
      registerModal.show()
    })
  }
}

