/**
 * Connector admin page — Generate / Revoke / Copy-Command workflow.
 *
 * Reads dynamic data (ajax URL, nonce, connector script URL, site URL,
 * translated strings) from window.freConnectorAdmin which is injected via
 * wp_localize_script() in PHP. No PHP interpolation in this file —
 * makes Plugin Check happy and lets browsers cache the file across sites.
 *
 * Wired to DOM in three places: the two toggle checkboxes (data-ajax-action
 * attr selector), the generate button (#fre-generate-password-btn), and the
 * revoke button (#fre-revoke-password-btn). The copy button
 * (#fre-copy-setup-command) is bound conditionally because it only exists
 * once a command has been rendered.
 */
(function() {
    'use strict';

    var data = window.freConnectorAdmin || {};
    if ( ! data.ajaxUrl ) {
        return;
    }
    var i18n = data.i18n || {};

    /**
     * Build the one-line bash setup command.
     *
     * Ported from the Promptless connector's equivalent, with paths and
     * identifiers adapted for the form engine. Notable details:
     *   - Installs into ~/form-engine-mcp so it does not conflict with a
     *     parallel Promptless install in ~/promptless-mcp.
     *   - Claude Desktop config key is "form-engine-wordpress" — distinct
     *     from Promptless's "promptless-wordpress" so both connectors can
     *     coexist.
     *   - Uses Node.js itself to rewrite claude_desktop_config.json so no
     *     jq/sed dependency is required.
     *   - Password is passed via argv[2], NOT interpolated into the Node
     *     script, so it never appears in shell history.
     *   - Leading `;` separator between NODE_PATH assignments avoids the
     *     `&&` short-circuit bug Promptless documented.
     */
    function buildSetupCommand( username, password ) {
        var escapedPassword = password.replace(/'/g, "'\\''");
        var escapedSiteUrl  = data.siteUrl.replace(/'/g, "'\\''");
        var escapedUsername = username.replace(/'/g, "'\\''");

        return [
            'mkdir -p ~/form-engine-mcp && \\',
            "curl -fsSL -A 'WordPress/FormRuntimeEngine' '" + data.connectorScriptUrl + "' -o ~/form-engine-mcp/form-engine-connector.js && \\",
            'NODE_PATH=$(ls -d ~/.nvm/versions/node/v*/bin/node 2>/dev/null | sort -V | tail -1) ; [ -z "$NODE_PATH" ] && NODE_PATH=$(which node) ; \\',
            'CONFIG="$HOME/Library/Application Support/Claude/claude_desktop_config.json" && \\',
            'mkdir -p "$HOME/Library/Application Support/Claude" && \\',
            '"$NODE_PATH" -e \'' +
            'var fs=require("fs");' +
            'var p=process.env.HOME+"/Library/Application Support/Claude/claude_desktop_config.json";' +
            'var c;try{c=JSON.parse(fs.readFileSync(p,"utf8"))}catch(e){c={}}' +
            'c.mcpServers=c.mcpServers||{};' +
            'c.mcpServers["form-engine-wordpress"]={' +
            'command:process.argv[1],' +
            'args:[process.env.HOME+"/form-engine-mcp/form-engine-connector.js"],' +
            'env:{' +
            'FORM_ENGINE_SITE_URL:"' + escapedSiteUrl + '",' +
            'FORM_ENGINE_USERNAME:"' + escapedUsername + '",' +
            'FORM_ENGINE_APP_PASSWORD:process.argv[2]' +
            '}};' +
            'fs.writeFileSync(p,JSON.stringify(c,null,2))' +
            '\' "$NODE_PATH" \'' + escapedPassword + '\' && \\',
            'echo "" && echo "Setup complete. Quit Claude Desktop (Cmd+Q) and reopen it."'
        ].join('\n');
    }

    function showSetupCommand( username, password ) {
        var cmd = buildSetupCommand( username, password );
        document.getElementById( 'fre-setup-command' ).textContent = cmd;
        var container = document.getElementById( 'fre-setup-command-container' );
        if ( container ) {
            container.style.display = 'block';
        }
        var placeholder = document.getElementById( 'fre-setup-command-placeholder' );
        if ( placeholder ) {
            placeholder.style.display = 'none';
        }
    }

    async function post( action, extra ) {
        extra = extra || {};
        var body = new URLSearchParams( Object.assign( { action: action, nonce: data.nonce }, extra ) );
        var res = await fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } );
        return res.json();
    }

    function showStatus( id, text, ok ) {
        var el = document.getElementById( id );
        if ( ! el ) {
            return;
        }
        el.textContent = text;
        el.style.color = ( ok === false ) ? '#b32d2e' : '#2271b1';
        clearTimeout( el._t );
        el._t = setTimeout( function() { el.textContent = ''; }, 2500 );
    }

    // Both toggles share a handler — they map to different AJAX actions via data-attr.
    document.querySelectorAll( '[data-ajax-action]' ).forEach( function( cb ) {
        cb.addEventListener( 'change', async function() {
            var action = cb.dataset.ajaxAction;
            var enabled = cb.checked ? '1' : '0';
            var statusId = cb.id === 'fre-connector-enabled' ? 'fre-enabled-status' : 'fre-entry-read-status';
            try {
                var r = await post( action, { enabled: enabled } );
                if ( r.success ) {
                    showStatus( statusId, cb.checked ? i18n.enabled : i18n.disabled );
                } else {
                    cb.checked = ! cb.checked; // revert
                    showStatus( statusId, ( r.data && r.data.message ) || 'Error', false );
                }
            } catch ( err ) {
                cb.checked = ! cb.checked;
                showStatus( statusId, String( err ), false );
            }
        } );
    } );

    var genBtn = document.getElementById( 'fre-generate-password-btn' );
    if ( genBtn ) {
        genBtn.addEventListener( 'click', async function() {
            // No confirm() dialog — Promptless doesn't use one and the
            // blocking modal adds friction. Misclicks are recoverable
            // (just click Generate again — the prior password is already
            // revoked atomically server-side).
            var originalLabel = genBtn.textContent;
            genBtn.disabled = true;
            genBtn.textContent = i18n.generating;
            var r = await post( 'fre_connector_generate_password' );
            genBtn.disabled = false;
            if ( r.success ) {
                // Reveal the success notice in Step 1 card.
                var display = document.getElementById( 'fre-credential-display' );
                if ( display ) {
                    display.style.display = 'block';
                }

                // Build + reveal the setup command in Step 2 card.
                showSetupCommand( r.data.username, r.data.password );

                // Flip the status pill in the Connection Status card from
                // red "Not Connected" to green "Configured".
                var pill = document.getElementById( 'fre-connector-status-pill' );
                if ( pill ) {
                    pill.textContent = i18n.configured;
                    pill.classList.remove( 'fre-connector-status-inactive' );
                    pill.classList.add( 'fre-connector-status-active' );
                }

                genBtn.textContent = i18n.regenerate;
            } else {
                genBtn.textContent = originalLabel;
                window.alert( ( r.data && r.data.message ) || 'Error' );
            }
        } );
    }

    var copyBtn = document.getElementById( 'fre-copy-setup-command' );
    if ( copyBtn ) {
        // Capture original label so the restore-after-flash matches the
        // template's rendered text (e.g. 'Copy Command') instead of being
        // hardcoded to 'Copy'.
        var originalCopyLabel = copyBtn.textContent;
        var flashCopied = function() {
            copyBtn.textContent = i18n.copied;
            setTimeout( function() { copyBtn.textContent = originalCopyLabel; }, 2000 );
        };
        copyBtn.addEventListener( 'click', async function() {
            var pre = document.getElementById( 'fre-setup-command' );
            var cmd = pre.textContent;
            // Path 1: modern Clipboard API. Only available on HTTPS sites
            // and true localhost — NOT on HTTP custom hostnames like
            // `mysite.local`.
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                try {
                    await navigator.clipboard.writeText( cmd );
                    flashCopied();
                    return;
                } catch ( e ) { /* fall through */ }
            }
            // Path 2: legacy execCommand fallback. Works on HTTP.
            var sel = window.getSelection();
            var range = document.createRange();
            range.selectNodeContents( pre );
            sel.removeAllRanges();
            sel.addRange( range );
            try {
                var ok = document.execCommand( 'copy' );
                sel.removeAllRanges();
                if ( ok ) {
                    flashCopied();
                }
            } catch ( e ) { /* leave selection so user can Cmd+C */ }
        } );
    }

    var revokeBtn = document.getElementById( 'fre-revoke-password-btn' );
    if ( revokeBtn ) {
        revokeBtn.addEventListener( 'click', async function() {
            // Revoke IS destructive (Cowork loses access immediately) so a
            // confirm() is reasonable here even though we removed it from
            // Generate. Keeping it preserves the safety net for misclicks
            // on the destructive path.
            if ( ! window.confirm( i18n.revokeConfirm ) ) {
                return;
            }
            revokeBtn.disabled = true;
            var r = await post( 'fre_connector_revoke_password' );
            revokeBtn.disabled = false;
            if ( r.success ) {
                window.location.reload();
            } else {
                window.alert( ( r.data && r.data.message ) || 'Error' );
            }
        } );
    }
})();
