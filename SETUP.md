# SLU Services at services.digitalsolucians.com

SLU Services is a public services app for Saint Lucia. It has its own GitHub repository (this one), its own database and its own folder on Hostinger (`public_html/services`), separate from the website and the CRM. It runs on your normal Hostinger plan (PHP + MySQL). Every push to `main` checks the code and uploads it.

**Residents can:**
- Find any public service across ministries, departments and statutory bodies. They can search, browse 14 topics and 12 life events ("Having a baby", "Starting a business"…), or go agency by agency.
- Read a plain-language page for each service: who can apply, what to bring (a checklist they can tick off), fees, how long it takes, steps, where to go, opening hours and contacts. Each page shows whether its details are confirmed by the agency or still come from public sources.
- Apply online where the agency accepts requests. They get a reference like `SLU-26-4F8K2Q`, follow progress, message the agency and upload documents.
- Use digiGov for services already offered there (the page links straight to it).
- Link a government ID once. Identity verifiers check it, and verified residents can then use services that need ID. **This only opens once the Government authorises it** (see D3).
- Change their address once and choose which agencies to tell (tell-us-once).
- Save services and get notified when the agency updates one.
- Download all their data, or close their account.

**Signing in is required.** Residents create their own accounts and confirm their email with a code. Government staff must use two-step sign-in (an authenticator app).

**Agencies keep their information up to date in whatever format they have:**
- Upload a spreadsheet (CSV or Excel), a Word or PDF document, a web page, JSON, or a photo of a form.
- Paste text, or give the address of a web page.
- Email the intake mailbox.
- Have their own systems call the API.

The app reads each submission and turns it into draft service pages. Spreadsheets are read column by column. Documents are read by Claude when AI reading is on, or by built-in rules when it's off. An officer then sees exactly which fields would change, edits anything, and approves before it goes live. Fields a submission leaves blank never wipe what's already on the page. Every change is versioned and recorded in the audit log.

**What's already in it:** 57 agencies and 68 services gathered from public government sources (govt.lc, the ministries' own sites, digiGov and published fee schedules). Each one is marked "awaiting agency confirmation" with a link to its source, until an agency approves its own information through intake. Fees that differed between sources are noted on the page. See [docs/REFERENCES.md](docs/REFERENCES.md) for the sources and the other government apps this design draws on.

---

## One-time setup (about 30 minutes)

Do the parts in order. Each one ends with a ✅ line telling you what you should see. If you see something else, or an error, send Claude a screenshot.

### Part A. Put the code on GitHub

**A1. Unzip the files**
1. Find `slu-services.zip` in your **Downloads** folder.
2. Right-click it › **Extract All…** › change the folder to `C:\Users\<your name>\Documents` › **Extract**.

✅ `Documents\slu-services` contains `index.php`, `cron.php`, `SETUP.md` (this file) and the folders `app`, `assets`, `data`, `docs` and `.github`.

**A2. Install GitHub Desktop**
1. Go to **https://desktop.github.com** and click **Download for Windows**. Run the installer.
2. When it opens, click **Sign in to GitHub.com**, sign in in the browser with the account that owns the **Digital-SoLucians** organization, and click **Authorize desktop**.
3. Back in GitHub Desktop, click **Finish**.

✅ GitHub Desktop shows "Let's get started!".

**A3. Turn the folder into a repository**
1. In GitHub Desktop, click **File** › **Add local repository…**.
2. Click **Choose…**, pick `Documents\slu-services`, and click **Select Folder**.
3. The box says "This directory does not appear to be a Git repository." Click the blue **create a repository** link under it.
4. Check the form:
   - **Name:** `slu-services`.
   - **Initialize this repository with a README:** leave it **unticked**.
   - **Git ignore** and **License:** leave them as **None**.
5. Click **Create repository**.

✅ The left side says "No local changes" and the **History** tab shows one commit, "Initial commit".

**A4. Publish it to GitHub (private)**
1. Click **Publish repository** in the top bar.
2. Fill in the window:
   - **Name:** `slu-services`.
   - **Keep this code private:** leave it **ticked**.
   - **Organization:** choose **Digital-SoLucians**.
3. Click **Publish repository**.

✅ **https://github.com/Digital-SoLucians/slu-services** shows the files.

The **Actions** tab shows a run called **Deploy to Hostinger** with a green tick. Click it to see a yellow note, "The FTP secrets aren't set yet". That's expected: Part B fixes it.

### Part B. Let GitHub upload to Hostinger

**B1. Create the subdomain**
1. Log in to **hPanel** › **Websites** › **digitalsolucians.com** › **Dashboard**.
2. Open **Domains** › **Subdomains**.
3. Create the subdomain **services**. Make its folder **`public_html/services`** (untick "Custom folder for subdomain" if hPanel suggests a different one).
4. Open **Security** › **SSL** and make sure **services.digitalsolucians.com** has a certificate. Install the free one if it's missing.

