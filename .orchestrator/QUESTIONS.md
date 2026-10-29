# Open questions

<!-- The orchestrator appends questions; a human writes the answers. To answer, replace
     "_(unanswered)_" after **Answer:** with your decision, then run the orchestrator again. -->

<!-- Q-001 … Q-008 were raised BEFORE the run (2026-10-29T16:23:34Z), while planning, from
     NEXT_PHASE_ROADMAP.md §3.11 "Not decided yet" and "Decide, and write down, which entries
     count toward the cap", plus three the code turned up. ROADMAP.md must stay "draft" until
     all eight are answered. Each lists a recommendation; it is a recommendation, not a
     default — an unanswered question is not answered by it. -->

## Q-001 · P2 · What counts toward the entry cap

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** When a form says "30 places", what is being counted — and can a place ever come back?

**Why it blocks:** P2's counter, P3's enforcement, the rulebook text (P5) and the admin controls (P6) all follow from it. Facts that shape it: Forms has no trash, so deleting an entry is permanent; FlowMint's `fre_delete_entries` step lets a retention workflow delete entries on a schedule; honeypot-caught spam is stored as an entry with `is_spam = 1` (up to 50 per form per hour) and can be restored with "Mark as Not Spam"; a form with `store_entries` off stores no rows at all; and non-dry-run `formengine_test_submit` calls create real entries.

