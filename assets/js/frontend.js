/**
 * Form Runtime Engine - Frontend JavaScript
 *
 * @package FormRuntimeEngine
 */

(function() {
    'use strict';

    /**
     * Generate a UUID v4.
     *
     * @returns {string} UUID v4 string.
     */
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    /**
     * Form handler class.
     */
    class FREForm {
        /**
         * Initialize a form.
         *
         * @param {HTMLFormElement} form - Form element.
         */
        constructor(form) {
            this.form = form;
            this.formId = form.dataset.formId;
            this.isAjax = form.dataset.ajax === 'true';
            this.storageKey = `fre_form_${this.formId}`;
            this.renderTime = Date.now();
            this.isSubmitting = false;

            // Fix #4: Track submission UUID to prevent duplicate submissions on retry.
            this.currentSubmissionId = null;

            this.init();
        }

        /**
         * Initialize event listeners.
         */
        init() {
            // Restore saved data on page load.
            this.restoreData();

            // Save data on input.
            this.form.addEventListener('input', () => this.saveData());
            this.form.addEventListener('change', () => this.saveData());

            // Handle form submission.
            this.form.addEventListener('submit', (e) => this.handleSubmit(e));

            // Clear field errors on input.
            this.form.querySelectorAll('.fre-field__input, .fre-field__textarea, .fre-field__select').forEach(input => {
                input.addEventListener('input', () => this.clearFieldError(input));
            });

            // Handle checkbox/radio changes.
            this.form.querySelectorAll('.fre-field__checkbox, .fre-field__radio').forEach(input => {
                input.addEventListener('change', () => this.clearFieldError(input));
            });
        }

        /**
         * Save form data to sessionStorage.
         */
        saveData() {
            try {
                const data = new FormData(this.form);
                const obj = {};

                data.forEach((value, key) => {
                    // Skip internal fields and files.
                    if (key.startsWith('_') || key.startsWith('fre_file_')) {
                        return;
                    }

                    // Handle arrays (checkboxes, multi-select).
                    if (obj[key]) {
                        if (!Array.isArray(obj[key])) {
                            obj[key] = [obj[key]];
                        }
                        obj[key].push(value);
                    } else {
                        obj[key] = value;
                    }
                });

                sessionStorage.setItem(this.storageKey, JSON.stringify(obj));
            } catch (e) {
                // sessionStorage might be unavailable.
                console.warn('FRE: Unable to save form data to sessionStorage');
            }
        }

        /**
         * Restore form data from sessionStorage.
         */
        restoreData() {
            try {
                const saved = sessionStorage.getItem(this.storageKey);
                if (!saved) return;

                const data = JSON.parse(saved);

                Object.entries(data).forEach(([name, value]) => {
                    const inputs = this.form.querySelectorAll(`[name="${name}"], [name="${name}[]"]`);

                    inputs.forEach(input => {
                        if (input.type === 'checkbox' || input.type === 'radio') {
                            const values = Array.isArray(value) ? value : [value];
                            input.checked = values.includes(input.value);
                        } else if (input.tagName === 'SELECT' && input.multiple) {
                            const values = Array.isArray(value) ? value : [value];
                            Array.from(input.options).forEach(option => {
                                option.selected = values.includes(option.value);
                            });
                        } else {
                            input.value = value;
                        }
                    });
                });
            } catch (e) {
                console.warn('FRE: Unable to restore form data from sessionStorage');
            }
        }

        /**
         * Clear saved form data.
         */
        clearSavedData() {
            try {
                sessionStorage.removeItem(this.storageKey);
            } catch (e) {
                // Ignore.
            }
        }

        /**
         * Handle form submission.
         *
         * @param {Event} e - Submit event.
         */
        async handleSubmit(e) {
            // Prevent double submission.
            if (this.isSubmitting) {
                e.preventDefault();
                return;
            }

            // Clear previous messages.
            this.clearMessages();
            this.clearAllErrors();

            // If not AJAX, let the form submit normally.
            if (!this.isAjax) {
                return;
            }

            e.preventDefault();

            // Check if form has been open for too long (> 1 hour).
            const hourMs = 60 * 60 * 1000;
            if (Date.now() - this.renderTime > hourMs) {
                try {
                    const freshNonce = await this.refreshNonce();
                    const nonceInput = this.form.querySelector('[name="_wpnonce"]');
                    if (nonceInput) {
                        nonceInput.value = freshNonce;
                    }
                } catch (error) {
                    this.showMessage('error', 'Your session expired. Please refresh the page and try again.');
                    return;
                }
            }

            this.submitForm();
        }

        /**
         * Submit form via AJAX.
         */
        async submitForm() {
            const submitBtn = this.form.querySelector('[type="submit"]');
            const submitText = submitBtn.querySelector('.fre-form__submit-text');
            const submitLoading = submitBtn.querySelector('.fre-form__submit-loading');

            // Set submitting state.
            this.isSubmitting = true;
            submitBtn.disabled = true;

            if (submitText) submitText.style.display = 'none';
            if (submitLoading) submitLoading.style.display = 'inline-flex';

            // Fix #4: Generate submission UUID for idempotency (reuse if retrying).
            if (!this.currentSubmissionId) {
                this.currentSubmissionId = generateUUID();
            }

            // Prepare form data.
            const formData = new FormData(this.form);
            formData.append('action', 'fre_submit_form');
            formData.append('_fre_submission_id', this.currentSubmissionId);

            try {
                const response = await fetch(freAjax.url, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                });

                const result = await response.json();

                if (result.success) {
                    // Clear saved data.
                    this.clearSavedData();

                    // Fix #4: Clear submission ID on success for next submission.
                    this.currentSubmissionId = null;

                    // Show success message.
                    this.showMessage('success', result.data.message);

                    // Reset form.
                    this.form.reset();

                    // Handle redirect.
                    if (result.data.redirect) {
                        window.location.href = result.data.redirect;
                        return;
                    }

                    // Scroll to message.
                    this.scrollToMessages();
                } else {
                    this.handleError(result.data);
                }
            } catch (error) {
                console.error('FRE: Submission error', error);
                this.showMessage('error', 'An error occurred. Please try again.');
            } finally {
                // Reset button state.
                this.isSubmitting = false;
                submitBtn.disabled = false;

                if (submitText) submitText.style.display = '';
                if (submitLoading) submitLoading.style.display = 'none';
            }
        }

        /**
         * Handle error response.
         *
         * @param {Object} data - Error data.
         */
        handleError(data) {
            // Handle nonce expiration.
            if (data.code === 'nonce_expired') {
                // Update nonce.
                const nonceInput = this.form.querySelector('[name="_wpnonce"]');
                if (nonceInput && data.new_nonce) {
                    nonceInput.value = data.new_nonce;
                }

                // Repopulate form data if provided.
                if (data.submitted_data) {
                    this.repopulateForm(data.submitted_data);
                }
            }

            // Show field errors.
            if (data.field_errors) {
                Object.entries(data.field_errors).forEach(([fieldKey, message]) => {
                    this.showFieldError(fieldKey, message);
                });

                // Focus first error field.
                const firstError = this.form.querySelector('.fre-field--has-error');
                if (firstError) {
                    const input = firstError.querySelector('input, textarea, select');
                    if (input) input.focus();
                }
            }

            // Show general error message.
            this.showMessage('error', data.message);
            this.scrollToMessages();
        }

        /**
         * Refresh nonce via AJAX.
         *
         * @returns {Promise<string>} Fresh nonce.
         */
        async refreshNonce() {
            const response = await fetch(freAjax.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'fre_refresh_nonce',
                    form_id: this.formId,
                }),
                credentials: 'same-origin',
            });

            const result = await response.json();

            if (result.success) {
                return result.data.nonce;
            }

            throw new Error('Failed to refresh nonce');
        }

        /**
         * Show a message.
         *
         * @param {string} type    - Message type (success, error).
         * @param {string} message - Message text.
         */
        showMessage(type, message) {
            const container = this.form.querySelector('.fre-form__messages');
            if (!container) return;

            const messageEl = document.createElement('div');
            messageEl.className = `fre-form__message fre-form__message--${type}`;
            messageEl.textContent = message;

            container.appendChild(messageEl);
        }

        /**
         * Clear all messages.
         */
        clearMessages() {
            const container = this.form.querySelector('.fre-form__messages');
            if (container) {
                container.innerHTML = '';
            }
        }

        /**
         * Show field error.
         *
         * @param {string} fieldKey - Field key.
         * @param {string} message  - Error message.
         */
        showFieldError(fieldKey, message) {
            const field = this.form.querySelector(`[data-field-key="${fieldKey}"]`);
            if (!field) return;

            field.classList.add('fre-field--has-error');

            const errorEl = field.querySelector('.fre-field__error');
            if (errorEl) {
                errorEl.textContent = message;
            }
        }

        /**
         * Clear field error.
         *
         * @param {HTMLElement} input - Input element.
         */
        clearFieldError(input) {
            const field = input.closest('.fre-field');
            if (!field) return;

            field.classList.remove('fre-field--has-error');

            const errorEl = field.querySelector('.fre-field__error');
            if (errorEl) {
                errorEl.textContent = '';
            }
        }

        /**
         * Clear all field errors.
         */
        clearAllErrors() {
            this.form.querySelectorAll('.fre-field--has-error').forEach(field => {
                field.classList.remove('fre-field--has-error');
                const errorEl = field.querySelector('.fre-field__error');
                if (errorEl) errorEl.textContent = '';
            });
        }

        /**
         * Repopulate form with submitted data.
         *
         * @param {Object} data - Submitted data.
         */
        repopulateForm(data) {
            Object.entries(data).forEach(([name, value]) => {
                const inputs = this.form.querySelectorAll(`[name="${name}"], [name="${name}[]"]`);

                inputs.forEach(input => {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        const values = Array.isArray(value) ? value : [value];
                        input.checked = values.includes(input.value);
                    } else {
                        input.value = value;
                    }
                });
            });
        }

        /**
         * Scroll to messages container.
         */
        scrollToMessages() {
            const container = this.form.querySelector('.fre-form__messages');
            if (container) {
                container.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    /**
     * Initialize all forms on page load.
     */
    function initForms() {
        document.querySelectorAll('.fre-form').forEach(form => {
            new FREForm(form);
        });
    }

    // Initialize on DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initForms);
    } else {
        initForms();
    }

    // Expose for external use.
    window.FREForm = FREForm;

})();
