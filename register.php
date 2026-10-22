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
    <link rel="stylesheet" href="style.css?v=20261011" />
    <link rel="stylesheet" href="register.css?v=20261015" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="registration-page-body">
    <main class="page-container register-page">
        <section class="auth-card register-card">
            <div class="register-brand">
                <img class="brand-logo" src="assets/balangaylog-logo.png" alt="" />
                <span class="register-brand-name">BalangayLog</span>
            </div>
            <a class="register-close" href="index.html" aria-label="Back to homepage" title="Back to homepage">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M19 12H5m7 7-7-7 7-7"></path>
                </svg>
                <span>Back to Home</span>
            </a>

            <form id="registerForm" enctype="multipart/form-data" novalidate>
                <div class="registration-progress" aria-label="Registration progress">
                    <div class="progress-track"><span id="progressFill"></span></div>
                    <p class="mobile-step-count" id="mobileStepCount" aria-live="polite">Step 1 of 4</p>
                    <ol class="step-list">
                        <li class="is-active" data-step-indicator="0"><span>1</span><small>Personal Information</small></li>
                        <li data-step-indicator="1"><span>2</span><small>Contact &amp; Address</small></li>
                        <li data-step-indicator="2"><span>3</span><small>Account Credentials</small></li>
                        <li data-step-indicator="3"><span>4</span><small>Verification</small></li>
                    </ol>
                </div>

                <div id="errorBanner" class="error-banner" style="display: none;" role="alert"></div>

                <section class="registration-step is-active" data-step="0" aria-labelledby="step1-title">
                    <div class="step-heading">
                        <h1 id="step1-title">Let's get to know you</h1>
                        <p>Please provide your basic personal information.</p>
                    </div>
                    <div class="register-grid">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" autocomplete="given-name" required aria-describedby="first_name_error" />
                            <small class="field-error" id="first_name_error"></small>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" autocomplete="family-name" required aria-describedby="last_name_error" />
                            <small class="field-error" id="last_name_error"></small>
                        </div>
                        <div class="form-group form-group--wide">
                            <label for="date_of_birth">Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth" autocomplete="bday" required aria-describedby="date_of_birth_error" />
                            <small class="field-error" id="date_of_birth_error"></small>
                        </div>
                    </div>
                </section>

                <section class="registration-step" data-step="1" aria-labelledby="step2-title" hidden>
                    <div class="step-heading">
                        <h1 id="step2-title">Your Contact Details</h1>
                        <p>Provide your contact information and residential address.</p>
                    </div>
                    <div class="register-grid register-grid-single">
                        <div class="form-group form-group--wide">
                            <label for="contact_number">Contact Number</label>
                            <input type="tel" id="contact_number" name="contact_number" placeholder="+63 9XX XXX XXXX" autocomplete="tel" required aria-describedby="contact_number_error" />
                            <small class="field-error" id="contact_number_error"></small>
                        </div>
                        <div class="form-group form-group--wide">
                            <label for="address">Complete Address</label>
                            <input type="text" id="address" name="address" autocomplete="street-address" required aria-describedby="address_error" />
                            <small class="field-error" id="address_error"></small>
                        </div>
                        <div class="form-group form-group--wide">
                            <label for="purok">Purok</label>
                            <select id="purok" name="purok" required aria-describedby="purok_error">
                                <option value="">Choose your Purok</option>
                            </select>
                            <small class="field-error" id="purok_error"></small>
                        </div>
                    </div>
                </section>

                <section class="registration-step" data-step="2" aria-labelledby="step3-title" hidden>
                    <div class="step-heading">
                        <h1 id="step3-title">Create Your Account</h1>
                        <p>Set up your login details to access BalangayLog.</p>
                    </div>
                    <div class="register-grid register-grid-single">
                        <div class="form-group form-group--wide">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" autocomplete="username" required pattern="(?:[A-Za-z0-9_.]|-)+" aria-describedby="username_hint username_error" />
                            <small class="field-hint" id="username_hint">Use letters, numbers, periods, underscores, or hyphens.</small>
                            <small class="field-error" id="username_error"></small>
                        </div>
                        <div class="form-group form-group--wide">
                            <label for="password">Password</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="password" name="password" autocomplete="new-password" required minlength="6" aria-describedby="password_hint password_error" />
                                <button type="button" class="eye-btn" data-password-toggle="password" data-field-name="password" aria-controls="password" aria-label="Show password" aria-pressed="false">
                                    <svg class="eye-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                            <small class="field-hint" id="password_hint">Password must be at least 6 characters.</small>
                            <small class="field-error" id="password_error"></small>
                        </div>
                        <div class="form-group form-group--wide">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required aria-describedby="confirm_password_error" />
                                <button type="button" class="eye-btn" data-password-toggle="confirm_password" data-field-name="confirm password" aria-controls="confirm_password" aria-label="Show confirm password" aria-pressed="false">
                                    <svg class="eye-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                            <small class="field-error" id="confirm_password_error"></small>
                        </div>
                    </div>
                </section>

                <section class="registration-step" data-step="3" aria-labelledby="step4-title" hidden>
                    <div class="step-heading">
                        <h1 id="step4-title">Verify Your Residency</h1>
                        <p>Upload a valid ID or proof of residency so your account can be verified by the barangay administrator.</p>
                    </div>
                    <div class="form-group form-group--wide upload-group">
                        <span class="upload-field-label">Proof of Residency / Valid ID Picture</span>
                        <label class="upload-area" for="proof_of_residency">
                            <span class="upload-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M20 16.5v2A1.5 1.5 0 0 1 18.5 20h-13A1.5 1.5 0 0 1 4 18.5v-2"/></svg>
                            </span>
                            <strong>Upload your ID or Proof of Residency</strong>
                            <span>Choose a JPG, PNG, or PDF file</span>
                            <input type="file" id="proof_of_residency" name="proof_of_residency" accept=".jpg,.jpeg,.png,.pdf" required aria-describedby="proof_of_residency_hint proof_of_residency_error" />
                        </label>
                        <small class="field-hint" id="proof_of_residency_hint">JPG, PNG, or PDF · Maximum file size: 5 MB.</small>
                        <small class="field-error" id="proof_of_residency_error"></small>
                        <div class="file-preview" id="filePreview" hidden>
                            <img id="imagePreview" alt="Selected proof preview" hidden />
                            <span id="fileName"></span>
                            <button type="button" id="removeFile" aria-label="Remove selected file">Remove</button>
                        </div>
                        <p class="privacy-note">Your document will be used only to verify your residency with the barangay.</p>
                    </div>

                    <div class="form-group form-group--wide" style="margin-top: 20px;">
                        <label for="otp_code">SMS Verification Code (OTP)</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="otp_code" name="otp_code" placeholder="Enter 6-digit code" required pattern="\d{6}" aria-describedby="otp_hint otp_code_error" style="flex: 1;" />
                            <button type="button" id="requestOtpBtn" class="secondary-btn" style="white-space: nowrap; padding: 0 20px;">Send OTP</button>
                        </div>
                        <small class="field-hint" id="otp_hint">Click 'Send OTP' to receive a code on your contact number.</small>
                        <small class="field-error" id="otp_code_error"></small>
                    </div>

                </section>

                <div class="registration-actions">
                    <button type="button" class="secondary-btn" id="backBtn" hidden>Back</button>
                    <span class="action-spacer"></span>
                    <button type="button" class="primary-btn" id="nextBtn">Next</button>
                    <button type="submit" class="primary-btn" id="registerBtn" hidden>Create Account</button>
                </div>
                <p class="register-footer">Already have an account? <a href="index.html">Log In</a></p>
            </form>
        </section>
    </main>

    <script>
        const registerForm = document.getElementById('registerForm');
        const registerBtn = document.getElementById('registerBtn');
        const errorBanner = document.getElementById('errorBanner');
        const steps = [...document.querySelectorAll('.registration-step')];
        const backBtn = document.getElementById('backBtn');
        const nextBtn = document.getElementById('nextBtn');
        const progressFill = document.getElementById('progressFill');
        const mobileStepCount = document.getElementById('mobileStepCount');
        const proofInput = document.getElementById('proof_of_residency');
        const filePreview = document.getElementById('filePreview');
        const imagePreview = document.getElementById('imagePreview');
        let currentStep = 0;
        let imagePreviewUrl = '';

        function showStep(stepIndex) {
            currentStep = stepIndex;
            steps.forEach((step, index) => {
                const isActive = index === stepIndex;
                step.hidden = !isActive;
                step.classList.toggle('is-active', isActive);
            });
            document.querySelectorAll('[data-step-indicator]').forEach((indicator, index) => {
                indicator.classList.toggle('is-active', index === stepIndex);
                indicator.classList.toggle('is-complete', index < stepIndex);
                indicator.querySelector('span').textContent = index < stepIndex ? '✓' : String(index + 1);
            });
            progressFill.style.width = `${((stepIndex + 1) / steps.length) * 100}%`;
            mobileStepCount.textContent = `Step ${stepIndex + 1} of ${steps.length}`;
            backBtn.hidden = stepIndex === 0;
            nextBtn.hidden = stepIndex === steps.length - 1;
            registerBtn.hidden = stepIndex !== steps.length - 1;
            errorBanner.style.display = 'none';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function setFieldError(field, message) {
            const error = document.getElementById(`${field.id}_error`);
            if (!error) return;
            error.textContent = message;
            error.classList.toggle('is-visible', Boolean(message));
            field.setAttribute('aria-invalid', String(Boolean(message)));
        }

        function validateField(field) {
            let message = '';
            if (!field.checkValidity()) {
                if (field.validity.valueMissing) {
                    message = field.type === 'file'
                        ? 'Please choose a file to verify your residency.'
                        : 'This field is required.';
                } else if (field.validity.patternMismatch) {
                    message = 'Use only letters, numbers, periods, underscores, or hyphens.';
                } else if (field.validity.tooShort) {
                    message = 'Password must be at least 6 characters.';
                } else {
                    message = field.validationMessage;
                }
            }
            if (!message && field.id === 'confirm_password' && field.value !== document.getElementById('password').value) {
                message = 'Passwords do not match.';
            }
            if (!message && field.id === 'proof_of_residency' && field.files[0]?.size > 5 * 1024 * 1024) {
                message = 'The selected file exceeds the 5 MB limit.';
            }
            setFieldError(field, message);
            return !message;
        }

        function validateStep(stepIndex) {
            const fields = [...steps[stepIndex].querySelectorAll('input, select')].filter((field) => field.required);
            let isValid = true;
            let firstInvalidField = null;
            fields.forEach((field) => {
                if (!validateField(field)) {
                    isValid = false;
                    if (!firstInvalidField) firstInvalidField = field;
                }
            });
            if (firstInvalidField) firstInvalidField.focus();
            return isValid;
        }

        nextBtn.addEventListener('click', () => {
            if (validateStep(currentStep)) showStep(currentStep + 1);
        });

        backBtn.addEventListener('click', () => showStep(currentStep - 1));

        registerForm.querySelectorAll('input, select').forEach((field) => {
            field.addEventListener('input', () => validateField(field));
            field.addEventListener('change', () => validateField(field));
        });

        document.getElementById('password').addEventListener('input', () => {
            const confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword.value) validateField(confirmPassword);
        });

        proofInput.addEventListener('change', () => {
            const file = proofInput.files[0];
            if (imagePreviewUrl) URL.revokeObjectURL(imagePreviewUrl);
            imagePreviewUrl = '';
            imagePreview.hidden = true;
            filePreview.hidden = !file;
            if (!file) return;
            document.getElementById('fileName').textContent = file.name;
            if (file.type.startsWith('image/')) {
                imagePreviewUrl = URL.createObjectURL(file);
                imagePreview.src = imagePreviewUrl;
                imagePreview.hidden = false;
            }
            validateField(proofInput);
        });

        document.getElementById('removeFile').addEventListener('click', () => {
            proofInput.value = '';
            filePreview.hidden = true;
            imagePreview.hidden = true;
            imagePreview.removeAttribute('src');
            if (imagePreviewUrl) URL.revokeObjectURL(imagePreviewUrl);
            imagePreviewUrl = '';
            validateField(proofInput);
        });

        showStep(0);

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

        document.getElementById('requestOtpBtn').addEventListener('click', async function() {
            const phoneInput = document.getElementById('contact_number');
            const phoneVal = phoneInput.value.trim();
            const errorElement = document.getElementById('otp_code_error');
            const btn = this;
            
            if (!phoneVal) {
                showStep(1); 
                phoneInput.focus();
                setFieldError(phoneInput, 'Please provide a contact number before requesting an OTP.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Sending...';
            errorElement.classList.remove('is-visible');
            
            try {
                const response = await fetch('api/send_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ contact_number: phoneVal })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    errorElement.textContent = 'OTP sent! Please check your messages.';
                    errorElement.style.color = '#059669'; 
                    errorElement.classList.add('is-visible');
                    
                    let countdown = 60;
                    const timer = setInterval(() => {
                        countdown--;
                        btn.textContent = `Resend in ${countdown}s`;
                        if (countdown <= 0) {
                            clearInterval(timer);
                            btn.disabled = false;
                            btn.textContent = 'Resend OTP';
                            errorElement.style.color = ''; 
                        }
                    }, 1000);
                } else {
                    errorElement.textContent = result.message || 'Failed to send OTP.';
                    errorElement.style.color = ''; 
                    errorElement.classList.add('is-visible');
                    btn.disabled = false;
                    btn.textContent = 'Send OTP';
                }
            } catch (e) {
                errorElement.textContent = 'Network error. Failed to connect to SMS server.';
                errorElement.classList.add('is-visible');
                btn.disabled = false;
                btn.textContent = 'Send OTP';
            }
        });

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

                for (let stepIndex = 0; stepIndex < steps.length; stepIndex += 1) {
                    if (!validateStep(stepIndex)) {
                        showStep(stepIndex);
                        validateStep(stepIndex);
                        return;
                    }
                }

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
                    registerBtn.textContent = 'Create Account';
                }
            });
        }
    </script>
</body>
</html>