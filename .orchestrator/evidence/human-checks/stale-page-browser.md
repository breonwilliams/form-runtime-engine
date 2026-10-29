# Stale cached page — browser check (P4, Q-004)

Performed by Claude in the Claude desktop app's built-in browser, 2026-10-29, on
the Local site `https://ai-section-builder.local`, branch `feat/form-availability-rules`
at `f784ebf`. Requested by the human; not performed by the human.

1. Test form `hc-stale` (name + email, cap 5, closed message "HC: registration is full.")
   on a published test page `/hc-stale-page-test/`.
2. Loaded the page in the browser: the form rendered open (Name, Email, Submit).
3. Without reloading, set the form's cap to 0 server-side (WP-CLI). Fresh
   `PForms_Availability::check( "hc-stale" )` → `{"open":false,"reason":"cap_reached",…}`.
4. Filled Name and Email in the still-open page and clicked Submit.
5. Network (browser): `POST admin-ajax.php` (the page's `pforms_refresh_nonce`) → `{"success":true,"data":{"nonce":"…"}}`;
   `POST admin-ajax.php` (submit) → `{"success":false,"data":{"code":"form_closed","message":"HC: registration is full.","reason":"cap_reached"}}`.
6. DOM after the response: wrapper `fre-form fre-form--light fre-form--submitted`; fields and button hidden;
   `.fre-form__messages` (`role="alert" aria-live="polite" tabindex="-1"`) contains
   `.fre-form__message--closed` "HC: registration is full."; `document.activeElement` is `.fre-form__messages`.
   Screenshot: the page shows only the closed message where the form was.
7. Database after: 0 entries for `hc-stale`, place count 0.
8. Reloaded the page: server renders `div#fre-form-hc-stale.fre-form--closed.fre-form--submitted`
   with `data-availability="cap_reached"`, the message in the same `role="alert"` container, no `<form>`, 0 inputs/buttons.
9. Test form and page deleted afterwards.

Not covered: screen-reader announcement was inferred from the live-region markup and focus,
not heard with a screen reader; one browser engine (the desktop app's Chromium); no page-cache plugin was active.
