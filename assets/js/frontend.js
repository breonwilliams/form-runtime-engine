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

            // Multi-step form state.
            this.isMultistep = form.classList.contains('fre-form--multistep');
            this.currentStep = 1;
            this.totalSteps = parseInt(form.dataset.steps, 10) || 1;
            this.validateOnNext = form.dataset.validateSteps === 'true';

            // Conditional fields tracking.
            this.conditionalFields = [];
            this.conditionalSections = [];

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

            // Initialize multi-step navigation.
            if (this.isMultistep) {
                this.initMultistep();
            }

            // Initialize conditional logic.
            this.initConditionalLogic();
        }

        /**
         * Initialize multi-step form navigation.
         */
        initMultistep() {
            // Next buttons.
            this.form.querySelectorAll('.fre-step__next').forEach(btn => {
                btn.addEventListener('click', () => this.goToNextStep());
            });

            // Previous buttons.
            this.form.querySelectorAll('.fre-step__prev').forEach(btn => {
                btn.addEventListener('click', () => this.goToPrevStep());
            });

            // Progress step clicks (for completed steps).
            this.form.querySelectorAll('.fre-progress__step').forEach(stepEl => {
                stepEl.addEventListener('click', () => {
                    const stepNum = parseInt(stepEl.dataset.step, 10);
                    if (stepNum < this.currentStep) {
                        this.goToStep(stepNum);
                    }
                });
                stepEl.style.cursor = 'pointer';
            });
        }

        /**
         * Go to the next step.
         */
        goToNextStep() {
            if (this.currentStep >= this.totalSteps) return;

            // Validate current step if enabled.
            if (this.validateOnNext && !this.validateCurrentStep()) {
                return;
            }

            this.goToStep(this.currentStep + 1);
        }

        /**
         * Go to the previous step.
         */
        goToPrevStep() {
            if (this.currentStep <= 1) return;
            this.goToStep(this.currentStep - 1);
        }

        /**
         * Go to a specific step.
         *
         * @param {number} stepNum - Step number (1-indexed).
         */
        goToStep(stepNum) {
            if (stepNum < 1 || stepNum > this.totalSteps) return;

            // Hide current step.
            const currentStepEl = this.form.querySelector(`.fre-step[data-step="${this.currentStep}"]`);
            if (currentStepEl) {
                currentStepEl.classList.remove('fre-step--active');
            }

            // Show new step.
            const newStepEl = this.form.querySelector(`.fre-step[data-step="${stepNum}"]`);
            if (newStepEl) {
                newStepEl.classList.add('fre-step--active');
            }

            // Update progress indicator.
            this.updateProgressIndicator(stepNum);

            // Update current step.
            this.currentStep = stepNum;

            // Scroll to top of form.
            this.form.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Re-evaluate conditions for the new step.
            this.evaluateAllConditions();
        }

        /**
         * Update the progress indicator.
         *
         * @param {number} stepNum - Current step number.
         */
        updateProgressIndicator(stepNum) {
            const progress = this.form.querySelector('.fre-progress');
            if (!progress) return;

            // Update bar style.
            const fill = progress.querySelector('.fre-progress__fill');
            if (fill) {
                const percentage = (stepNum / this.totalSteps) * 100;
                fill.style.width = `${percentage}%`;
            }

            // Update current number text.
            const currentText = progress.querySelector('.fre-progress__current');
            if (currentText) {
                currentText.textContent = stepNum;
            }

            // Update step indicators.
            progress.querySelectorAll('.fre-progress__step').forEach(stepEl => {
                const stepIndex = parseInt(stepEl.dataset.step, 10);
                stepEl.classList.remove('fre-progress__step--active', 'fre-progress__step--completed');

                if (stepIndex === stepNum) {
                    stepEl.classList.add('fre-progress__step--active');
                } else if (stepIndex < stepNum) {
                    stepEl.classList.add('fre-progress__step--completed');
                }
            });

            // Update connectors.
            const connectors = progress.querySelectorAll('.fre-progress__connector');
            connectors.forEach((connector, index) => {
                if (index < stepNum - 1) {
                    connector.style.backgroundColor = 'var(--fre-primary-color)';
                } else {
                    connector.style.backgroundColor = '';
                }
            });
        }

        /**
         * Validate the current step's fields.
         *
         * @returns {boolean} True if valid.
         */
        validateCurrentStep() {
            const currentStepEl = this.form.querySelector(`.fre-step[data-step="${this.currentStep}"]`);
            if (!currentStepEl) return true;

            let isValid = true;
            const inputs = currentStepEl.querySelectorAll('input, textarea, select');

            inputs.forEach(input => {
                // Skip hidden conditional fields.
                const field = input.closest('.fre-field');
                if (field && field.classList.contains('fre-field--hidden')) {
                    return;
                }

                // Skip disabled fields.
                if (input.disabled) {
                    return;
                }

                // Check HTML5 validation.
                if (!input.checkValidity()) {
                    isValid = false;
                    this.showFieldError(input.closest('.fre-field')?.dataset.fieldKey, input.validationMessage);
                    if (isValid === false) {
                        input.focus();
                    }
                }
            });

            return isValid;
        }

        /**
         * Initialize conditional logic.
         */
        initConditionalLogic() {
            // Find all fields with conditions.
            this.conditionalFields = Array.from(
                this.form.querySelectorAll('[data-conditions]')
            );

            if (this.conditionalFields.length === 0) return;

            // Add change listeners to all form inputs.
            this.form.querySelectorAll('input, textarea, select').forEach(input => {
                input.addEventListener('change', () => this.evaluateAllConditions());
                if (input.type === 'text' || input.tagName === 'TEXTAREA') {
                    input.addEventListener('input', () => this.evaluateAllConditions());
                }
            });

            // Initial evaluation.
            this.evaluateAllConditions();
        }

        /**
         * Evaluate all conditional fields.
         */
        evaluateAllConditions() {
            this.conditionalFields.forEach(element => {
                const conditionsAttr = element.dataset.conditions;
                if (!conditionsAttr) return;

                try {
                    const conditions = JSON.parse(conditionsAttr);
                    const shouldShow = this.evaluateConditions(conditions);

                    if (shouldShow) {
                        this.showConditionalElement(element);
                    } else {
                        this.hideConditionalElement(element);
                    }
                } catch (e) {
                    console.warn('FRE: Invalid conditions JSON', e);
                }
            });
        }

        /**
         * Evaluate a conditions object.
         *
         * @param {Object} conditions - Conditions configuration.
         * @returns {boolean} True if conditions are met.
         */
        evaluateConditions(conditions) {
            if (!conditions.rules || !Array.isArray(conditions.rules)) {
                return true;
            }

            const logic = conditions.logic || 'and';
            const results = conditions.rules.map(rule => this.evaluateRule(rule));

            if (logic === 'or') {
                return results.some(r => r === true);
            }

            // Default: 'and' logic.
            return results.every(r => r === true);
        }

        /**
         * Evaluate a single condition rule.
         *
         * @param {Object} rule - Rule configuration.
         * @returns {boolean} True if rule is met.
         */
        evaluateRule(rule) {
            const { field, operator, value } = rule;
            const fieldValue = this.getFieldValue(field);

            switch (operator) {
                case 'equals':
                case '==':
                case '=':
                    return fieldValue === value;

                case 'not_equals':
                case '!=':
                case '<>':
                    return fieldValue !== value;

                case 'contains':
                    return String(fieldValue).toLowerCase().includes(String(value).toLowerCase());

                case 'not_contains':
                    return !String(fieldValue).toLowerCase().includes(String(value).toLowerCase());

                case 'is_empty':
                case 'empty':
                    return fieldValue === '' || fieldValue === null || fieldValue === undefined ||
                           (Array.isArray(fieldValue) && fieldValue.length === 0);

                case 'is_not_empty':
                case 'not_empty':
                    return fieldValue !== '' && fieldValue !== null && fieldValue !== undefined &&
                           !(Array.isArray(fieldValue) && fieldValue.length === 0);

                case 'is_checked':
                case 'checked':
                    return fieldValue === true || fieldValue === '1' || fieldValue === 'on';

                case 'is_not_checked':
                case 'not_checked':
                    return fieldValue === false || fieldValue === '' || fieldValue === '0';

                case 'greater_than':
                case '>':
                    return parseFloat(fieldValue) > parseFloat(value);

                case 'less_than':
                case '<':
                    return parseFloat(fieldValue) < parseFloat(value);

                case 'greater_than_or_equals':
                case '>=':
                    return parseFloat(fieldValue) >= parseFloat(value);

                case 'less_than_or_equals':
                case '<=':
                    return parseFloat(fieldValue) <= parseFloat(value);

                case 'in':
                    return Array.isArray(value) ? value.includes(fieldValue) : false;

                case 'not_in':
                    return Array.isArray(value) ? !value.includes(fieldValue) : true;

                default:
                    console.warn(`FRE: Unknown condition operator: ${operator}`);
                    return true;
            }
        }

        /**
         * Get the value of a field by key.
         *
         * @param {string} fieldKey - Field key.
         * @returns {*} Field value.
         */
        getFieldValue(fieldKey) {
            const name = `fre_field_${fieldKey}`;

            // Check for radio buttons.
            const radios = this.form.querySelectorAll(`input[name="${name}"][type="radio"]`);
            if (radios.length > 0) {
                const checked = Array.from(radios).find(r => r.checked);
                return checked ? checked.value : '';
            }

            // Check for checkboxes.
            const checkboxes = this.form.querySelectorAll(`input[name="${name}"][type="checkbox"], input[name="${name}[]"][type="checkbox"]`);
            if (checkboxes.length > 0) {
                if (checkboxes.length === 1 && !checkboxes[0].name.endsWith('[]')) {
                    // Single checkbox.
                    return checkboxes[0].checked;
                }
                // Multiple checkboxes.
                return Array.from(checkboxes).filter(c => c.checked).map(c => c.value);
            }

            // Check for select.
            const select = this.form.querySelector(`select[name="${name}"], select[name="${name}[]"]`);
            if (select) {
                if (select.multiple) {
                    return Array.from(select.selectedOptions).map(o => o.value);
                }
                return select.value;
            }

            // Check for regular input.
            const input = this.form.querySelector(`input[name="${name}"], textarea[name="${name}"]`);
            if (input) {
                return input.value;
            }

            return '';
        }

        /**
         * Show a conditional element.
         *
         * @param {HTMLElement} element - Element to show.
         */
        showConditionalElement(element) {
            element.classList.remove('fre-field--hidden', 'fre-section--hidden');

            // Re-enable required validation on inputs.
            const inputs = element.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                if (input.dataset.wasRequired === 'true') {
                    input.required = true;
                    input.removeAttribute('data-was-required');
                }
            });
        }

        /**
         * Hide a conditional element.
         *
         * @param {HTMLElement} element - Element to hide.
         */
        hideConditionalElement(element) {
            if (element.classList.contains('fre-section')) {
                element.classList.add('fre-section--hidden');
            } else {
                element.classList.add('fre-field--hidden');
            }

            // Disable required validation on hidden inputs.
            const inputs = element.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                if (input.required) {
                    input.dataset.wasRequired = 'true';
                    input.required = false;
                }
            });
        }

        /**
         * Get list of hidden conditional field keys.
         *
         * @returns {string[]} Array of field keys that are currently hidden.
         */
        getHiddenConditionalFieldKeys() {
            const hiddenKeys = [];

            this.form.querySelectorAll('.fre-field--hidden, .fre-section--hidden').forEach(element => {
                const fieldKey = element.dataset.fieldKey || element.dataset.sectionKey;
                if (fieldKey) {
                    hiddenKeys.push(fieldKey);
                }

                // Also add any nested field keys within hidden sections.
                if (element.classList.contains('fre-section--hidden')) {
                    element.querySelectorAll('[data-field-key]').forEach(nested => {
                        if (nested.dataset.fieldKey) {
                            hiddenKeys.push(nested.dataset.fieldKey);
                        }
                    });
                }
            });

            return [...new Set(hiddenKeys)]; // Remove duplicates.
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

            // Add list of hidden conditional fields for server-side validation.
            const hiddenFields = this.getHiddenConditionalFieldKeys();
            if (hiddenFields.length > 0) {
                formData.append('_fre_hidden_fields', JSON.stringify(hiddenFields));
            }

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

                    // Reset multi-step form to first step.
                    if (this.isMultistep) {
                        this.goToStep(1);
                    }

                    // Re-evaluate conditions after reset.
                    this.evaluateAllConditions();

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
