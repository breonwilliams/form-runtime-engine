/**
 * Twilio admin page — Settings (test-connection) and Clients (CRUD modal).
 *
 * Reads dynamic data from window.freTwilioAdmin (injected via
 * wp_localize_script in PHP). No PHP interpolation here — Plugin Check
 * happy, file is browser-cacheable across page loads.
 *
 * Used by both the Settings tab (test-connection button) and the Clients
 * tab (modal-based add/edit/toggle/delete). Both pieces are bound on
 * DOMContentLoaded; missing elements are quietly ignored so the same
 * file can ship on both tabs.
 */
( function( $ ) {
    'use strict';

    $( function() {
        var data = window.freTwilioAdmin || {};
        if ( ! data.nonce ) {
            return;
        }
        var nonce = data.nonce;
        var i18n  = data.i18n || {};

        // ---- Settings tab: Test Connection button. ----
        $( '#fre-twilio-test-connection' ).on( 'click', function() {
            var $btn    = $( this );
            var $result = $( '#fre-twilio-test-result' );
            $btn.prop( 'disabled', true );
            $result.text( i18n.testing || 'Testing...' );

            $.post( window.ajaxurl, {
                action: 'fre_twilio_test_connection',
                _wpnonce: nonce
            }, function( response ) {
                $btn.prop( 'disabled', false );
                if ( response.success ) {
                    $result.html(
                        $( '<span/>' ).addClass( 'fre-twilio-test-ok' )
                            .text( '✔ ' + ( i18n.connectedOk || 'Connected successfully' ) )
                    );
                } else {
                    $result.html(
                        $( '<span/>' ).addClass( 'fre-twilio-test-fail' )
                            .text( '✖ ' + response.data )
                    );
                }
            } ).fail( function() {
                $btn.prop( 'disabled', false );
                $result.html(
                    $( '<span/>' ).addClass( 'fre-twilio-test-fail' )
                        .text( '✖ ' + ( i18n.requestFailed || 'Request failed' ) )
                );
            } );
        } );

        // ---- Clients tab: modal CRUD. ----
        // Add client button.
        $( '#fre-twilio-add-client' ).on( 'click', function() {
            $( '#fre-twilio-modal-title' ).text( i18n.modalTitleAdd || 'Add Client' );
            $( '#fre-twilio-client-id' ).val( '' );
            $( '#fre-twilio-client-name, #fre-twilio-client-number, #fre-twilio-owner-phone, #fre-twilio-owner-email, #fre-twilio-webhook-url' ).val( '' );
            $( '#fre-twilio-auto-reply' ).val( i18n.defaultAutoReply || 'Thanks for calling {business_name}! Sorry we missed you. How can we help?' );
            $( '#fre-twilio-client-modal' ).show();
        } );

        // Cancel modal.
        $( '#fre-twilio-cancel-modal, .fre-twilio-modal-overlay' ).on( 'click', function( e ) {
            if ( e.target === this ) {
                $( '#fre-twilio-client-modal' ).hide();
            }
        } );

        // Edit client button.
        $( '.fre-twilio-edit-client' ).on( 'click', function() {
            var $btn = $( this );
            $( '#fre-twilio-modal-title' ).text( i18n.modalTitleEdit || 'Edit Client' );
            $( '#fre-twilio-client-id' ).val( $btn.data( 'id' ) );
            $( '#fre-twilio-client-name' ).val( $btn.data( 'name' ) );
            $( '#fre-twilio-client-number' ).val( $btn.data( 'number' ) );
            $( '#fre-twilio-owner-phone' ).val( $btn.data( 'owner-phone' ) );
            $( '#fre-twilio-owner-email' ).val( $btn.data( 'owner-email' ) );
            $( '#fre-twilio-auto-reply' ).val( $btn.data( 'auto-reply' ) );
            $( '#fre-twilio-webhook-url' ).val( $btn.data( 'webhook-url' ) );
            $( '#fre-twilio-client-modal' ).show();
        } );

        // Save client.
        $( '#fre-twilio-save-client' ).on( 'click', function() {
            var $result = $( '#fre-twilio-save-result' );
            $result.text( i18n.saving || 'Saving...' );

            $.post( window.ajaxurl, {
                action: 'fre_twilio_save_client',
                _wpnonce: nonce,
                id: $( '#fre-twilio-client-id' ).val(),
                client_name: $( '#fre-twilio-client-name' ).val(),
                twilio_number: $( '#fre-twilio-client-number' ).val(),
                owner_phone: $( '#fre-twilio-owner-phone' ).val(),
                owner_email: $( '#fre-twilio-owner-email' ).val(),
                auto_reply_template: $( '#fre-twilio-auto-reply' ).val(),
                webhook_url: $( '#fre-twilio-webhook-url' ).val()
            }, function( response ) {
                if ( response.success ) {
                    window.location.reload();
                } else {
                    $result.html(
                        $( '<span/>' ).addClass( 'fre-twilio-save-fail' ).text( response.data )
                    );
                }
            } );
        } );

        // Toggle client active/inactive.
        $( '.fre-twilio-toggle-client' ).on( 'click', function() {
            var $btn = $( this );
            $.post( window.ajaxurl, {
                action: 'fre_twilio_toggle_client',
                _wpnonce: nonce,
                id: $btn.data( 'id' )
            }, function( response ) {
                if ( response.success ) {
                    window.location.reload();
                }
            } );
        } );

        // Delete client.
        $( '.fre-twilio-delete-client' ).on( 'click', function() {
            var name = $( this ).data( 'name' );
            var msg  = ( i18n.confirmDelete || 'Delete client "%s"? This cannot be undone.' ).replace( '%s', name );
            if ( ! window.confirm( msg ) ) {
                return;
            }

            $.post( window.ajaxurl, {
                action: 'fre_twilio_delete_client',
                _wpnonce: nonce,
                id: $( this ).data( 'id' )
            }, function( response ) {
                if ( response.success ) {
                    window.location.reload();
                }
            } );
        } );
    } );
}( jQuery ) );
