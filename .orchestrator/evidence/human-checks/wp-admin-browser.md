# wp-admin Forms list and Adjust count — browser check (P6)

Performed by Claude in the Claude desktop app's built-in browser, 2026-10-29, on the Local
site `https://ai-section-builder.local`, branch `feat/form-availability-rules` at `f784ebf`.
The human signed the browser pane in to wp-admin with their own account; Claude did not
enter credentials. Requested by the human; not performed by the human.

Fixtures: six forms from `admin-fixtures.php` (`hc-admin-plain`, `-capped` 3/10 with a
closing date, `-before`, `-past`, `-full` 2/2, `-uncap` cap removed with count 4). Site
timezone is UTC. Removed afterwards with `admin-cleanup.php`.

## Status column text — PASS
Page text in the signed-in browser (Form Entries → Forms) for each fixture:
- plain: "Open"
- capped: "Open / Closes July 20, 2099 at 5:00 pm UTC / 3 of 10 places taken / Adjust count"
- before: "Closed: not open yet / Opens January 15, 2099 at 9:00 am UTC"
- past: "Closed: past its closing date / Closed January 20, 2020 at 5:00 pm UTC"
- full: "Closed: entry limit reached / 2 of 2 places taken / Adjust count"
- uncap: "Open / 4 counted (no entry limit set) / Adjust count"
The two real forms (Workshop Registration, Commission a Piece) show "Open".

## Layout — FAIL (defect)
- At desktop width (viewport 1440×900): the Status column is ~1 character wide. The header
  reads "S t a t u s" vertically and every Status cell, including the Adjust count label and
  controls, wraps one character per line; rows become very tall.
  Cause: the table is `wp-list-table widefat fixed` (fixed layout) and the existing columns
  already take nearly all the width — `.column-form` 35% (assets/css/admin.css:188),
  `.column-shortcode` 30% (:206), `.column-modified` 20% (:210), `.column-entries` 100px (:346).
  P6's new `.column-availability` has no width rule, so it gets the near-zero remainder.
- At the pane's own width (~800px): the header wraps "Status Modified" onto a second row and
  each row's cells stack under the form name. Not compared with the pre-P6 table at this width.
- Side effect: the Adjust count button, the count field and Save sit almost on top of each other;
  clicking by element reference landed on the wrong control twice.
- Not checked: light/dark admin colour schemes (changing the admin colour scheme is a change to
  the human's user profile; not made).

## Adjust count save flow — PASS (Save), NOT VERIFIED (Reset button)
- Save: opened Adjust count on `hc-admin-capped`, entered 7, Save count → stored count 7
  (`PForms_Place_Counter::get`), page reloaded showing "7 of 10 places taken".
- Save 0: entered 0, Save count → stored count 0.
- Reset to 0 button: three clicks did not change the count. The handler returns unless
  `confirm()` is true (assets/js/admin.js:410); the built-in browser does not show/accept the
  dialog, so the Reset path could not be exercised here. Needs a person to click Reset → OK.

## Refused without a valid nonce — PASS
In the signed-in admin session (capability present):
- `admin-ajax.php?action=pforms_set_place_count&form_id=hc-admin-capped&count=5` (no nonce) → `-1`
- same with `nonce=0123456789` (wrong) → `-1`
- stored count stayed 0 after both.
Logged out (curl, no cookies): HTTP 400, body `0` (no `wp_ajax_nopriv_` handler; never reaches the plugin).

## Layout — FIXED (2026-10-29T20:39:30Z, commit daf499f)
Widths rebalanced in `assets/css/admin.css` (form 28%, shortcode 24%, `.column-availability` 20%,
modified 14%, entries 100px) and the Adjust count label stacked above its field; RTL regenerated.
Gate exit 0 (Unit 556 / 1517, Integration 192 / 1044 / 3 skipped). Re-checked by Claude in the
signed-in browser with the six fixtures recreated (then removed again):
- 1440px: five readable columns; capped row reads "Open / Closes July 20, 2099 at 5:00 pm UTC /
  3 of 10 places taken / Adjust count" at normal row height. The opened control shows "Places taken"
  above the field, "Save count" and "Reset to 0" beside it; clicking by element reference now hits the
  right controls; Save stored 6.
- 800px: five readable columns; Status text wraps over a few lines.
- 375px: WordPress's stacked small-screen layout, readable; header wraps ("Modifie d") — the table has
  no primary-column markup, so all its columns behave this way; not compared against the pre-P6 table.

## Reset to 0 → OK — PASS (2026-10-29T20:47:15Z)
**This step was performed by the human**, because the built-in browser does not accept `confirm()`.
Setup by Claude: test form `hc-reset-check`, cap 10, count set to 4; Forms list showed
"Open / 4 of 10 places taken / Adjust count". The human clicked Adjust count → Reset to 0 → OK and
reported the page showing 0. Verified by Claude afterwards: stored option row `'0'`;
`PForms_Place_Counter::get()` 0; `PForms_Availability::check()` → open, cap 10, count 0; page text
"0 of 10 places taken". Test form deleted (its count row with it); no `hc-` forms, count rows or entries remain.
