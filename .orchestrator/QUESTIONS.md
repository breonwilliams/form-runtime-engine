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
