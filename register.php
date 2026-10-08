<?php
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    redirectTo(getRoleDashboardPath(getUserRole()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Register as Resident</title>
    <link rel="stylesheet" href="style.css" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <main class="page-container register-page">
        <section class="auth-card register-card">
            <div class="register-brand">
                <div class="brand-logo">
                    <span class="brand-logo-fallback" aria-hidden="true">B</span>
                </div>
                <span class="register-brand-name">BalangayLog</span>
            </div>

            <div class="auth-header">
                <h2>Register as Resident</h2>
                <p>Submit your profile for verification by the System Administrator.</p>
            </div>

            <div id="errorBanner" class="error-banner" style="display: none;"></div>

            <form id="registerForm" enctype="multipart/form-data">
                <div class="register-grid">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" required />
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" required />
                    </div>

                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" required />
                    </div>

                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="text" id="contact_number" name="contact_number" required />
                    </div>

                    <div class="form-group form-group--wide">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" required />
                    </div>

                    <div class="form-group">
                        <label for="purok">Purok</label>
                        <select id="purok" name="purok" required>
                            <option value="">Choose your Purok</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autocomplete="off" />
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="password" name="password" autocomplete="new-password" required />
                            <button type="button" class="eye-btn" data-password-toggle="password" data-field-name="password" aria-controls="password" aria-label="Show password" aria-pressed="false">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required />
                            <button type="button" class="eye-btn" data-password-toggle="confirm_password" data-field-name="confirm password" aria-controls="confirm_password" aria-label="Show confirm password" aria-pressed="false">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group form-group--wide">
                        <label for="proof_of_residency">Proof of Residency / Valid ID</label>
                        <input type="file" id="proof_of_residency" name="proof_of_residency" accept=".jpg,.jpeg,.png,.pdf" />
                    </div>
                </div>

                <button type="submit" class="primary-btn" id="registerBtn">Register</button>
                <p class="register-footer">
                    Already have an account? <a href="index.html">Log In</a>
                </p>
            </form>
        </section>
    </main>

    <script>
        const registerForm = document.getElementById('registerForm');
        const registerBtn = document.getElementById('registerBtn');
        const errorBanner = document.getElementById('errorBanner');

        document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const passwordInput = document.getElementById(toggle.dataset.passwordToggle);
                const isVisible = passwordInput.type === 'text';
                passwordInput.type = isVisible ? 'password' : 'text';
                toggle.setAttribute('aria-pressed', String(!isVisible));
                toggle.setAttribute(
                    'aria-label',
                    `${isVisible ? 'Show' : 'Hide'} ${toggle.dataset.fieldName}`
                );
            });
        });

        function showError(message) {
            errorBanner.textContent = message;
            errorBanner.style.display = 'block';
        }

        fetch('api/get_system_parameters.php')
            .then((response) => response.json())
            .then((result) => {
                if (!result.success) return;
                const purokSelect = document.getElementById('purok');
                result.puroks.forEach((purok) => purokSelect.add(new Option(purok, purok)));
            })
            .catch(() => showError('Unable to load the Purok list. Please refresh the page.'));

        if (registerForm) {
            registerForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                errorBanner.style.display = 'none';
                errorBanner.textContent = '';

                const payload = {
                    first_name: document.getElementById('first_name').value.trim(),
                    last_name: document.getElementById('last_name').value.trim(),
                    date_of_birth: document.getElementById('date_of_birth').value,
                    address: document.getElementById('address').value.trim(),
                    purok: document.getElementById('purok').value.trim(),
                    contact_number: document.getElementById('contact_number').value.trim(),
                    username: document.getElementById('username').value.trim(),
                    password: document.getElementById('password').value,
                    confirm_password: document.getElementById('confirm_password').value,
                    proof_of_residency: document.getElementById('proof_of_residency').files[0]?.name || ''
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

                if (requiredFields.some((value) => value === '')) {
                    showError('Please complete all required fields.');
                    return;
                }

                if (payload.password.length < 6) {
                    showError('Password must be at least 6 characters long.');
                    return;
                }

                if (payload.password !== payload.confirm_password) {
                    showError('Passwords do not match.');
                    return;
                }

                registerBtn.disabled = true;
                registerBtn.textContent = 'Submitting...';

                try {
                    const response = await fetch('api/signup.php', {
                        method: 'POST',
                        body: new FormData(registerForm)
                    });

                    const result = await response.json();

                    if (result.success) {
                        alert(result.message || 'Registration submitted.');
                        window.location.href = 'index.html';
                        return;
                    }

                    showError(result.message || 'Resident registration failed.');
                } catch (error) {
                    showError('Unable to submit your registration right now. Please try again.');
                } finally {
                    registerBtn.disabled = false;
                    registerBtn.textContent = 'Register';
                }
            });
        }
    </script>
</body>
</html>
