# DepEd DART — User Manual

**Document Administration and Routing Tool**, Office of the Schools Division Superintendent

This manual explains how to use DART day-to-day: logging in, submitting and reviewing outgoing documents, routing and acknowledging incoming documents, signing, releasing, and managing users. It's written for every role in the system — find your role in the table below and follow the workflow sections that apply to you.

---

## 1. Roles at a glance

| Role | What they typically do |
|---|---|
| **Originator** (staff) | Creates and submits outgoing documents for approval |
| **Unit Head** | Reviews outgoing documents from their unit; may also originate documents |
| **Division Chief** | Reviews outgoing documents from their division; routes incoming documents to their own staff |
| **ASDS** (Assistant Schools Division Superintendent) | Recommends outgoing documents for approval; routes incoming documents |
| **SDS** (Schools Division Superintendent) | Gives final approval on outgoing documents; routes incoming documents; is the identity behind every digital signature |
| **Secretary to the SDS** | Uploads incoming documents on behalf of the SDS's office; signs approved outgoing documents using the SDS's credential; routes incoming documents in lieu of the SDS. Assign this role only to the SDS's own secretary (in the OSDS or one of its sub-offices) — anyone with it can sign on the SDS's behalf |
| **Records Section staff** | Anyone assigned to the office the Admin designated as the Records Section. Applies the final release stamp to signed outgoing documents; uploads incoming documents; receives the SDS secretary's incoming uploads for filing; views the daily Incoming Documents Log |
| **Admin** | Manages user accounts, roles, divisions, and signing credentials; not part of the review chain |

Your role determines what you see in the navigation bar and what actions are available on a document — DART hides actions you're not authorized to take rather than showing an error after the fact.

---

## 2. Getting started

### Logging in
Go to the DART site and sign in with the email and password your administrator gave you.

- **First login / after a password reset**: you'll be forced to set a new password before anything else works.
- **Two-Factor Authentication (2FA)**: if your account requires it, you'll be sent straight to a setup page to scan a QR code with an authenticator app (Google Authenticator, Authy, etc.) before you can use the rest of the app. Save the recovery codes shown — they're the only way back in if you lose your device.
- **Weekend access**: DART is only available on weekdays for most roles. If you sign in on a Saturday or Sunday and you are not the SDS or an Admin, you'll see a restricted-access page instead of the dashboard.
- **Magic Link (SDS/ASDS only)**: an Admin can generate a one-time login link for the SDS or ASDS from the Users page. Opening that link logs you in directly — no password needed. For the SDS, this drops you straight into the **For Approval** tab of the Documents page instead of the dashboard, since that's usually what you opened the link to do.

### Navigating the app
The top navigation bar shows, depending on your role:

- **Dashboard** — your at-a-glance summary
- **Documents** — the main list of outgoing and incoming documents
- **My Signature** — upload your own signing certificate (SDS and Records Section only)
- **Sig. Credentials** — admin-only overview of everyone's signing credentials
- **Users** — admin-only user management
- A notification bell, and your name with a dropdown for **Profile** and **Log Out**

---

## 3. Outgoing documents (review & approval)

Use this when your office is producing a document (a memo, letter, endorsement, etc.) that needs to go up the chain for approval.

### 3.1 Submitting a document
Unit Heads, Division Chiefs, Detailed Officials, and the SDS's secretary can always create outgoing documents. A **Staff / Originator** can only do so if they are also tagged **KMT Focal** — unless an Admin has switched on **Allow All Originators** (see [Section 8](#8-user-management-admin-only)), in which case every Staff / Originator can.

1. From Documents, click **+ New Outgoing**.
2. Fill in the **Title** and a **Description/Purpose**.
3. Attach the document as a **PDF file** (max 10 MB). DART checks the actual file contents, not just the `.pdf` extension, so a renamed non-PDF file will be rejected.
4. Click **Submit for Review**.

### 3.2 Where it goes
DART automatically decides the starting review stage so nobody reviews their own submission twice:

- A regular staff member's document goes to their **Unit Head** first (if their division has one), then the **Division Chief**, then the **ASDS**, then the **SDS**.
- If a **Unit Head** submits their own document, it skips straight to Division Chief.
- If a **Division Chief** submits their own document, it skips straight to ASDS.
- If a division has no Division Chief, that stage is skipped automatically.
- If there is no Unit Head **and** no Division Chief above you (for example, staff in an OSDS office such as the Legal Unit, since the OSDS has no division chief), your document goes straight to the ASDS.
- The ASDS stage can be disabled entirely by an Admin, in which case documents go straight to the SDS.