**Options seen:**
1. **A counter of accepted submissions, kept apart from the entry rows** (recommended). Spam never takes a place; deleting entries — by hand, by the connector, or by a retention workflow — never reopens the form; it works with `store_entries` off. Cost: an owner can no longer free a place by deleting a test entry, so P6 needs an admin "adjust / reset count" control, and the count can drift from what the Entries screen shows (the Forms list should show the count, not imply it equals the rows). Sub-decisions this option needs, with my recommendation for each:
   - *Cap added to a form that already has entries:* start the count at the number of non-spam entries stored at that moment (recommended; shown to the owner, adjustable) — or start at 0 (cheaper, but "30 places" then means 30 more).
   - *Non-dry-run connector test submissions:* count, like any real entry (recommended; the rulebook tells agents to use `dry_run` on capped forms) — or exempt them (needs a way to tell them apart that a visitor cannot spoof).
   - *"Mark as Not Spam" on a capped form:* does not take a place and is allowed even when full (recommended; it is the owner's deliberate act) — or takes a place and is refused when full.
2. **Count stored, non-spam entry rows.** Simplest; deleting a test entry frees its place; what the Entries screen shows is the count. Cost: a retention workflow silently reopens a "full" form; a cap on a `store_entries`-off form is impossible (must be refused at save); "Mark as Not Spam" can push a form past its cap; counting rows atomically under concurrency is harder than claiming a counter (count-then-insert is the race §3.11 forbids, so the claim must lock something per form anyway).
3. **Counter, but admin deletion gives the place back while workflow/connector deletion does not.** Matches intuition for both cases. Cost: all three deletions go through `PForms_Entry::delete()`, so this needs the caller identified, more code, more tests, and a rule an agent must be told about. Highest cost for the least certain gain.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". A counter of accepted submissions, kept apart from the entry rows: spam never takes a place, and deleting entries (admin, connector or FlowMint retention) never gives a place back; works with store_entries off. Sub-decisions, all as recommended: (a) when a cap is first added to a form that already has entries, the count starts at the number of non-spam entries stored at that moment, shown to the owner and adjustable; (b) non-dry-run connector test submissions count like any real entry, and the rulebook tells agents to use dry_run on capped forms; (c) "Mark as Not Spam" does not take a place and is allowed even when the form is full. P6 provides an admin control to adjust or reset the count.

## Q-002 · P6 · Where the settings appear in wp-admin

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** Do owners set availability through the existing form JSON, or through dedicated fields on the form edit screen?

**Why it blocks:** P6's scope; it decides whether P6 is a status column or a small settings UI.

**Options seen:**
1. **JSON (like every other setting today) plus a read-only status on the Forms list, plus any count control Q-001 needs** (recommended for v1). Smallest; agents (the main way forms are made) are served by the connector anyway. Cost: a non-technical owner editing dates by hand in JSON, in a format they must get right; the validator's messages carry that.
2. **Dedicated fields (date-time pickers, cap, message) on the edit screen, writing into the same settings.** Friendlier. Cost: the first non-JSON settings UI in the Forms Manager, two editors for the same values that must not disagree, and screens the gate cannot check.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". Settings are edited in the form JSON like every other setting, plus a read-only open/closed status (with reason and count against cap) on the Forms list, plus the count adjust/reset control Q-001 requires. No dedicated settings fields in v1.

## Q-003 · P3 · Does a form closing fire an event?

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** When a form fills its last place (or passes its close date), should anything happen besides refusing later submissions?

**Why it blocks:** P3 builds it or not; P5 documents it.

**Options seen:**
1. **A `pforms_*` action when the last place is taken, nothing for date closure** (recommended). Exact and cheap: it fires inside the request that takes the last place. FlowMint or a site can listen later. Cost: a date closure has no moment at which code runs, so no event for it; a FlowMint trigger for the action is separate FlowMint work.
2. **The action plus an email to the notification recipients.** Owners learn the form is full without looking. Cost: another email template, and a setting to turn it off.
3. **Both cap and date events, date via WP-Cron.** Complete. Cost: cron is unreliable on low-traffic sites (it runs on visits), so the event fires late or never — a promise the stack can't keep.
4. **Nothing in v1.** Cost: none now; owners find out from the Forms list.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". A pforms_* action fires when the last place is taken (once, inside the request that takes it, after the entry is stored). No event for date closure, no email, no WP-Cron. A FlowMint trigger for the action is separate FlowMint work.

## Q-004 · P4 · What a visitor on a stale (cached) page sees when they submit

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** A page cached while the form was open still shows the form. When the visitor submits and the server refuses, what do they see?

**Why it blocks:** P4's front-end behaviour (the refusal itself is P3 and guaranteed either way).

**Options seen:**
1. **Replace the form with the closed message, the way the success confirmation replaces it** (recommended). Clear that it is over, nothing to retry, same focus/announcement treatment as confirmation. Cost: the visitor loses what they typed (they could not submit it anyway).
2. **Show the closed message above the form as an error, form left in place.** Cost: invites retrying a form that will never accept it.
3. Either of the above **plus a check on page load** that hides a closed form before the visitor types. Cost: an extra uncached request per form view on every page, for every form, to save a few typed fields; and it is still JS the gate cannot test.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". On a stale page, the server's closed refusal replaces the form with the closed message, the same way the success confirmation replaces it (same focus/announcement treatment). No page-load availability check.

## Q-005 · — · "N places left" in v1?

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** Should the form show how many places remain?

**Why it blocks:** Whether it is in this roadmap at all (it is currently out of scope).

**Options seen:**
1. **Not in v1** (recommended). The P1 filter and the P5 read-back expose the count, so it can be added later without touching the decision point. Cost: owners who want it wait.
2. **In v1.** Cost: on a cached page the number is wrong (stale) unless fetched live, which brings back Q-004 option 3's per-view request; a new phase.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". "N places left" is not in v1; it stays out of scope. The P1 filter and P5 read-back expose the count for later.

## Q-006 · P1 · One message for every "closed", or a separate one before opening?

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** §3.11 asks for "a custom message shown once the form is closed". Before the open date the form is also not accepting — should that say the same thing?

**Why it blocks:** P1 settings and P3/P4 messages.

**Options seen:**
1. **A separate optional before-open message, with a default that names the opening date and time** (recommended). "Closed" before something has opened misleads. Cost: one more setting to document and validate.
2. **One closed message for every reason.** Fewer settings. Cost: a registration that opens next week tells visitors it is closed.
3. Also separate messages for "full" vs "past the date". Cost: three messages; most owners will set one.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". A separate optional before-open message, with a built-in default that names the opening date and time in the site timezone. The custom closed message covers both full and past-close-date.

## Q-007 · P1 · Dates or date-times, and when on the close date does it close?

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** Is "closes 15 March" the start of the 15th, the end of it, or a specific time the owner sets?

**Why it blocks:** P1's format, validator and timezone tests.

**Options seen:**
1. **Date and time, in the site timezone (e.g. `2027-03-15 17:00`); a date with no time opens at 00:00 and closes at the end of that day (23:59:59)** (recommended). Matches "applications close 5pm Friday" and reads naturally when only a date is given. Cost: two accepted formats to validate and document.
2. **Date-time only, always required.** One format. Cost: owners and agents must always state a time.
3. **Date only, closing at the end of the day.** Simplest. Cost: cannot express a 5pm deadline.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". Date and time in the site timezone (e.g. 2027-03-15 17:00). A date given without a time opens at 00:00 and closes at the end of that day (23:59:59). Both formats are accepted, validated and documented.

## Q-008 · P3 · Connector test submits against a closed form

**Status:** answered
**Raised by:** planner (pre-run), 2026-10-29T16:23:34Z

**Question:** When an agent runs `formengine_test_submit` on a form that is closed, what happens — for a dry run, and for a real one?

**Why it blocks:** P3's programmatic path and P5's rulebook text.

**Options seen:**
1. **Both are refused with the closed reason; a dry run never takes or holds a place** (recommended). The tool then tells the truth about what a visitor would get. Cost: an agent cannot test field validation on a closed form without opening it (it can clear the rules, test, and set them back).
2. **Dry run validates normally and reports "would be refused: closed" alongside; real submit refused.** More useful for testing. Cost: a second response shape for agents and the rulebook to explain.
3. **Programmatic submissions bypass availability.** Cost: contradicts §3.11 ("on both paths"); not recommended.

**Answer:** Option 1 (the recommendation). Human, 2026-10-29T16:30:06Z, verbatim: "Accept all recommendations". On a closed form, formengine_test_submit is refused with the closed reason for both dry runs and real submissions, and a dry run never takes or holds a place.

## Q-009 · P1 · Built-in wording of the closed and before-open messages

**Status:** answered
**Raised by:** worker, 2026-10-29T16:57:37Z

**Question:** When the owner sets no message, what should visitors read? Today (P1 attempt 1): closed (full or past the close date) → "This form is no longer accepting submissions."; before opening → "This form opens on March 15, 2027 at 9:00 am." (site date/time formats, site timezone, no zone name).

**Why it blocks:** It does not block P1 (the worker reported DONE; each text is one translatable string in `PForms_Availability`). It should be settled before P4 shows these to visitors and P5 documents them.

**Options seen:**
1. Keep as is: matches the style of the existing `success_message` default.
2. Name the timezone in the before-open text ("9:00 am NZDT"): helps visitors in other zones; noisier for a local audience.
3. Different closed wording (e.g. "This form is closed."): shorter; less explicit that it once was open.

**Answer:** Human, 2026-10-29T17:07:03Z, verbatim: "Keep both defaults, with one change: the before-open message must name the timezone. \"This form opens on March 15, 2027 at 9:00 am ET\" — a time without a zone is ambiguous for anyone outside the site's timezone, and these are deadline-driven forms. Use whatever zone label WordPress gives for the site timezone, and carry it into the P4 brief." So: the closed default stays "This form is no longer accepting submissions."; the before-open default stays as worded and appends the zone label WordPress gives for the site timezone (the label is WordPress's, so it may read e.g. "EDT"/"EST" rather than "ET", or an offset on an offset-only site). Implemented in P4.

## Q-010 · P2 · What happens to the count when a cap is removed and later added back

**Status:** answered
**Raised by:** reviewer, 2026-10-29T17:23:34Z

**Question:** A form has a cap, fills up, and then its cap is removed (by the owner, or by a save that simply leaves the availability settings out). Later the cap is put back. Does the form remember how many places were already taken?

**Why it blocks:** P2 as built answers "no": removing the cap deletes the count, and putting it back recounts from the entries still stored. Example: a camp capped at 30 is full; a retention workflow deletes the old entries; the owner removes the cap for a day and restores it → the form reopens with up to 30 places. That contradicts Q-001 ("deleting entries never gives a place back"), and Q-001(a) only settles a cap added for the *first* time. Also: the connector's update replaces the whole form config, so an agent PATCH that omits `settings.availability` removes the cap and, as built, wipes the count with it. P2 cannot be approved until this is decided.

**Options seen:**
1. **Restart from the entries stored at that moment** (as built). Simple; matches the first-add rule. Cost: places come back for every deleted entry, the exact effect Q-001 ruled out; a careless agent update erases the count.
2. **Keep the old count, frozen while the form has no cap.** A re-added cap resumes where it stopped; a cap-less save never erases anything. Cost: submissions accepted while uncapped are not counted, so the form can admit more than the cap in total (e.g. cap 30 with 20 taken; cap removed and 10 more accepted; cap 30 restored → the count still says 20, so 10 more get in: 40 in all).
3. **Keep counting accepted submissions while the cap is off, once a form has ever had one.** A re-added cap is exact; a cap-less save never erases anything. Cost: one extra small database write per accepted submission on forms that once had a cap; slightly more for P3 to test.

In every option, P6's adjust/reset control lets the owner correct the number, and deleting the form itself removes its count (a re-created form is a new form, seeded under Q-001(a)) unless you say otherwise.

**Answer:** Option 3. Human, 2026-10-29T17:49:31Z, chose "Keep counting (3)", whose text reads: "Q-010: option 3. Once a form has had a cap, keep counting accepted submissions even while the cap is off, so a restored cap is exact and a cap-less save never erases the count. Deleting the form removes its count."

## Q-011 · P4 · Two locked tests pin the old before-open wording

**Status:** answered
**Raised by:** worker, 2026-10-29T18:28:57Z

**Question:** Your A-003 change (name the timezone in the default "This form opens on …" message) cannot be made without changing two tests written in P1, and the verification guard locks committed tests. How should those two tests change?

**Why it blocks:** Both assert the old sentence exactly — `tests/Unit/FormAvailabilityTest.php:484` and `tests/Integration/FormAvailabilityTimezoneTest.php:52` expect `'This form opens on March 15, 2027 at 9:00 am.'` with `assertSame`. Any zone label makes them fail. Workers cannot edit committed tests and neither can the orchestrator; only you can unlock the verification layer (a turn whose first line you type by hand as exactly `UNLOCK-VERIFICATION`, or your own edit followed by the re-baseline script in `.claude/hooks/` run in your own terminal). The rest of P4 (closed-form rendering, the stale-page JS) is built, green and parked on `orchestrator/P4-attempt-1`.

**Options seen:**
1. **Loosen the two assertions so they hold before and after the change** — assert the message starts with "This form opens on March 15, 2027 at 9:00 am" instead of equalling the whole sentence. The tree stays green at every step; the retry worker then adds the zone label and new tests that pin the exact new text (New York winter and summer, an offset-only site). Cost: those two P1 tests stop pinning the trailing text; the new P4 tests take that over.
2. **Change the two assertions to the exact new text** ("… at 9:00 am NZDT." for Auckland). Strictest. Cost: the tests are red until the message changes, so tests and code have to land in the same unlocked turn — the main session would write product code, outside the orchestrator's role, at your instruction.
3. **Withdraw A-003.** No test change. Cost: the before-open time stays without a zone, the ambiguity you objected to.

Whichever you choose, the test edit has to be made by you or in a turn you unlock, and it is recorded as an amendment.

**Answer:** Option 1. Human, 2026-10-29T18:35:57Z, chose "Loosen both (1)", whose text reads: "Q-011: option 1. Loosen both assertions to check that the message starts with \"This form opens on March 15, 2027 at 9:00 am\"; the P4 retry pins the exact new text with its own tests." Not yet applied: the two tests are still locked (no unlock in that turn). The edit is made in a turn the human unlocks, or by the human, before P4 is retried.
