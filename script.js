// ==========================================
// 1. STRICT SESSION VALIDATION HELPER
// ==========================================
function hasValidSession() {
    const token = sessionStorage.getItem('authToken');
    const user = sessionStorage.getItem('currentUser');
    
    if (!token || !user) return false;
    if (token === 'undefined' || token === 'null') return false;
    if (user === 'undefined' || user === 'null') return false;
    
    try {
        const parsed = JSON.parse(user);
        return Boolean(parsed && typeof parsed === 'object');
    } catch (e) {
        return false;
    }
}

// ==========================================
// 2. BFCACHE ROUTE GUARD
// ==========================================
window.addEventListener('pageshow', function () {
    if (hasValidSession()) {
        window.location.replace('dashboard.html');
    }
});

document.addEventListener('DOMContentLoaded', async () => {
    if (hasValidSession()) {
        window.location.replace('dashboard.html');
        return;
    }

    const loginForm = document.getElementById('loginForm');
    const setupForm = document.getElementById('setupForm');
    const setupBtn = document.getElementById('setupBtn');
    const errorBanner = document.getElementById('errorBanner');
    const headerTitle = document.querySelector('.auth-header h2');
    const headerText = document.querySelector('.auth-header p');

    try {
        const response = await fetch('api/check_setup.php', { cache: 'no-store' });
        const data = await response.json();

        if (data && data.setup_required) {
            if (loginForm) loginForm.style.display = 'none';
            if (setupForm) setupForm.style.display = 'block';
            if (headerTitle) headerTitle.textContent = 'Initial System Setup';
            if (headerText) headerText.textContent = 'Create the first System Administrator account.';
        }
    } catch (error) {
        // Ignore and show the normal login form if setup check fails.
    }

    // Element selections
    const loginBtn = document.getElementById('loginBtn');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const forgotPasswordLink = document.getElementById('forgotPasswordLink');

    // Password Visibility Toggle
    if (togglePasswordBtn && passwordInput) {
        togglePasswordBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            togglePasswordBtn.style.color = isPassword ? '#2563eb' : '#94a3b8';
        });
    }

    // Real-Time Stats Fetcher (Polls every 5s)
    async function fetchRealtimeStats() {
        try {
            const response = await fetch('api/stats.php');
            if (response.ok) {
                const stats = await response.json();
                const activeEl = document.getElementById('activeCases');
                const resolvedEl = document.getElementById('resolvedMonth');
                const residentsEl = document.getElementById('residentsServed');
                const avgEl = document.getElementById('avgResolution');

                if (activeEl) activeEl.innerText = stats.activeCases ?? 0;
                if (resolvedEl) resolvedEl.innerText = stats.resolvedMonth ?? 0;
                if (residentsEl) residentsEl.innerText = Number(stats.residentsServed ?? 0).toLocaleString();
                if (avgEl) avgEl.innerText = `${stats.avgResolution ?? 0} days`;
            }
        } catch (err) {
            // Silently retain values
        }
    }

    fetchRealtimeStats();
    setInterval(fetchRealtimeStats, 5000);

    // Form Submission & Authentication
    if (setupForm) {
        setupForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const firstName = document.getElementById('setup_first_name')?.value.trim() || '';
            const lastName = document.getElementById('setup_last_name')?.value.trim() || '';
            const username = document.getElementById('setup_username')?.value.trim() || '';
            const password = document.getElementById('setup_password')?.value || '';
            const confirmPassword = document.getElementById('setup_confirm_password')?.value || '';

            if (!firstName || !lastName || !username || !password || !confirmPassword) {
                if (errorBanner) {
                    errorBanner.textContent = 'Please complete every field.';
                    errorBanner.style.display = 'block';
                }
                return;
            }

            if (password.length < 6) {
                if (errorBanner) {
                    errorBanner.textContent = 'Password must be at least 6 characters long.';
                    errorBanner.style.display = 'block';
                }
                return;
            }

            if (password !== confirmPassword) {
                if (errorBanner) {
                    errorBanner.textContent = 'Passwords do not match.';
                    errorBanner.style.display = 'block';
                }
                return;
            }

            if (setupBtn) {
                setupBtn.disabled = true;
                setupBtn.textContent = 'Creating Account...';
            }

            try {
                const response = await fetch('api/setup_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        first_name: firstName,
                        last_name: lastName,
                        username,
                        password,
                        confirm_password: confirmPassword
                    })
                });

                const result = await response.json();
                if (result.success) {
                    alert(result.message || 'System administrator account created successfully.');
                    window.location.href = 'index.html';
                    return;
                }

                if (errorBanner) {
                    errorBanner.textContent = result.message || 'Setup failed.';
                    errorBanner.style.display = 'block';
                }
            } catch (error) {
                if (errorBanner) {
                    errorBanner.textContent = 'Unable to create the administrator account.';
                    errorBanner.style.display = 'block';
                }
            } finally {
                if (setupBtn) {
                    setupBtn.disabled = false;
                    setupBtn.textContent = 'Create Administrator';
                }
            }
        });
    }

    if (loginForm) {
        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (errorBanner) {
                errorBanner.style.display = 'none';
                errorBanner.innerText = '';
            }

            const username = usernameInput.value.trim();
            const password = passwordInput.value;

            if (password.length < 6) {
                if (errorBanner) {
                    errorBanner.innerText = 'Password must be at least 6 characters.';
                    errorBanner.style.display = 'block';
                }
                return;
            }

            loginBtn.disabled = true;
            loginBtn.innerText = 'Signing in...';

            try {
                const response = await fetch('api/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });

                const data = await response.json();

               if (response.ok && data.success) {
                    sessionStorage.setItem('authToken', data.token);
                    sessionStorage.setItem('currentUser', JSON.stringify(data.user));
                    const redirectPath = data.redirect || 'dashboard.html';
                    window.location.replace(redirectPath);
                } else {
                    if (errorBanner) {
                        errorBanner.innerText = data.message || 'Invalid credentials.';
                        errorBanner.style.display = 'block';
                    }
                }
            } catch (error) {
                console.error('API Error:', error);
                if (errorBanner) {
                    errorBanner.innerText = 'Unable to connect to server. Ensure Apache is running.';
                    errorBanner.style.display = 'block';
                }
            } finally {
                loginBtn.disabled = false;
                loginBtn.innerText = 'Sign In';
            }
        });
    }

    // Forgot Password Flow
    const forgotModal = document.getElementById('forgotModal');
    const closeForgotModal = document.getElementById('closeForgotModal');
    const dismissForgotBtn = document.getElementById('dismissForgotBtn');
    const forgotModalUserDisplay = document.getElementById('forgotModalUserDisplay');

    function closeForgotModalHandler() {
        if (forgotModal) forgotModal.style.display = 'none';
    }

    if (forgotPasswordLink) {
        forgotPasswordLink.addEventListener('click', async (e) => {
            e.preventDefault();

            if (errorBanner) {
                errorBanner.style.display = 'none';
                errorBanner.innerText = '';
            }

            const username = usernameInput ? usernameInput.value.trim() : '';

            if (!username) {
                if (errorBanner) {
                    errorBanner.innerText = 'Please enter your username or email above first.';
                    errorBanner.style.display = 'block';
                }
                if (usernameInput) usernameInput.focus();
                return;
            }

            try {
                const response = await fetch('api/check_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username })
                });

                const data = await response.json();

                if (data.success && data.exists) {
                    if (forgotModalUserDisplay) {
                        forgotModalUserDisplay.innerText = data.name || username;
                    }
                    if (forgotModal) forgotModal.style.display = 'flex';
                } else {
                    if (errorBanner) {
                        errorBanner.innerText = data.message || 'Account not registered.';
                        errorBanner.style.display = 'block';
                    }
                    if (usernameInput) usernameInput.focus();
                }
            } catch (err) {
                if (errorBanner) {
                    errorBanner.innerText = 'Unable to verify account. Please try again.';
                    errorBanner.style.display = 'block';
                }
            }
        });
    }

    if (closeForgotModal) closeForgotModal.addEventListener('click', closeForgotModalHandler);
    if (dismissForgotBtn) dismissForgotBtn.addEventListener('click', closeForgotModalHandler);

    window.addEventListener('click', (e) => {
        if (e.target === forgotModal) closeForgotModalHandler();
    });
});

// Universal Theme Toggle
const themeBtn = document.getElementById('themeToggleBtn');
if (themeBtn) {
    themeBtn.addEventListener('click', () => {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const newTheme = isDark ? 'light' : 'dark';
        if (newTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        localStorage.setItem('balangay_theme', newTheme);
        themeBtn.textContent = newTheme === 'dark' ? '☀️ Light Mode' : '🌙 Dark Mode';
    });
}

// Universal Profile Update Handler
document.getElementById('profileForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const res = await fetch('api/profile.php', { method: 'POST', body: formData });
    const data = await res.json();
    alert(data.message);
    if (data.success) location.reload();
});

// Universal Password Update Handler
document.getElementById('passwordForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const res = await fetch('api/profile.php', { method: 'POST', body: formData });
    const data = await res.json();
    alert(data.message);
    if (data.success) e.currentTarget.reset();
});