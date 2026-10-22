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

        let setupRequired = false;

    try {
        const response = await fetch('api/check_setup.php', { cache: 'no-store' });
        const data = await response.json();
        setupRequired = Boolean(data && data.setup_required);
    } catch (error) {
        // Ignore and show the normal login form if setup check fails.
    }

    function showSetupForm() {
        if (loginForm) loginForm.style.display = 'none';
        if (setupForm) setupForm.style.display = 'block';
        if (headerTitle) headerTitle.textContent = 'Initial System Setup';
        if (headerText) headerText.textContent = 'Create the first System Administrator account.';
        document.getElementById('setup_first_name')?.focus();
    }

    if (setupRequired) showSetupForm();

    const initialSetupLink = document.getElementById('initialSetupLink');
    if (initialSetupLink) {
        if (!setupRequired) initialSetupLink.style.display = 'none';
        initialSetupLink.addEventListener('click', (e) => {
            e.preventDefault();
            showSetupForm();
        });
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

    const forgotModal = document.getElementById('forgotModal');
    const closeForgotModal = document.getElementById('closeForgotModal');
    const recoveryStatus = document.getElementById('recoveryStatus');
    const recoveryStartForm = document.getElementById('recoveryStartForm');
    const recoveryVerifyForm = document.getElementById('recoveryVerifyForm');
    const recoveryResetForm = document.getElementById('recoveryResetForm');
    const recoveryResendBtn = document.getElementById('recoveryResendBtn');
    let resendTimer = null;

    function showRecoveryStep(name) {
        document.querySelectorAll('[data-recovery-step]').forEach((step) => {
            step.hidden = step.dataset.recoveryStep !== name;
        });
    }

    function showRecoveryStatus(message) {
        recoveryStatus.textContent = message;
        recoveryStatus.hidden = !message;
    }

    async function recoveryRequest(action, payload = {}) {
        const response = await fetch('api/password_recovery.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...payload })
        });
        const data = await response.json();
        return { response, data };
    }

    function startResendCooldown(seconds = 30) {
        window.clearInterval(resendTimer);
        let remaining = seconds;
        recoveryResendBtn.disabled = true;
        recoveryResendBtn.textContent = `Resend in ${remaining}s`;
        resendTimer = window.setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                window.clearInterval(resendTimer);
                recoveryResendBtn.disabled = false;
                recoveryResendBtn.textContent = 'Resend code';
                return;
            }
            recoveryResendBtn.textContent = `Resend in ${remaining}s`;
        }, 1000);
    }

    async function closeRecoveryModal() {
        if (resendTimer) window.clearInterval(resendTimer);
        if (forgotModal.style.display !== 'none') {
            try {
                await recoveryRequest('cancel');
            } catch (error) {
                // Closing the UI is safe; server-side challenges still expire.
            }
        }
        forgotModal.style.display = 'none';
        showRecoveryStatus('');
        showRecoveryStep('username');
    }

    if (forgotPasswordLink && forgotModal) {
        forgotPasswordLink.addEventListener('click', (event) => {
            event.preventDefault();
            showRecoveryStatus('');
            showRecoveryStep('username');
            document.getElementById('recoveryUsername').value = usernameInput?.value.trim() || '';
            forgotModal.style.display = 'flex';
            document.getElementById('recoveryUsername').focus();
        });
    }

    recoveryStartForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.getElementById('recoveryStartBtn');
        button.disabled = true;
        showRecoveryStatus('');
        try {
            const { data } = await recoveryRequest('start', {
                username: document.getElementById('recoveryUsername').value.trim()
            });
            if (!data.success) {
                showRecoveryStatus(data.message || 'Unable to send a verification code right now. Please try again later.');
                return;
            }
            if (data.provider_accepted !== true) {
                showRecoveryStatus('Unable to send a verification code right now. Please try again later.');
                return;
            }
            // Only reveal the OTP step after the SMS provider accepts the send request.
            showRecoveryStep('otp');
            document.getElementById('recoveryOtp').focus();
            document.getElementById('recoveryMaskedPhone').textContent = data.masked_phone || '+63 9** *** ****';
            recoveryVerifyForm.querySelector('button[type="submit"]').disabled = false;
            showRecoveryStatus(data.message);
            startResendCooldown();
        } catch (error) {
            showRecoveryStatus('Unable to send a verification code right now. Please try again later.');
        } finally {
            button.disabled = false;
        }
    });

    recoveryResendBtn?.addEventListener('click', async () => {
        recoveryResendBtn.disabled = true;
        showRecoveryStatus('');
        try {
            const { data } = await recoveryRequest('resend');
            if (!data.success || data.provider_accepted !== true) {
                // A failed resend invalidates the old challenge, so do not accept its OTP.
                recoveryVerifyForm.querySelector('button[type="submit"]').disabled = true;
                showRecoveryStatus(data.message || 'Unable to send a verification code right now. Please try again later.');
                startResendCooldown();
                return;
            }
            document.getElementById('recoveryMaskedPhone').textContent = data.masked_phone || '+63 9** *** ****';
            recoveryVerifyForm.querySelector('button[type="submit"]').disabled = false;
            showRecoveryStatus(data.message);
            startResendCooldown();
        } catch (error) {
            recoveryVerifyForm.querySelector('button[type="submit"]').disabled = true;
            showRecoveryStatus('Unable to send a verification code right now. Please try again later.');
            startResendCooldown();
        }
    });

    recoveryVerifyForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = recoveryVerifyForm.querySelector('button[type="submit"]');
        button.disabled = true;
        showRecoveryStatus('');
        try {
            const { data } = await recoveryRequest('verify', {
                otp: document.getElementById('recoveryOtp').value.trim()
            });
            if (!data.success) {
                showRecoveryStatus(data.message || 'The code is invalid or expired.');
                return;
            }
            showRecoveryStep('password');
            showRecoveryStatus(data.message);
            document.getElementById('recoveryPassword').focus();
        } catch (error) {
            showRecoveryStatus('Password recovery is temporarily unavailable. Please try again later.');
        } finally {
            button.disabled = false;
        }
    });

    recoveryResetForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const password = document.getElementById('recoveryPassword').value;
        const confirmPassword = document.getElementById('recoveryConfirmPassword').value;
        const button = recoveryResetForm.querySelector('button[type="submit"]');
        button.disabled = true;
        showRecoveryStatus('');
        try {
            const { data } = await recoveryRequest('reset', { password, confirm_password: confirmPassword });
            if (!data.success) {
                showRecoveryStatus(data.message || 'Unable to set the new password.');
                return;
            }
            recoveryResetForm.reset();
            showRecoveryStep('success');
            showRecoveryStatus(data.message);
        } catch (error) {
            showRecoveryStatus('Password recovery is temporarily unavailable. Please try again later.');
        } finally {
            button.disabled = false;
        }
    });

    closeForgotModal?.addEventListener('click', closeRecoveryModal);
    document.getElementById('recoveryReturnLogin')?.addEventListener('click', closeRecoveryModal);
    window.addEventListener('click', (event) => {
        if (event.target === forgotModal) closeRecoveryModal();
    });
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && forgotModal?.style.display !== 'none') closeRecoveryModal();
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