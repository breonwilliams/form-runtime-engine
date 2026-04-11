# Google Sheets Integration — Setup Guide

Send form submissions from Form Runtime Engine directly to a Google Sheet, for free, using Google Apps Script as a webhook receiver.

**Time to set up:** ~5 minutes
**Cost:** Free (uses your Google account)
**Requirements:** A Google account

---

## How It Works

```
User submits form → WordPress sends webhook → Google Apps Script receives it → Data written to Google Sheet
```

Form Runtime Engine's webhook feature sends a structured JSON payload on every submission. Google Apps Script can receive that payload and write it to any Google Sheet you own. Each form gets its own sheet tab automatically.

---

## Step-by-Step Setup

### 1. Create a Google Sheet

Go to [sheets.google.com](https://sheets.google.com) and create a new spreadsheet. Give it a name like "Form Submissions" or whatever makes sense for your project.

Copy the **Sheet ID** from the URL. It's the long string between `/d/` and `/edit`:

```
https://docs.google.com/spreadsheets/d/THIS_IS_YOUR_SHEET_ID/edit
```

### 2. Open Apps Script

In your Google Sheet, go to **Extensions → Apps Script**. This opens the script editor.

### 3. Paste the Template Code

Delete any existing code in the editor. Then paste the entire contents of the `apps-script-template.gs` file included with this plugin (located at `docs/google/apps-script-template.gs`).

**One thing to configure:** Near the top of the script, find the `SHEET_ID` constant. If you want the script to write to the spreadsheet it's attached to (the one you just created), leave it empty:

```javascript
const SHEET_ID = '';
```

If you want to write to a different spreadsheet, paste that spreadsheet's ID:

```javascript
const SHEET_ID = 'your-spreadsheet-id-here';
```

### 4. Deploy as a Web App

1. Click **Deploy → New deployment** (top right of the Apps Script editor)
2. Click the gear icon next to "Select type" and choose **Web app**
3. Set these options:
   - **Description:** Form Runtime Engine webhook (or anything you'll recognize)
   - **Execute as:** Me
   - **Who has access:** Anyone
4. Click **Deploy**
5. Google will ask you to authorize the script. Click **Review Permissions**, choose your Google account, and click **Allow**
6. Copy the **Web app URL** that appears. It looks like:
   ```
   https://script.google.com/macros/s/AKfycb.../exec
   ```

> **Important:** You must select "Anyone" for access. This allows the WordPress webhook to reach the endpoint without Google authentication. The script only accepts POST requests with valid JSON payloads — it doesn't expose any of your data.

### 5. Configure the Webhook in WordPress

1. Go to **WordPress Admin → Form Entries → Forms**
2. Edit the form you want to connect
3. Check **Enable webhook for this form**
4. Paste the Apps Script Web app URL into the **Webhook URL** field
5. Click **Save Form**

That's it. The next time someone submits that form, the data will appear in your Google Sheet.

### 6. Enable Request Signing (Recommended)

Request signing ensures that only your WordPress site can send data to your Google Sheet endpoint. Without it, anyone who discovers the URL could send fake submissions.

**In WordPress:**
1. When you enable the webhook, a **Webhook Secret** is auto-generated
2. Copy the secret from the form's webhook settings

**In Google Apps Script:**
1. Find the `WEBHOOK_SECRET` constant near the top of the script
2. Paste your secret:
   ```javascript
   const WEBHOOK_SECRET = 'paste-your-32-character-secret-here';
   ```
3. Save the script and deploy a new version (Deploy → Manage deployments → Edit → New version → Deploy)

How it works: WordPress signs every request body with HMAC-SHA256 using the shared secret. The signature is included in the `X-FRE-Signature` header. The Apps Script verifies the signature before processing the data.

> **Note:** Google Apps Script web apps have limited access to request headers. The template includes the verification infrastructure, but full header-based verification works best with custom endpoint deployments. Even without full header access, having the secret configured adds a layer of protection since the signing secret must match.

If you ever need to change the secret, click **Regenerate** in WordPress and update the Apps Script to match.

---

## What Gets Written to the Sheet

Each form creates its own sheet tab (named after the form ID). The columns are:

| Column | Source | Example |
|--------|--------|---------|
| timestamp | When the submission was received | 2026-04-11T14:30:00+00:00 |
| entry_id | WordPress entry ID | 42 |
| name | Form field | Jane Smith |
| email | Form field | jane@example.com |
| message | Form field | I'd like to learn more... |
| *(any other fields)* | Form fields | *(values)* |

Columns are created automatically from the first submission. If you add new fields to the form later, they'll be appended as new columns on the next submission that includes them.

---

## Testing the Connection

After setting up the webhook, submit a test entry through your form. Then check your Google Sheet — a new tab should appear with the form ID as the tab name, and your submission data in the first row.

If nothing appears:

1. **Check the webhook URL** — Make sure you copied the full Apps Script URL (starts with `https://script.google.com/`)
2. **Check the deployment** — In Apps Script, go to Deploy → Manage deployments and verify it's active
3. **Check permissions** — The deployment must be set to "Anyone" access
4. **Check the error log** — If `LOG_ERRORS` is enabled in the script, look for an "FRE_Errors" tab in your spreadsheet
5. **Check WordPress debug log** — Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php`, then check `wp-content/debug.log` for webhook errors

---

## Using One Endpoint for Multiple Forms

The template handles multiple forms automatically. Each form's submissions go to a separate sheet tab named after the form ID. You can use the same Apps Script URL for every form in your WordPress site.

---

## Updating the Script

If you need to update the Apps Script code (for example, after we release a new template version):

1. Open the Apps Script editor
2. Replace the code with the updated template
3. Go to **Deploy → Manage deployments**
4. Click the edit icon (pencil) on your existing deployment
5. Under "Version," select **New version**
6. Click **Deploy**

The URL stays the same — no changes needed in WordPress.

---

## Limitations

There are a few things to be aware of:

- **Google Apps Script execution time:** Each script run is limited to 6 minutes. For normal form submissions, this is more than enough (typical processing takes under 1 second).
- **Google Sheets cell limit:** Each spreadsheet supports approximately 10 million cells. If you receive very high volume, enable the `MAX_ROWS` setting in the script to rotate sheet tabs automatically.
- **Quotas:** Free Google accounts can handle approximately 20,000 URL fetch calls per day and 100 simultaneous executions. This is well beyond what most WordPress forms generate.
- **File uploads:** The webhook payload includes file metadata (name, size, type) but not the actual file contents. Files remain stored in WordPress.
- **Response codes:** Google Apps Script web apps always return HTTP 200, even on internal errors. The actual status is included in the JSON response body.

---

## Comparison: Google Sheets vs. Zapier

| | Google Sheets (Apps Script) | Zapier |
|---|---|---|
| **Cost** | Free | Free tier limited; paid plans start at $19.99/mo |
| **Setup** | ~5 minutes, copy-paste template | ~5 minutes, visual builder |
| **Maintenance** | You manage the script | Zapier manages infrastructure |
| **Flexibility** | Full JavaScript — do anything | Visual workflow builder |
| **Multi-step workflows** | Requires custom code | Built-in chaining |
| **Best for** | Simple logging, notifications, data collection | Complex multi-app workflows |

**Recommendation:** Use Google Sheets for straightforward "submissions → spreadsheet" workflows. Use Zapier or Make when you need to chain multiple services together (e.g., form → CRM → email sequence → Slack notification).

---

## Advanced: Customizing the Script

The template is designed to work out of the box, but you can customize it:

**Send an email notification from Google:**
```javascript
// Add this inside doPost(), after the sheet.appendRow(row) line:
MailApp.sendEmail({
  to: 'you@example.com',
  subject: 'New ' + payload.form.title + ' submission',
  body: 'Entry #' + payload.entry.id + '\n\n' +
        Object.keys(payload.data).map(function(key) {
          return key + ': ' + payload.data[key];
        }).join('\n')
});
```

**Filter submissions by form:**
```javascript
// Only process submissions from a specific form:
if (payload.form.id !== 'contact') {
  return _jsonResponse({ status: 'ignored', message: 'Not the target form' });
}
```

**Add to Google Calendar:**
```javascript
// If your form has a date field:
if (payload.data.appointment_date) {
  CalendarApp.getDefaultCalendar().createEvent(
    'Appointment: ' + payload.data.name,
    new Date(payload.data.appointment_date),
    new Date(payload.data.appointment_date)
  );
}
```

These are just examples. Google Apps Script has access to the full Google Workspace API — Calendar, Gmail, Drive, Docs, and more — so you can build whatever workflow makes sense for your project.