The document's page shows a **Review Chain** stepper so you can see exactly which stages apply to this document and where it currently sits.

### 3.3 Reviewing a document
If a document is waiting on you, open it and use the **Submit Your Review** card. Your options:

| Your role | Primary action |
|---|---|
| Unit Head / Division Chief | **Review & Forward** — sends it to the next stage |
| ASDS | **Recommend for Approval** — sends it to the SDS |
| SDS | **Approve** — moves it to signing |

At every stage you can instead choose:
- **For Comment** — send it back to the originator with feedback, without fully rejecting it
- **Return for Revision** — send it back for corrections
- **Reject** — end the process (this is final; there is no resubmission after a rejection)

You can add optional **Remarks** with any decision. Every decision is recorded permanently in the **Review History** on the document, along with who made it, their role at the time, and when.

On mobile, you can also review directly from a **quick-preview** pop-up when you tap a document card in the list — you don't have to open the full document page first.

### 3.4 Fixing and resubmitting
If a document comes back **For Comment** or **Returned for Revision**, the originator will see a **Correct and Resubmit** option. Uploading a corrected file restarts the review chain from the beginning.

### 3.5 Signing (Secretary)
Once the SDS approves a document, it moves to **Pending Signature**. The **Secretary** role handles this:

1. Open the document and click **Attach Digital Signature** → **Open Signing Page**.
2. Position the SDS's e-signature image on the previewed PDF.
3. Enter the **SDS's** signing credential password (the Secretary is applying the SDS's own certificate on the SDS's behalf — the Secretary never has or uses a signature of their own).
4. Submit. The document becomes **Signed**, and a tamper-evident signed PDF is available to download.

### 3.6 Releasing (Records Section)
After a document is signed, Records Section staff apply a **second, separate stamp** — the office's official release stamp — before the document is considered fully released:

1. Open the document and click **Mark as Released**.
2. Enter your own signing credential password (Records Section staff upload their own certificate under **My Signature** ahead of time — this is a different credential from the SDS's).
3. Submit. The document becomes **Released**, and the final released PDF is available to download.

**After releasing, once you've actually downloaded the released PDF and emailed it out**, click **Mark as Emailed** on the document page (optionally add a note, e.g. who you sent it to). This is recorded in the document's review history, and the uploading office — the uploader and their Division Chief (or Unit Head, for OSDS sub-units) — is notified that it's been sent.

---

## 4. Incoming documents (routing & dispatch)

Use this for documents received from outside the office that need to be routed to the right people internally.

### 4.1 Uploading
Only the **SDS**, **Admin**, the **SDS's secretary**, or **Records Section** staff can upload an incoming document:

1. From Documents, click **+ Upload Incoming**.
2. Fill in **Title**, **Source/Origin**, **Reference Number**, and any **Remarks**.
3. Attach the PDF and submit.

**Automatic forwarding**: when the SDS's secretary uploads a document, a copy is automatically routed to the Records Section for filing. The SDS or the SDS's secretary still routes the document itself to the right division. (If no office has been designated as the Records Section yet, the secretary sees a warning and the Admin is notified.)

### 4.2 Routing
For anything not auto-forwarded, the **SDS**, **ASDS**, the **SDS's own secretary** (acting in lieu of the SDS), or a **Division Chief** (routing within their own division) routes the document:

1. Open the document and click **Route Document**.
2. Optionally flag it as **RUSH**.
3. Choose one or more recipients — individual users or whole divisions (a division-level assignment means anyone on that team can act on it).
4. Choose an **Action**: For Action, Transmittal, Attendance, Verification, Draft Letter, Comment, Information, Validation, Draft Indorsement, Recommendation, or Others (with your own label).
5. Add **Remarks** if needed, then **Route Now**.

Notes:
- A Division Chief can only route to their own division's staff, and only after acknowledging a document routed to them.
- The SDS and the ASDS can each route a given document **only once** — routing is not repeatable by the same person on the same document.
- Only active staff appear as routing candidates.

### 4.3 Acknowledging and completing
Recipients see their assignment under **Your Assignment** on the document page:

- Most recipients see a single **Acknowledge & Complete** button, with an optional response/remarks field.
- A Division Chief instead **Acknowledges** first, then either routes the document onward to their own staff or marks it **Complete** personally.

---

## 5. Dashboard, notifications, and search

- The **Dashboard** shows role-scoped counts (total outgoing/incoming, items awaiting your action, completions this month), status breakdown charts, and lists of pending and recent documents.
- The **Documents** page is organized into tabs: **Outgoing**, **Incoming**, and **For Action** for everyone; the **SDS** additionally gets **For Approval** and **For Routing** tabs; **Records Section** gets **Receiving**, **Acknowledged**, **Ready for Release**, and **Released** tabs.
- Use the search box to filter by title, reference number, source, or uploader.
- Documents flagged **RUSH** show a red badge in the list.
- The notification bell alerts you when a document needs your attention.

---

## 6. Previewing documents

- **Desktop**: click **View Document** to open a preview modal. Press `F` to toggle fullscreen, or use **Open in tab** to view it in a new browser tab. Press `Esc` to close.
- **Mobile**: tapping a document card opens a quick-preview panel that renders the PDF page by page, with the same Review/Route actions available inline — no need to navigate to the full document page.

---

## 7. Managing your signing credential

If your role can hold a signing credential (SDS, Records Section staff), go to **My Signature**:

1. Upload your **.p12 certificate file**.
2. Enter the certificate's password.
3. Optionally upload an image of your signature to be placed on signed documents.

DART reads and stores your certificate's subject, serial number, and validity dates automatically. An Admin can see everyone's credential status (valid, expiring soon, expired) on the **Sig. Credentials** page, and can revoke a credential if needed.

---

## 8. User management (Admin only)

From the **Users** page, an Admin can:

- **Add** or **edit** a user: set their name, email, role (Admin, Schools Division Superintendent, Assistant Schools Division Superintendent, Division Chief, Unit/Section Head, Staff/Originator, Secretary to the SDS, or Detailed Official), division, and whether they are **KMT Focal**.
- **Reset a password** — this sets a temporary default password and forces the user to change it on next login.
- **Activate / Deactivate** an account.
- **Delete** a user.
- **Generate a Magic Link** — SDS and ASDS accounts only (see [Section 2](#getting-started)).
- Toggle office-wide settings: enforce 2FA for everyone, force a password reset for all users, enforce email verification, enable/disable the ASDS review stage in the outgoing approval chain, and **Allow All Originators** — when on, every Staff / Originator can create outgoing documents without the KMT Focal tag; switching it back off restores the KMT-only rule (nobody's KMT Focal tag is changed either way).

### Offices (Admin only)
From the **Offices** page, an Admin sets up the SDO's structure. Three top-level offices are created at installation — the OSDS, CID, and SGOD; add sub-offices (Records Section, Legal Unit, ICT Unit, Personnel, and so on) under their parent office.

- **Designate the Records Section**: edit (or create) the office that handles receiving and releasing, and tick **This Office Is the Records Section**. Only one office can hold this at a time — ticking it on another office moves it. Until one is designated, the Offices page shows a warning, and signed documents cannot be released.
- **Allow SDS to Route Directly to This Unit's Head**: for sub-offices whose head should receive incoming documents straight from the SDS, like a division chief does.
- **Code**: optional short code used as the office prefix in outgoing control numbers (e.g. `OUTGOING-CID-2026-09-001`); a sub-office without its own code uses its parent's. The OSDS office's code is fixed and the OSDS office cannot be deleted, because the system relies on it.

---

## 9. Troubleshooting / FAQ

**"I can't log in on a Saturday/Sunday."**
By design — only the SDS and Admin accounts can use DART on weekends. Everyone else needs to wait until the next business day, or ask the SDS/Admin to act on anything urgent.

**"My uploaded file was rejected even though it's named `document.pdf`."**
DART verifies the actual file contents, not just the file extension. Make sure the file is a genuine PDF (open it in a PDF reader first) rather than a renamed image, Word document, or other file type.

**"I don't see a Route Document button on a document that was already routed."**
The SDS, ASDS, and the SDS's own secretary can each route a given document only once. If it needs to go somewhere else after that, a Division Chief who received it can route it onward within their own division.

**"I was rejected and want to fix and resubmit."**
Rejection is final — there is no resubmit option for rejected documents. **For Comment** and **Returned for Revision** are the two outcomes that allow a corrected resubmission.

**"I forgot my 2FA device / lost access."**
Use one of the recovery codes you saved during 2FA setup to log in, then set up 2FA again. If you don't have a recovery code, contact your Admin.
