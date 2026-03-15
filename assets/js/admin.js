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

})(jQuery);
