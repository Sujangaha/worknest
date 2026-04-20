/**
 * Login Form Handler
 * Validates form and authenticates with backend
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('Login page loaded successfully');
    
    const loginForm = document.getElementById('loginForm');
    
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get form values
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            const remember = document.getElementById('remember').checked;
            
            // Validate form
            if (!email || !password) {
                showError('Please fill in all fields');
                return;
            }
            
            // Basic email validation
            if (!isValidEmail(email)) {
                showError('Please enter a valid email address');
                return;
            }
            
            // Submit login to backend
            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('email', email);
            formData.append('password', password);
            
            fetch('../dashboard/backend/auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('Login successful!');
                    // Redirect based on role
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 500);
                } else {
                    showError(data.message || 'Login failed');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('An error occurred during login');
            });
        });
    }

    // Add button hover effects
    const loginButton = document.querySelector('.btn-login');
    if (loginButton) {
        loginButton.addEventListener('mouseenter', function() {
            this.style.cursor = 'pointer';
        });
    }

    // Add form input focus effects
    const inputs = document.querySelectorAll('input[type="email"], input[type="password"]');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transition = 'all 0.3s ease';
        });
    });
});

/**
 * Validate email format
 */
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

/**
 * Show error message
 */
function showError(message) {
    alert('❌ ' + message);
}

/**
 * Show success message
 */
function showSuccess(message) {
    console.log('✓ ' + message);
    // Could be enhanced with toast notification
}
