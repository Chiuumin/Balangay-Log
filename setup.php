<?php
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    redirectTo(getRoleDashboardPath(getUserRole()));
}

if (hasSystemAdmin()) {
    redirectTo('index.html');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Initial System Setup</title>
    <link rel="stylesheet" href="style.css" />
</head>
<body>
    <div class="page-container" style="min-height: 100vh; align-items: center; justify-content: center;">
        <div class="auth-card" style="max-width: 560px; width: 100%;">
            <div class="auth-header">
                <h2>Initial System Setup</h2>
                <p>Create the first System Administrator account.</p>
            </div>

            <div id="errorBanner" class="error-banner" style="display: none;"></div>

            <form id="setupForm">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required />
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required />
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autocomplete="off" />
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required />
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required />
                </div>

                <button type="submit" class="primary-btn" id="setupBtn">Create Administrator</button>
            </form>
        </div>
    </div>

    <script>
        const setupForm = document.getElementById('setupForm');
        const setupBtn = document.getElementById('setupBtn');
        const errorBanner = document.getElementById('errorBanner');

        function showError(message) {
            errorBanner.textContent = message;
            errorBanner.style.display = 'block';
        }

        if (setupForm) {
            setupForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                errorBanner.style.display = 'none';
                errorBanner.textContent = '';

                const payload = {
                    first_name: document.getElementById('first_name').value.trim(),
                    last_name: document.getElementById('last_name').value.trim(),
                    username: document.getElementById('username').value.trim(),
                    password: document.getElementById('password').value,
                    confirm_password: document.getElementById('confirm_password').value,
                };

                if (!payload.first_name || !payload.last_name || !payload.username || !payload.password || !payload.confirm_password) {
                    showError('Please complete every field.');
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

                setupBtn.disabled = true;
                setupBtn.textContent = 'Creating Account...';

                try {
                    const response = await fetch('api/setup_admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });

                    const result = await response.json();

                    if (result.success) {
                        alert(result.message || 'System administrator account created successfully.');
                        window.location.href = 'index.html';
                        return;
                    }

                    showError(result.message || 'Setup failed.');
                } catch (error) {
                    showError('Unable to create the administrator account. Please check the database connection.');
                } finally {
                    setupBtn.disabled = false;
                    setupBtn.textContent = 'Create Administrator';
                }
            });
        }
    </script>
</body>
</html>