✅ Under **Subdomains**, `services.digitalsolucians.com` shows the directory `public_html/services`.

**B2. Make an FTP account just for this app**
This account can only reach the app's own folder. Don't change the main FTP password: the main website and infinite-sports.org use it.
1. In hPanel, open **Files** › **FTP Accounts**.
2. Under **Create a new FTP account**, fill in:
   - **Username:** `slu`.
   - **Password:** click **Generate** and **copy it into a note**.
   - **Directory:** type `services`. The box may already start with `public_html/`; the full path should end in `public_html/services`.
3. Click **Create**.
4. At the top of the same page, under **FTP details**, copy the **FTP IP** (it looks like `123.45.67.89`) into your note.
5. In the list of accounts below, copy the full username of the new account. It has a prefix, like `u123456789.slu`.

✅ Your note has three things: the FTP IP, the full username (with its `u…` prefix) and the password.

**B3. Give the three values to GitHub**
1. Open **https://github.com/Digital-SoLucians/slu-services** › **Settings** (the tab at the top right) › **Secrets and variables** › **Actions**.
2. Click **New repository secret**.
3. In **Name**, type the first name below exactly. In **Secret**, paste the matching value from your note. Click **Add secret**.
4. Repeat steps 2 and 3 for the other two.

```
FTP_SERVER
```
The FTP IP, for example `123.45.67.89`.

```
FTP_USERNAME
```
The full username, for example `u123456789.slu`.

```
FTP_PASSWORD
```
The password you generated.

✅ **Repository secrets** lists `FTP_PASSWORD`, `FTP_SERVER` and `FTP_USERNAME`.

**B4. Upload the app**
1. Open the **Actions** tab.
2. Click **Deploy to Hostinger** on the left.
3. Click the grey **Run workflow** button on the right, then the green **Run workflow** button.
4. Wait 1–2 minutes and refresh the page.

✅ The new run has a green tick. Inside it, the **Upload to Hostinger** step ends with "Upload finished to ./". In hPanel › **Files** › **File Manager**, `public_html/services` now contains `index.php`, `cron.php`, `.htaccess` and the folders `app`, `assets` and `data`.

### Part C. Database and first sign-in

