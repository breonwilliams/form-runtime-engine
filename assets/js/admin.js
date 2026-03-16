/**
 * Form Runtime Engine - Admin JavaScript
 *
 * @package FormRuntimeEngine
 */

(function($) {
    'use strict';

    /**
     * Entry actions handler.
     */
    const FREAdmin = {
        /**
         * Initialize admin functionality.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Mark as read.
            $(document).on('click', '.fre-mark-read', function(e) {
                e.preventDefault();
                FREAdmin.markRead($(this).data('entry-id'), $(this));
            });

            // Mark as unread.
            $(document).on('click', '.fre-mark-unread', function(e) {
                e.preventDefault();
                FREAdmin.markUnread($(this).data('entry-id'), $(this));
            });

            // Delete entry.
            $(document).on('click', '.fre-delete-entry', function(e) {
                e.preventDefault();
                if (confirm(freAdmin.strings.confirmDelete)) {
                    FREAdmin.deleteEntry($(this).data('entry-id'), $(this));
                }
            });

            // Mark as spam.
            $(document).on('click', '.fre-mark-spam', function(e) {
                e.preventDefault();
                if (confirm(freAdmin.strings.confirmSpam)) {
                    FREAdmin.markSpam($(this).data('entry-id'), $(this));
                }
            });
        },

        /**
         * Mark entry as read.
         *
         * @param {number} entryId - Entry ID.
         * @param {jQuery} $button - Button element.
         */
        markRead: function(entryId, $button) {
            this.ajaxRequest('fre_mark_read', entryId, $button, function() {
                // Update UI.
                const $row = $button.closest('tr');
                if ($row.length) {
                    $row.find('.fre-status').removeClass('fre-status--unread').addClass('fre-status--read').text('Read');
                }

                // On detail page, update button.
                $button.removeClass('fre-mark-read').addClass('fre-mark-unread').text('Mark as Unread');
            });
        },

        /**
         * Mark entry as unread.
         *
         * @param {number} entryId - Entry ID.
         * @param {jQuery} $button - Button element.
         */
        markUnread: function(entryId, $button) {
            this.ajaxRequest('fre_mark_unread', entryId, $button, function() {
                // Update UI.
                const $row = $button.closest('tr');
                if ($row.length) {
                    $row.find('.fre-status').removeClass('fre-status--read').addClass('fre-status--unread').text('Unread');
                }

                // On detail page, update button.
                $button.removeClass('fre-mark-unread').addClass('fre-mark-read').text('Mark as Read');
            });
        },

        /**
         * Delete entry.
         *
         * @param {number} entryId - Entry ID.
         * @param {jQuery} $button - Button element.
         */
        deleteEntry: function(entryId, $button) {
            this.ajaxRequest('fre_delete_entry', entryId, $button, function() {
                // Remove row from table.
                const $row = $button.closest('tr');
                if ($row.length) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    // On detail page, redirect to list.
                    window.location.href = freAdmin.listUrl || 'admin.php?page=fre-entries';
                }
            });
        },

        /**
         * Mark entry as spam.
         *
         * @param {number} entryId - Entry ID.
         * @param {jQuery} $button - Button element.
         */
        markSpam: function(entryId, $button) {
            this.ajaxRequest('fre_mark_spam', entryId, $button, function() {
                // Update UI.
                const $row = $button.closest('tr');
                if ($row.length) {
                    $row.find('.fre-status').removeClass('fre-status--unread fre-status--read').addClass('fre-status--spam').text('Spam');
                    $button.closest('.row-actions').find('.fre-mark-spam').remove();
                }

                // On detail page, remove spam button.
                $button.remove();
            });
        },

        /**
         * Make AJAX request.
         *
         * @param {string}   action   - AJAX action.
         * @param {number}   entryId  - Entry ID.
         * @param {jQuery}   $button  - Button element.
         * @param {Function} callback - Success callback.
         */
        ajaxRequest: function(action, entryId, $button, callback) {
            const originalText = $button.text();

            $button.prop('disabled', true).text('...');

            $.ajax({
                url: freAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    entry_id: entryId,
                    nonce: freAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        callback();
                    } else {
                        alert(response.data.message || 'An error occurred.');
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        }
    };

    // Initialize on document ready.
    $(document).ready(function() {
        FREAdmin.init();
    });

    /**
     * Forms Manager handler.
     */
    const FREFormsManager = {
        /**
         * Track if user has manually edited the Form ID field.
         */
        idManuallyEdited: false,

        /**
         * Initialize.
         */
        init: function() {
            this.bindEvents();
            this.initAutoGenerateId();
        },

        /**
         * Initialize auto-generate ID from title functionality.
         */
        initAutoGenerateId: function() {
            var $titleField = $('#fre-form-title');
            var $idField = $('#fre-form-id');

            // Only enable auto-generation for new forms (ID field is not readonly).
            if ($idField.prop('readonly')) {
                return;
            }

            // Track manual edits to ID field.
            $idField.on('input', function() {
                FREFormsManager.idManuallyEdited = true;
            });

            // Auto-generate ID from title.
            $titleField.on('input', function() {
                // Skip if user has manually edited the ID field.
                if (FREFormsManager.idManuallyEdited) {
                    return;
                }

                var title = $(this).val();
                var slug = FREFormsManager.slugify(title);
                $idField.val(slug);
            });
        },

        /**
         * Convert a string to a URL-friendly slug.
         *
         * @param {string} text - The text to slugify.
         * @return {string} The slugified string.
         */
        slugify: function(text) {
            return text
                .toString()
                .toLowerCase()
                .trim()
                .replace(/\s+/g, '-')           // Replace spaces with dashes
                .replace(/[^\w\-]+/g, '')       // Remove non-word characters (except dashes)
                .replace(/\-\-+/g, '-')         // Replace multiple dashes with single dash
                .replace(/^-+/, '')             // Trim dashes from start
                .replace(/-+$/, '');            // Trim dashes from end
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Form submission.
            $('#fre-forms-form').on('submit', this.handleFormSubmit.bind(this));

            // Delete button.
            $(document).on('click', '.fre-forms-delete-btn', this.handleDelete.bind(this));

            // Copy shortcode button.
            $(document).on('click', '.fre-forms-copy-btn', this.handleCopy.bind(this));
        },

        /**
         * Handle form submission.
         *
         * @param {Event} e
         */
        handleFormSubmit: function(e) {
            e.preventDefault();

            var $form = $(e.target);
            var $submitBtn = $('#fre-forms-save-btn');
            var $spinner = $('#fre-forms-spinner');
            var $notices = $('#fre-forms-notices');

            // Get form data.
            var formId = $('#fre-form-id').val().trim();
            var title = $('#fre-form-title').val().trim();
            var config = $('#fre-form-config').val().trim();
            var customCss = $('#fre-form-custom-css').val().trim();

            // Client-side validation.
            if (!formId) {
                this.showNotice($notices, 'error', freAdmin.strings.formIdRequired);
                return;
            }

            if (!config) {
                this.showNotice($notices, 'error', freAdmin.strings.configRequired);
                return;
            }

            // Validate JSON syntax client-side.
            var parsedConfig;
            try {
                parsedConfig = JSON.parse(config);
            } catch (err) {
                this.showNotice($notices, 'error', 'Invalid JSON syntax: ' + err.message);
                return;
            }

            // Validate JSON schema.
            var schemaResult = this.validateJsonSchema(parsedConfig);
            if (!schemaResult.valid) {
                this.showNotice($notices, 'error', schemaResult.errors.join(' '));
                return;
            }

            // Log warnings if any (visible in browser console).
            if (schemaResult.warnings.length > 0) {
                console.warn('FRE Form Schema Warnings:', schemaResult.warnings);
            }

            // Validate CSS if provided.
            if (customCss) {
                var cssResult = this.validateCss(customCss);
                if (!cssResult.valid) {
                    this.showNotice($notices, 'error', cssResult.errors.join(' '));
                    return;
                }
            }

            // Show loading state.
            $submitBtn.prop('disabled', true).text(freAdmin.strings.saving);
            $spinner.addClass('is-active');
            $notices.empty();

            // Send AJAX request.
            $.ajax({
                url: freAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fre_save_form',
                    nonce: freAdmin.nonce,
                    form_id: formId,
                    title: title,
                    config: config,
                    custom_css: customCss
                },
                success: function(response) {
                    if (response.success) {
                        FREFormsManager.showNotice($notices, 'success', response.data.message);

                        // If adding new form, redirect to edit view.
                        if (!$('#fre-form-id').prop('readonly')) {
                            window.location.href = freAdmin.ajaxUrl.replace('admin-ajax.php', 'admin.php?page=fre-forms&action=edit&form=' + formId + '&saved=1');
                        }
                    } else {
                        FREFormsManager.showNotice($notices, 'error', response.data.message);
                    }
                },
                error: function() {
                    FREFormsManager.showNotice($notices, 'error', 'An error occurred. Please try again.');
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).text('Save Form');
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Handle delete button click.
         *
         * @param {Event} e
         */
        handleDelete: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var formId = $btn.data('form-id');

            if (!confirm(freAdmin.strings.confirmDeleteForm)) {
                return;
            }

            var originalText = $btn.text();
            $btn.prop('disabled', true).text(freAdmin.strings.deleting);

            $.ajax({
                url: freAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fre_delete_form',
                    nonce: freAdmin.nonce,
                    form_id: formId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row from table.
                        $btn.closest('tr').fadeOut(300, function() {
                            $(this).remove();

                            // Check if table is now empty.
                            if ($('.fre-forms-table tbody tr').length === 0) {
                                window.location.reload();
                            }
                        });
                    } else {
                        alert(response.data.message);
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Handle copy shortcode button click.
         *
         * @param {Event} e
         */
        handleCopy: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var shortcode = $btn.data('shortcode');

            // Copy to clipboard.
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(shortcode).then(function() {
                    FREFormsManager.showCopyTooltip($btn, freAdmin.strings.copied);
                }).catch(function() {
                    FREFormsManager.fallbackCopy(shortcode, $btn);
                });
            } else {
                this.fallbackCopy(shortcode, $btn);
            }
        },

        /**
         * Fallback copy method for older browsers.
         *
         * @param {string} text
         * @param {jQuery} $btn
         */
        fallbackCopy: function(text, $btn) {
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();

            try {
                var success = document.execCommand('copy');
                if (success) {
                    this.showCopyTooltip($btn, freAdmin.strings.copied);
                } else {
                    this.showCopyTooltip($btn, freAdmin.strings.copyFailed);
                }
            } catch (err) {
                this.showCopyTooltip($btn, freAdmin.strings.copyFailed);
            }

            $temp.remove();
        },

        /**
         * Show copy tooltip.
         *
         * @param {jQuery} $btn
         * @param {string} message
         */
        showCopyTooltip: function($btn, message) {
            var $tooltip = $('<div class="fre-forms-copy-tooltip">' + message + '</div>');
            var offset = $btn.offset();

            $tooltip.css({
                top: offset.top - 30,
                left: offset.left - ($tooltip.outerWidth() / 2) + ($btn.outerWidth() / 2)
            });

            $('body').append($tooltip);

            // Position after adding to DOM (to get accurate width).
            $tooltip.css('left', offset.left - ($tooltip.outerWidth() / 2) + ($btn.outerWidth() / 2));

            setTimeout(function() {
                $tooltip.fadeOut(200, function() {
                    $(this).remove();
                });
            }, 1500);
        },

        /**
         * Show admin notice.
         *
         * @param {jQuery} $container
         * @param {string} type
         * @param {string} message
         */
        showNotice: function($container, type, message) {
            var noticeClass = 'notice-' + type;
            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + this.escapeHtml(message) + '</p></div>');

            $container.html($notice);

            // Scroll to notice.
            $('html, body').animate({
                scrollTop: $container.offset().top - 50
            }, 300);

            // Make dismissible work.
            if (typeof wp !== 'undefined' && wp.notices && wp.notices.removeDismissible) {
                wp.notices.removeDismissible($notice);
            }
        },

        /**
         * Escape HTML entities.
         *
         * @param {string} text
         * @return {string}
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Validate CSS for dangerous patterns.
         *
         * @param {string} css
         * @return {object} Result with valid flag and errors array.
         */
        validateCss: function(css) {
            var result = {
                valid: true,
                errors: []
            };

            if (!css || !css.trim()) {
                return result;
            }

            var cssLower = css.toLowerCase();

            // Dangerous patterns that can execute code.
            var dangerousPatterns = [
                { pattern: /expression\s*\(/i, name: 'expression()' },
                { pattern: /behavior\s*:/i, name: 'behavior:' },
                { pattern: /-moz-binding\s*:/i, name: '-moz-binding:' },
                { pattern: /javascript\s*:/i, name: 'javascript:' },
                { pattern: /@import/i, name: '@import' },
                { pattern: /data\s*:/i, name: 'data:' },
                { pattern: /vbscript\s*:/i, name: 'vbscript:' },
                { pattern: /base64/i, name: 'base64' }
            ];

            // Check for dangerous patterns.
            for (var i = 0; i < dangerousPatterns.length; i++) {
                if (dangerousPatterns[i].pattern.test(css)) {
                    result.valid = false;
                    result.errors.push('CSS contains potentially unsafe pattern: ' + dangerousPatterns[i].name);
                }
            }

            // Check for balanced braces.
            var openBraces = (css.match(/\{/g) || []).length;
            var closeBraces = (css.match(/\}/g) || []).length;

            if (openBraces !== closeBraces) {
                result.valid = false;
                result.errors.push('Invalid CSS syntax: unbalanced braces. Check that all { have matching }.');
            }

            // Check for balanced parentheses.
            var openParens = (css.match(/\(/g) || []).length;
            var closeParens = (css.match(/\)/g) || []).length;

            if (openParens !== closeParens) {
                result.valid = false;
                result.errors.push('Invalid CSS syntax: unbalanced parentheses. Check that all ( have matching ).');
            }

            return result;
        },

        /**
         * Validate JSON form configuration schema.
         *
         * @param {object} config Parsed form configuration.
         * @return {object} Result with valid flag, errors array, and warnings array.
         */
        validateJsonSchema: function(config) {
            var result = {
                valid: true,
                errors: [],
                warnings: []
            };

            var validFieldTypes = [
                'text', 'email', 'tel', 'textarea', 'select',
                'radio', 'checkbox', 'file', 'hidden', 'message', 'section'
            ];

            // Must be an object.
            if (typeof config !== 'object' || config === null || Array.isArray(config)) {
                result.valid = false;
                result.errors.push('Configuration must be a valid object.');
                return result;
            }

            // Must have fields array.
            if (!config.fields || !Array.isArray(config.fields)) {
                result.valid = false;
                result.errors.push('Configuration must have a "fields" array.');
                return result;
            }

            // Fields array cannot be empty.
            if (config.fields.length === 0) {
                result.valid = false;
                result.errors.push('The "fields" array cannot be empty.');
                return result;
            }

            var fieldKeys = [];

            // Validate each field.
            for (var i = 0; i < config.fields.length; i++) {
                var field = config.fields[i];

                // Field must be an object.
                if (typeof field !== 'object' || field === null || Array.isArray(field)) {
                    result.valid = false;
                    result.errors.push('Field at index ' + i + ' must be an object.');
                    continue;
                }

                // Must have key.
                if (!field.key || typeof field.key !== 'string' || !field.key.trim()) {
                    result.valid = false;
                    result.errors.push('Field at index ' + i + ' is missing required "key" property.');
                }

                // Must have type.
                if (!field.type || typeof field.type !== 'string' || !field.type.trim()) {
                    result.valid = false;
                    result.errors.push('Field "' + (field.key || 'index ' + i) + '" is missing required "type" property.');
                } else {
                    // Type must be valid.
                    var fieldType = field.type.toLowerCase();
                    if (validFieldTypes.indexOf(fieldType) === -1) {
                        result.valid = false;
                        result.errors.push('Invalid field type "' + field.type + '" for field "' + (field.key || 'index ' + i) + '". Valid types: ' + validFieldTypes.join(', '));
                    }
                }

                // Track keys for duplicate check.
                if (field.key) {
                    fieldKeys.push(field.key);
                }

                // Check for options in select/radio fields.
                if (['select', 'radio'].indexOf(field.type) !== -1) {
                    if (!field.options || !Array.isArray(field.options) || field.options.length === 0) {
                        result.valid = false;
                        result.errors.push('Field "' + (field.key || 'index ' + i) + '" requires an "options" array.');
                    }
                }
            }

            // Check for duplicate field keys.
            var seen = {};
            var duplicates = [];
            for (var j = 0; j < fieldKeys.length; j++) {
                if (seen[fieldKeys[j]]) {
                    if (duplicates.indexOf(fieldKeys[j]) === -1) {
                        duplicates.push(fieldKeys[j]);
                    }
                }
                seen[fieldKeys[j]] = true;
            }

            if (duplicates.length > 0) {
                result.valid = false;
                result.errors.push('Duplicate field keys found: ' + duplicates.join(', '));
            }

            return result;
        }
    };

    // Initialize Forms Manager on document ready.
    $(document).ready(function() {
        FREFormsManager.init();
    });

})(jQuery);
