// Client-side validation for login form
const loginForm = document.getElementById('loginForm');
const emailInput = document.getElementById('email');
const passwordInput = document.getElementById('password');

loginForm.addEventListener('submit', function (event) {
    let isValid = true;

    // Clear previous error messages
    const errorMessages = document.querySelectorAll('.error');
    errorMessages.forEach(error => error.remove());

    // Validate email
    if (emailInput.value.trim() === '') {
        const error = document.createElement('span');
        error.className = 'error';
        error.textContent = 'Username is required';
        emailInput.parentElement.appendChild(error);
        isValid = false;
    }

    // Validate password
    if (passwordInput.value.length < 8 || passwordInput.value.length > 15) {
        const error = document.createElement('span');
        error.className = 'error';
        error.textContent = 'Password must be 8–15 characters';
        passwordInput.parentElement.appendChild(error);
        isValid = false;
    }

    if (!isValid) {
        event.preventDefault();
    }
});