**C1. Create a database (a new one, not the CRM's)**
1. In hPanel, open **Databases** › **Management**.
2. Under **Create a new MySQL database and database user**, fill in:
   - **MySQL database name:** `govt`.
   - **MySQL username:** `govt`.
   - **Password:** click **Generate** and **copy it into a note**.
3. Click **Create**.

✅ The list shows a database like `u123456789_govt` and a user like `u123456789_govt`. Write both down exactly as shown.

**C2. Run the setup page**
1. Open **https://services.digitalsolucians.com**. You'll see **Set up SLU Services**.
2. Enter the database name, user and password from C1.
3. Create the **owner** account: your name, your email, and a password of 10+ characters that doesn't contain your email address.
4. Click **Create SLU Services**.

✅ You're signed in. The page only appears once. The settings are saved to `data/config.php` on the server, which is never shown to browsers and never stored in git.

### Part D. Inside the app

**D1. Turn on two-step sign-in**
The console won't open until you do:
1. Click **Set up two-step sign-in** and add the key to Google Authenticator, Microsoft Authenticator, 1Password or similar.
2. Enter the 6-digit code.
3. **Save the 8 recovery codes** somewhere safe. Each one signs you in once if you lose your phone.

✅ The page says "Two-step sign-in is on", and **Console** in the top menu opens the government console.

**D2. Background tasks (cron)**
1. In the app open **Console** › **Settings** and copy the command under **Background tasks**. It looks like `php /home/u123456789/domains/digitalsolucians.com/public_html/services/cron.php`.
2. In hPanel, open **Advanced** › **Cron Jobs**.
3. Choose the **Custom** type and paste the command into the command box.
4. Set the schedule:
   - **Minute:** `*/15`
   - **Hour**, **Day**, **Month** and **Weekday:** `*`
5. Click **Save**.

Every 15 minutes it reads the intake mailbox, finishes any intake that was interrupted, deletes ID photos once their retention period ends, and clears expired sign-in codes.

✅ After 15 minutes, **Console** › **Settings** › **Background tasks** shows a "Last run" time instead of "never".

**D3. Record the Government's clearance**
Open **Console** › **Clearance**. This setting controls what residents can do:

| Status | What residents see |
| --- | --- |
| **Not yet authorised** (start here) | A "Preview: not yet an official Government of Saint Lucia service" notice. They can find services and apply where no ID is needed. ID linking is closed. |
| **Pilot** | A pilot notice. ID linking is open only to staff and the testers you tick in **Console** › **People**. |
| **Authorised** | The notice disappears and ID linking opens to everyone. You must record the approving authority, its reference (for example the Cabinet conclusion or letter) and the date. |
| **Suspended** | ID linking pauses. Existing verifications stay. |

Every change is kept in the history on that page and in the audit log.

**D4. Add government staff**
In **Console** › **People** › **Add a staff member**:
- **Agency officer:** handles their own agency's requests, service pages, intake, notices and API keys. Choose their agency.
- **Identity verifier:** checks linked IDs. The Government appoints these people.
- **Platform admin:** everything, including clearance and settings.

Each person gets an email with a temporary password. When they first sign in, they choose their own password and set up two-step sign-in.

**D5. Optional: AI reading for intake**
Without it, spreadsheets and structured documents work fully. Free-form documents are read by built-in rules, and scanned PDFs and photos can't be read.

With it:
1. Create an API key at console.anthropic.com.
2. Paste it in **Console** › **Settings** › **AI reading for intake**. The key is stored encrypted.
3. The default model is Claude Opus 5.5. Sonnet 5.5 and Haiku 5.5 are cheaper choices.

Claude reads Word, PDF, web pages, pasted text and photos of forms. It is told to use only facts stated in the material and to leave anything unstated empty. Every draft still needs an officer's approval.

**D6. Optional: the intake mailbox**
1. In hPanel open **Emails** and create a mailbox, for example `services@digitalsolucians.com`.
2. In **Console** › **Settings** › **Intake mailbox**, enter:
   - **IMAP server:** `imap.hostinger.com`
   - **Port:** 993
   - **Security:** SSL
   - The mailbox address and its password
3. Click **Save**, then **Check now**.

The app reads mail from three kinds of sender, and ignores (and logs) everything else:
- Staff accounts.
- Agencies' listed email addresses.
- The domains you list (default `govt.lc`).

Each email body and each attachment becomes its own submission.

Email "From" addresses are easy to fake, so the app also checks whether Hostinger's mail server verified the sender (a DKIM or DMARC pass):
- **Verified:** the submission goes to that agency's officers.
- **Not verified:** it waits for a platform admin, marked **Sender not verified**, and each draft has to be reviewed on its own ("Approve all" is turned off).

If an agency's emails always show as unverified, ask its IT team to set up DKIM for its domain.

---

## How agencies send updates

| Channel | Where | Best for |
| --- | --- | --- |
| Spreadsheet | Console › Intake › Upload (download the CSV template there) | Many services at once, fee schedules |
| Document | Console › Intake › Upload | Word/PDF notices, brochures, scanned forms (AI reading) |
| Paste | Console › Intake › Paste text | A quick correction |
| Web page | Console › Intake › Web page | The agency's own website |
| Email | The intake mailbox | Staff who work from email |
| API | `POST /api/v1/intake` with a key from Console › API keys | An agency's own systems |

API example (keys are per agency and shown once):

```bash
curl -X POST https://services.digitalsolucians.com/api/v1/intake \
  -H 'Authorization: Bearer slk_…' -H 'Content-Type: application/json' \
  -d '{"services":[{"title":"Apply for a fishing licence","fees":"Annual: EC$50","requirements":"Photo ID; Completed form"}]}'
```

The API also accepts the following, up to 60 requests an hour per key:
- `{"text": "…"}`
- `{"url": "https://…"}`
- A file upload in the `file` field.

`GET /api/v1/services` lists the agency's published services, and `GET /api/v1/intake/{id}` shows a submission's status. An admin can mark a key "publish without review" for trusted systems that send structured data.

## Privacy and security in short
- **ID numbers** are encrypted (AES-256-GCM), with only the last four digits shown.
- **ID photos and uploads** are encrypted in `data/vault`.
- **Who sees ID details:** only verifiers and platform admins can open an ID case, and every case and photo view is logged.
- **ID deletion:** 30 days after a decision (configurable) the photos, the full number and the name and date of birth from the ID are deleted. A one-way fingerprint stays so one ID can't be linked to two accounts.
- **Server-only files:** `data/` and `app/` are never served (`.htaccess`), and `data/` is never committed to git.
- **Staff accounts** need two-step sign-in. Sign-in attempts are rate-limited, and every staff action is in **Console** › **Audit log**.
- **Officers** only see their own agency's requests, notices and intake.
- **Residents** can download everything held about them, or close their account. Closing it deletes their ID photos and personal details.

## Running it locally
```bash
php -S 127.0.0.1:8100 index.php
# open http://127.0.0.1:8100 (needs PHP 8.1+ with pdo_mysql, openssl, zip, dom, curl, and a MySQL/MariaDB database)
```

## Changing the app later
Every change pushed to `main` is checked and uploaded automatically. In GitHub Desktop:
1. Write a summary in the box at the bottom left.
2. Click **Commit to main**.
3. Click **Push origin**.

Nothing on the server is ever deleted by an upload, so your settings and residents' documents in `data/` are safe.
