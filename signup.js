const signupForm = document.getElementById('signupForm');
const signupBtn = document.getElementById('signupBtn');
const errorBanner = document.getElementById('errorBanner');
const passwordInput = document.getElementById('password');
const confirmPasswordInput = document.getElementById('confirmPassword');
const togglePasswordBtn = document.getElementById('togglePassword');

if (togglePasswordBtn && passwordInput && confirmPasswordInput) {
    togglePasswordBtn.addEventListener('click', function () {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        const newType = isPassword ? 'text' : 'password';

        passwordInput.setAttribute('type', newType);
        confirmPasswordInput.setAttribute('type', newType);
        togglePasswordBtn.innerText = isPassword ? 'Hide' : 'Show';
    });
}

if (signupForm) {
    signupForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (errorBanner) {
            errorBanner.style.display = 'none';
            errorBanner.innerText = '';
        }

        const payload = {
            first_name: document.getElementById('first_name')?.value.trim() || '',
            last_name: document.getElementById('last_name')?.value.trim() || '',
            date_of_birth: document.getElementById('date_of_birth')?.value || '',
            address: document.getElementById('address')?.value.trim() || '',
            purok: document.getElementById('purok')?.value.trim() || '',
            contact_number: document.getElementById('contact_number')?.value.trim() || '',
            username: document.getElementById('username')?.value.trim() || '',
            password: passwordInput?.value || '',
            confirm_password: confirmPasswordInput?.value || '',
            proof_of_residency: document.getElementById('proof_of_residency')?.value || ''
        };

        const requiredFields = [
            payload.first_name,
            payload.last_name,
            payload.date_of_birth,
            payload.address,
            payload.purok,
            payload.contact_number,
            payload.username,
            payload.password,
            payload.confirm_password
        ];

        if (requiredFields.some(value => value === '')) {
            showError('Please complete all required resident registration fields.');
            return;
        }

        if (payload.password.length < 6) {
            showError('Password must be at least 6 characters.');
            return;
        }

        if (payload.password !== payload.confirm_password) {
            showError('Passwords do not match.');
            return;
        }

        signupBtn.disabled = true;
        signupBtn.innerText = 'Registering...';

        try {
            const response = await fetch('api/signup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (result.success) {
                alert(result.message || 'Registration submitted successfully.');
                window.location.href = 'index.html';
                return;
            }

            showError(result.message || 'Registration failed.');
        } catch (error) {
            console.error('Sign-up error:', error);
            showError('Connection failed. Make sure Apache and MySQL are running in XAMPP.');
        } finally {
            signupBtn.disabled = false;
            signupBtn.innerText = 'Register';
        }
    });
}

function showError(msg) {
    if (!errorBanner) return;
    errorBanner.innerText = msg;
    errorBanner.style.display = 'block';
    errorBanner.style.color = '#b91c1c';
    errorBanner.style.background = '#fee2e2';
    errorBanner.style.padding = '10px';
    errorBanner.style.borderRadius = '6px';
    errorBanner.style.marginBottom = '14px';
}