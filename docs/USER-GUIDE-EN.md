# User Guide — Logbook

Welcome to **Logbook**, an application for recording and monitoring the supervision of students' Final Projects (Tugas Akhir / Thesis). This guide explains all application features and the working mechanisms (workflows) for each user role.

---

## Table of Contents

1. [About the Application](#1-about-the-application)
2. [User Roles](#2-user-roles)
3. [Detailed Features Overview](#3-detailed-features-overview)
4. [Role-based Workflows](#4-role-based-workflows)
5. [Frequently Asked Questions (FAQ)](#5-frequently-asked-questions-faq)

---

## 1. About the Application

This application helps supervising/examining lecturers and students monitor the Final Project supervision process digitally. Key features include:

- **Supervision logbook recording** — students record each supervision session, including progress and obstacles.
- **Review workflow** — lecturers approve or request revisions on logbook entries.
- **Continuous revision** — improvements from review results are recorded as separate revision entries.
- **File workspace** — students store and share thesis documents (chapters, revisions, etc.) in one place.
- **Chat & communication** — direct messaging between lecturers and students.
- **Announcements** — official announcements sent to all students.
- **Examination records & examining history** — lecturers record examination/session results.
- **Seminar/examination material submission** — students send invitation letters & materials for seminars/examinations to lecturers.
- **Thesis/Internship finalization** — students submit final requirements (abstract, cover, approval page, full file) for lecturer approval.
- **Notifications & supervision health tracking** — indicators of supervision regularity.

### Application Modes

The application runs in one of two modes (configured by the administrator):

| Mode | Description |
|---|---|
| 🌱 **Individual** (default) | Used by **a single lecturer** who records the supervision and examination of their own students. Students can self-register and are then approved by the lecturer. |
| 🏛️ **Institution** | Used by a study program/institution that manages **many lecturers**, students, supervisors, examiners, examination sessions, and official reports. |

The current mode is shown at the top of the sidebar menu, for example the **"Individual"** or **"Institution"** label.

---

## 2. User Roles

| Role | Description |
|---|---|
| **System Admin** | Highest role — manages other admin accounts (create, reset password, delete), plan/subscription settings, and has full access to all admin menus. |
| **Admin** | Manages users, thesis data, bulk review, examination data, and institution settings. Cannot manage other admins. |
| **Lecturer (Dosen)** | Supervises and examines students, reviews logbooks, records examination sessions, and approves student registrations. |
| **Student (Mahasiswa)** | Records supervision logbooks, uploads revisions, manages the workspace, and monitors thesis progress. |

---

## 3. Detailed Features Overview

The following describes the features available in the application, regardless of role (per-role availability is explained in Section 4).

### 3.1 Authentication

- **Login** — Sign in with email and password.
- **Email verification** — Every new account must **verify their email address** before full access. After registration, the system sends a verification email; users are directed to a verification page and can resend the link if needed.
- **Student self-registration** — Students can register themselves (name, email, password). After email verification, the account is set to **active** (not yet linked to a lecturer). Students then **select a lecturer** (supervisor/examiner) via the **"Select Lecturer"** page; the selected lecturer approves or rejects the attachment request.
- **Student registration statuses** — `active` (email verified, no lecturer), `verified` (approved by a lecturer), `rejected` (denied, cannot log in).
- **Automatic cleanup** — Students with `active` status who do not select a lecturer within 1 month are automatically removed by the system (scheduled daily).
- **Forgot password** — Reset your password via email. The displayed message is uniform to prevent email enumeration.
- **Logout** — The logout button is available in the profile menu.

### 3.2 Dashboard

Each role has a summary dashboard:

- **Admin**: statistics for students, lecturers, thesis data, and entries awaiting review; Excel student import; list of recent thesis data.
- **Lecturer**: statistics for total supervision, in progress, completed, examined, and awaiting review; review queue; health indicators; thesis phase management.
- **Student**: unread announcements, supervision health status, milestone journey (phases), thesis title & supervisors, supervision progress, achievements, statistics & streaks, 12-month activity heatmap, and supervision timeline.

> **💡 Separated Admin & Lecturer Dashboards** — If your account has **both admin and lecturer** roles, the admin and lecturer dashboards are **not combined**. You can switch modes by **clicking your profile picture** in the top-right corner → select **"Dashboard Mode"** → **Lecturer** or **Admin**. The selected mode is persisted for the session.
>
> **🔒 Hidden "Admin" Label** — The "admin" and "system_admin" role labels are **never shown** on any profile page (your own or others'). Administrative status remains private.
>
> **🛡️ System Admin** — The **System Admin** role has full access to all admin menus **plus** a dedicated **"Manage Admins"** menu (creating, resetting passwords, and deleting admin accounts) and **Plan/Subscription** settings. A regular **Admin** cannot manage other admins.

### 3.3 Supervision Logbook

A logbook entry records one supervision session.

- **Entry status**: `Draft` → `Submitted` → `Approved` or `Revision`.
- **Entry type**: `Logbook` (supervision session) and `Revision` (improvements from review).
- **Revision round limit**: maximum 3 rounds per entry (a warning appears when reached).
- **Auto-save draft**: logbook input is saved automatically in the browser (localStorage) every 5 seconds.
- **Filters**: status, type, date range, and keyword.

### 3.4 PDF Review & Annotation

- Attached documents (PDF) can be opened in a **PDF viewer**.
- Lecturers can **highlight areas** on the PDF and **add comments** (annotations) per page.
- Comments have an **open/resolved** status.
- Lecturers can **build feedback automatically** from unresolved comments.

### 3.5 File Workspace

- Students can **upload files** (PDF, DOC, DOCX, XLS, XLSX) — max 25 MB, up to 5 files at once, with *drag & drop*.
- Files are tagged with a **Chapter label** (optional) and **notes** (description).
- **Filters** by chapter, file type, and keyword.
- **Preview** for PDFs, **download** for all files.
- Students can **edit metadata** (chapter, notes) and **delete** files.
- **Storage usage** (MB) indicator is displayed.

### 3.6 Chat

- Direct messaging between lecturers and students.
- Lecturers can start a conversation from the **student detail page**.
- The conversation list shows the counterpart, number of unread messages, and the related thesis.
- Messages can be **attached** to related entities (attachment option).

### 3.7 Announcements

- Announcement creators (lecturer/admin) can create announcements sent to students.
- **Students** see unread announcements on the dashboard and can **mark them as read**.
- Creators can view the **report** (number sent, number read) and **remind** those who have not read.

### 3.8 Notifications

- Notifications are received for important activities (new entries, status changes, PDF comments, etc.).
- The **notification bell** in the header shows the unread count; a **dropdown** and **all-notifications page** are available.
- **Real-time notifications** (via Reverb/Pusher) show a toast when entry status changes or a PDF comment is added.

### 3.9 Examination Records & Examining History

- Lecturers record **examination sessions** (Proposal Seminar / Final Examination) for supervised students **or other students** (outside the system).
- Recorded data: student, session type, date, result (Pass / Pass + Revision / Repeat), and supervisor names (max 3).
- **PDF export** of examining history for portfolio/BKD purposes.
- A Final Examination with a **Pass / Pass + Revision** result automatically marks the student as **completed (tamat)**.

### 3.10 Supervision Scheduling

- The **"Schedule Supervision"** page displays a list of lecturers with their supervision schedule links.
- Click a lecturer card to open their schedule link (in a new tab).

### 3.11 Global Search (Cmd+K)

- Quick search across all data (students/lecturers, entries, workspace files).
- Press `Cmd+K` (Mac) or `Ctrl+K` (Windows/Linux), or click the search field in the header.

### 3.12 Profile & Settings

- Every user can update their **profile** (photo, WhatsApp/Telegram/LinkedIn contacts, etc.) and **password**.
- Other users' profile pages can be viewed (linked from various pages).

### 3.13 Supervision Health Indicator

Supervision regularity is calculated from the last supervision date:

| Status | Meaning |
|---|---|
| 🟢 **Healthy** | Last supervision < 15 days |
| 🟡 **Attention** | 15–40 days |
| 🔴 **Critical** | > 40 days or never supervised |

- Lecturers see this indicator on the dashboard (with filters).
- Students see their own supervision health status.
- Students who are inactive for too long may receive an **inactivity email** (marked with a ⚠ icon).

### 3.14 Milestone Journey & Achievements

- **Milestone Journey** shows the student's thesis phase trajectory: Proposal → Data Collection → Analysis → Results Seminar → Examination Draft → Examination → Achievement Unlocked.
- **Achievements** (badges) unlock when a student reaches certain milestones, displayed on the student dashboard.

### 3.15 Import & Export

- **Student import (Excel)** — admin (institution) can bulk-import student data (name, NIM, email, supervisor1_nidn, supervisor2_nidn) with a default supervisor.
- **Summary export** — supervision summary in **PDF** and **Excel** per student.
- **Examining history export** — PDF for lecturers.

### 3.16 Thesis Phase & Status Management

- The thesis **phase** can be updated by the lecturer (from the dashboard or the student detail page).
- **Thesis status**: `Active`, `Completed`, `Inactive` — managed by the admin.

### 3.17 Seminar/Examination Material Submission

Students send **seminar/examination materials** (invitation + presentation material) to supervising/examining lecturers:

- **Automatic type** — the seminar type (Proposal Seminar, Results Seminar, Final Examination, KP Seminar) is determined automatically from the current thesis/KP phase.
- **Data to fill**: date, time, location, invitation letter (file), "invitation as" option (Supervisor 1/2 or Examiner 1/2), and material.
- **Material** can be uploaded directly **or selected from the workspace files** (one is required).
- **Hardcopy note** — lecturers can add/update a hardcopy note on the submission.
- **Notifications** — related lecturers (supervisors & examiners) receive a notification when materials are sent.
- **Edit** — students can modify the submission as long as it has not been converted to an examination record.
- **Convert to examination record** — lecturers can convert the submission into an examination record (selecting examiner & result).

### 3.18 Thesis/Internship Finalization

Students submit **final requirements** for TA/KP approval by supervising lecturers:

- **TA items**: Abstract, Keywords, Cover, Approval Page, and Full File (PDF).
- **KP items**: Full File (PDF) only.
- **Approval workflow** — each item must be **approved by both supervisors** (Supervisor 1 & 2) before it is considered final.
- **Rejection & unlock** — lecturers can reject an item (status becomes `rejected`) or unlock an already-approved item.
- **Grade input** — lecturers can enter the final grade (0–100).
- **Automatic milestone** — if all items are approved and the TA phase is `sidang`, the phase automatically advances to **Achievement Unlocked**.

### 3.19 Organizational Directory (University → Faculty → Department → Study Program)

The application has a **hierarchical organizational directory** to map user affiliations:

```
University (universities)
  └── Faculty (faculties)
        └── Department (departments)
              └── Study Program (study_programs)
```

- **Automatic deduplication** — a university name never appears twice; faculty/department/study program are unique within their parent (unique constraints).
- **Lecturer registration** — lecturers register with their **NIDN** and select/create their university (if it already exists, it is reused; if not, it is created).
- **Multi-university** — a lecturer can be affiliated with **multiple universities**.
- **Students automatically follow their supervisor's institution** — when a lecturer invites or approves a student, the lecturer's university is automatically copied to the student (no re-entry).
- **Display** — the university is shown on the dashboard (lecturer & student), sidebar, and profile page.

### 3.20 Lecturer Groups & Cross-linking

Lecturers can form **groups** for collaboration and cross-linking with other lecturers at the same university.

- **Group levels**: University, Faculty, Department, or Study Program.
- **Create group**: a lecturer creates a group and automatically becomes the owner.
- **Invite colleagues**: a lecturer invites other lecturers from the same university (data is not re-entered — selected directly from the directory).
- **Approval**: the invited lecturer must **approve** the invitation before becoming a member.
- **Pending invitations**: lecturers see pending invitations on the **Lecturer Groups** page and can accept/reject them.
- **"Direct relation only" access**: lecturers in the same group (or sharing a thesis) can view each other's supervision data — data is only accessible when there is a direct relationship.

### 3.21 Lecturer Workspace

In addition to the student workspace, lecturers also have a **personal workspace** via the **My Workspace** menu:

- Upload personal files (PDF, DOC, DOCX, XLS, XLSX) — max 25 MB, up to 5 files at once.
- Manage files with chapter labels and notes.
- Filter & search files.
- Only the lecturer concerned can access their personal workspace files.

### 3.22 Dashboard & UI (Institution & Groups)

- The **lecturer dashboard** shows the **"Institution & Groups"** card: university (NPSN), NIDN, and the number of groups joined.
- The **student dashboard** shows the **"University"** card.
- The **sidebar** shows the user's primary university badge.
- The **profile** shows the NIDN (lecturer) and university.

---

## 4. Role-based Workflows

### 4.1 Student Workflow

#### 4.1.1 Registration & Selecting a Lecturer

1. Open the **Register** page.
2. Fill in **name**, **email**, and **password**.
3. Submit → the system sends a **verification email**. Open the email and click the verification link.
4. After verification, you can log in. Your account is **active** (not yet linked to a lecturer).
5. On the dashboard, click the **"Select Lecturer to Start a Program"** banner (or the **Select Lecturer** menu).
6. Choose the **program type** (TA/KP), **current phase/milestone**, **Supervisor 1** (required), and optionally **Supervisor 2**, **Examiner 1/2** → click **Send Request**.
7. The selected lecturer will **approve** or **reject** the request. Once approved, your program becomes **active** and thesis data becomes available.
   - If rejected, you can select another lecturer.
   - If you do not select a lecturer within 1 month, your account may be automatically removed.

#### 4.1.2 Recording a Supervision Logbook

1. Go to **Dashboard** → click **+ Logbook** (or the **Add Logbook** menu).
2. The **session** number is filled automatically (next session).
3. Fill in **Supervision Date**, **Supervision Topic**, and **Summary of Improvements** (progress & obstacles).
4. (Optional) Attach a file (PDF, etc.).
5. Choose:
   - **Save Draft** — saves as a draft; can be edited and submitted later.
   - **Send to lecturer** — sends directly to the lecturer for review.
6. Input is saved automatically (auto-save) every 5 seconds; the draft is restored if the page is closed.

#### 4.1.3 Responding to Lecturer Review

1. When the lecturer requests a revision, the entry status becomes **Revision**.
2. Open the entry details → click **Create Revision from This Feedback**.
3. Fill in the improvement summary and upload the revision (you can upload a **Correction Notes** file).
4. Send to the lecturer → the lecturer will review again.

#### 4.1.4 Managing the Workspace

1. Open the **Workspace** menu.
2. Click the upload zone (or drag & drop files) — max 5 files, 25 MB per file.
3. Fill in the **Chapter** label (optional) and **Notes**.
4. Click **Upload** → the files appear in the list, grouped by chapter.
5. Use **filters** (chapter, type, keyword) or **search** to find files.
6. Click the **edit** icon to change metadata, or **delete** to remove a file.

#### 4.1.5 Communicating & Monitoring

- **Chat** with your supervising lecturer.
- Read **announcements** and **notifications** regularly.
- Monitor your **milestone journey**, **supervision progress**, and **health indicator** on the dashboard.
- Download your **PDF/Excel summary** of supervision.

#### 4.1.6 (If an Examiner) Recording Examination Sessions

If your account is permitted to be an examiner, you can record examination sessions of other students through the **Record Examination** menu (see the lecturer workflow in 4.2.5).

#### 4.1.7 Submitting Seminar/Examination Materials

1. Open your TA/KP detail page → click **Submit Seminar/Examination Materials** (or from the dashboard).
2. The seminar type (Proposal Seminar / Results Seminar / Final Examination / KP Seminar) is filled automatically from your phase.
3. Fill in **Date**, **Time**, and **Location** of the seminar/examination.
4. Upload the **Invitation Letter** (file) and select **"Invitation as"** (Supervisor 1/2 or Examiner 1/2).
5. Choose **Material**: upload a new file **or** take it from the **Workspace** (one is required).
6. (Optional) Add **Remarks**.
7. Click **Submit** → related lecturers receive a notification.
8. You can **edit** the submission as long as it has not been converted into an examination record by a lecturer.

#### 4.1.8 Thesis/Internship Finalization

1. Open the **Finalization** menu on your TA/KP detail page.
2. Fill in **Abstract** and **Keywords** (TA only).
3. Upload **Cover** and **Approval Page** (PDF, TA only).
4. Upload the **Full File** (PDF) — required for both TA and KP.
5. Click **Submit for Approval** → each item is sent to both supervisors.
6. Monitor approval status: `pending` → `approved` (if both supervisors approve) or `rejected` (if any rejects).
7. If an item is rejected, fix it and resubmit.

---

### 4.2 Lecturer Workflow

#### 4.2.1 Approving Lecturer Attachment Requests

1. Open the **Approvals** menu (in the sidebar) — the **"Lecturer Attachment Approvals"** page.
2. View the list of students who **selected you** as supervisor/examiner (status **Pending**).
3. To approve, fill in:
   - **Thesis Title** (or **KP Location** for internship programs)
   - **Your role** for that student (Supervisor 1 / Supervisor 2 / Examiner 1 / Examiner 2)
   - **Target Sessions**
4. Click **Approve & Assign** → the program becomes **active** and the student is marked **verified**.
5. Alternatively, click **Reject** to reject the request — the student can select another lecturer.
6. You can also **add a student manually** (enter email) — the student needs to verify their email and select a lecturer.

#### 4.2.2 Reviewing Logbooks

1. On the **Dashboard**, check the **Review Queue** (number of entries awaiting review).
2. Click **Review** on an entry, or use the **Quick Review** menu to review them one by one.
3. On the entry:
   - Open **PDF & Annotation** to read the document and add comments to PDF areas.
   - Choose **Approve** to approve, or
   - Fill in **feedback** (required, min 20 characters) then **Request Revision**.
4. When approving with an unopened PDF attachment, the application asks for confirmation first.

#### 4.2.3 Quick Review

A fast mode to review the queue one by one:

1. Open the **Quick Review** menu.
2. Read the entry summary, previous feedback, and previous round annotations.
3. Use the **last feedback** for that student (click to reuse) or **feedback templates**.
4. Click **"Build from Comments"** to generate feedback from unresolved PDF comments.
5. Choose **Approve & Next** or **Revision & Next** to proceed to the next entry.
6. Save feedback as a **template** for reuse.

#### 4.2.4 Managing Phases & Supervision Health

1. On the dashboard, use the **Supervision Health Indicator** to monitor each student's supervision regularity (filter Healthy/Attention/Critical).
2. Use **Thesis Phase Management** to update each student's phase (e.g., from Proposal to Data Collection).
3. Students inactive in supervision can be flagged (⚠) and receive an inactivity email.

#### 4.2.5 Recording Examination Sessions / Examining History

1. Open the **Record Examination** menu.
2. Select a student from the supervision list, or type the **name of a student outside the system**.
3. Fill in **Type** (Proposal Seminar / Final Examination), **Date**, and **Result** (Pass / Pass + Revision / Repeat).
4. (Optional) Fill in the supervisor names of the examined student (max 3).
5. Click **Save Examination** → recorded in the examining history.
6. Use **Export PDF** to download the examining history.

#### 4.2.6 Reviewing Seminar/Examination Materials

1. When a student submits seminar/examination materials, you receive a **notification**.
2. Open the submission detail (from the notification, dashboard, or student page).
3. **Download the Invitation Letter** and **Material** for review.
4. (Optional) Update the **Hardcopy Note** on the submission.
5. If everything is in order, you can **Convert to Examination Record**: select the **Examiner** and **Result** (Pass / Pass + Revision / Repeat).
6. A converted submission can no longer be modified by the student.

#### 4.2.7 Approving Thesis/Internship Finalization

1. Open the **Finalization Review** menu (in the sidebar) to see the list of finalizations from your supervised students.
2. Review each item (Abstract, Keywords, Cover, Approval Page, Full File).
3. Click **Approve** or **Reject** per item.
4. An item is considered **final** only if **both supervisors** approve it.
5. (Optional) Enter the final **Grade** (0–100).
6. If all items are approved and the TA phase is `sidang`, the phase automatically advances to **Achievement Unlocked**.

#### 4.2.8 Communicating & Announcing

- **Chat** with supervised students (start from the student detail page).
- Create **announcements** and monitor their read reports; send reminders to those who have not read.
- Monitor **notifications** for new entries and PDF comments.

#### 4.2.9 Main Supervision Workflow (Summary)

```
Student registers → Lecturer approves & assigns
   → Student records a logbook (draft)
   → Student submits to lecturer (submitted)
   → Lecturer reviews: approve OR request revision
   → If revision: Student creates a revision entry → submits again → lecturer reviews
   → Each approval adds an approved session
   → Lecturer monitors health & phase → until completed
```

---

### 4.3 System Admin Workflow

#### 4.3.1 Managing Admin Accounts

1. Open the **Manage Admins** menu (only visible to System Admins).
2. View the list of all operational admin accounts.
3. **Add an admin**: fill in name, email, identifier (optional), and password → click **Save**.
4. **Reset an admin's password**: click **Reset PW** → enter a new password.
5. **Delete** an admin account: click **Delete** (with confirmation).
6. Protection: you **cannot delete your own account** or another System Admin account.

#### 4.3.2 Managing Plans/Subscriptions

1. Open the **Users** menu → click **Plan** on the user you want to configure.
2. Select a plan (Free / Donation).
3. Configure overrides: export permission, import permission, and storage limit (MB).
4. Click **Save** → the user's plan is updated.

#### 4.3.3 Full Admin Menu Access

- The System Admin has access to **all** admin menus: Users, Dosen Approvals, Thesis Data, Bulk Review, Examinations, and Institution.
- The System Admin can also create users with the **admin** role from the **Users** page (the "Admin" checkbox only appears for System Admins).

---

### 4.4 Admin Workflow

#### 4.4.1 Managing Users

1. Open the **Users** menu.
2. **Search/filter** users by name, email, identifier, or role.
3. **Add a user**: fill in name, email, identifier (NIM/NIDN), password, and select a role (admin/lecturer/student).
4. **Reset a user's password** (click **Reset PW**).
5. **Delete** a user when necessary.

#### 4.4.2 Managing Thesis Data

1. Open the **Thesis Data** menu.
2. **Create thesis data**: select a student (without a thesis), fill in the title, and set supervisors 1/2, examiners 1/2, and target sessions.
3. **Edit & assign** thesis data per student (title, supervisors, examiners, target sessions, status).
4. **Bulk action**: check several rows → select a lecturer → **Assign Supervisor 1** in bulk.
5. **Import students (Excel)** from the dashboard: upload a file (name, NIM, email, supervisor1_nidn, supervisor2_nidn) and choose a default supervisor.

#### 4.4.3 Bulk Entry Review

1. Open the **Bulk Review** menu.
2. **Filter** entries by status, type, and keyword.
3. Check the selected entries.
4. Choose a bulk action: **Approve**, **Mark as Revision**, or **Delete**.

#### 4.4.4 Managing Examination Data

1. Open the **Examinations** menu.
2. **Add examination data**: select a student, examining lecturer, type, date, and result.
3. **Delete** examination data when necessary.
4. Note: A Final Examination with a **Pass / Pass + Revision** result automatically marks the student as **completed**.

#### 4.4.5 Managing the Institution Profile

1. Open the **Institution** menu.
2. Fill in institution information: application name, institution name, faculty, study program, address, city, phone, email, website, document footer note, and logo.
3. Configure **upload & template settings**: correction notes template link, max upload size (MB), and allowed file types.
4. Configure **email settings (SMTP)**: mailer, host, port, encryption, username, password, from-address, and from-name.
5. **Send a test email** to verify the SMTP configuration.

#### 4.4.6 Monitoring the Dashboard

- The admin dashboard shows statistics for **students, lecturers, thesis data**, and **awaiting review**.
- A list of **recent thesis data** is displayed for quick monitoring.

---

## 5. Frequently Asked Questions (FAQ)

**Q: What does the "419 Page Expired" error mean?**
A: This error occurs when the session security token (CSRF) is invalid or expired. Solution: reload the login page, and avoid leaving the page open for too long. If it persists, contact the administrator to check the session configuration.

**Q: I am a student, but I cannot log in after registering.**
A: Make sure you have **verified your email** (click the link in the email sent after registration). If your account is **rejected**, contact the admin. If your account is **active**, you can log in — next, select a lecturer via the **"Select Lecturer"** menu on the dashboard.

**Q: How do I edit or submit a draft logbook?**
A: Open the draft entry from the **Logbook** page, click **Edit** to modify it, then **Send to lecturer** to submit.

**Q: I am a lecturer. How do I comment on a PDF?**
A: Open the entry (status **Submitted**) → click **Review PDF & Annotate** → open the PDF, highlight an area, and add a comment. Comments can be turned into automatic feedback.

**Q: What is the file size limit in the workspace?**
A: Max 25 MB per file, up to 5 files at once, in PDF, DOC, DOCX, XLS, XLSX formats.

**Q: How is a student marked as "completed"?**
A: Automatically when a **Final Examination** is recorded with a **Pass** or **Pass + Revision** result, or manually set by the admin.

**Q: What is the health indicator?**
A: An indicator of supervision regularity: 🟢 Healthy (<15 days), 🟡 Attention (15–40 days), 🔴 Critical (>40 days). It helps lecturers and students monitor supervision consistency.

**Q: I am both a lecturer and an admin. How do I switch dashboards?**
A: Click your **profile picture** in the top-right corner → in the dropdown menu select **"Dashboard Mode"** → choose **Lecturer** or **Admin**. The selected mode is persisted for the session.

**Q: Why doesn't the "admin" label appear on my profile?**
A: The "admin" role label is intentionally hidden from all profile pages so that administrative status remains private. The "lecturer" and "student" labels are still shown.

**Q: How do I submit seminar/examination materials?**
A: Open your TA/KP detail page → click **Submit Seminar/Examination Materials** → fill in date, time, location, upload the invitation letter, choose the material (upload or from workspace), then submit. Related lecturers will receive a notification.

**Q: How do I approve thesis/internship finalization?**
A: Open the **Finalization Review** menu → review each item → click **Approve** or **Reject**. An item is considered final if **both supervisors** approve it.

**Q: What is the difference between System Admin and Admin?**
A: **System Admin** is the highest role that can manage other admin accounts (create, reset password, delete) and plan/subscription settings, and has full access to all admin menus. **Admin** manages academic data (users, thesis, examinations, review) but cannot manage other admins.

**Q: How do I create a new admin account?**
A: Log in as **System Admin** → open the **Manage Admins** menu → fill in the **Add Admin** form → click **Save**. The System Admin can also create users with the admin role from the **Users** page.

**Q: Why can't I create an admin account?**
A: Only the **System Admin** can create accounts with the admin role. If you are logged in as a regular admin, the "Admin" role option will not appear in the add-user form.

---

## Notes

- Use **Cmd+K / Ctrl+K** for global search.
- Use the **dark/light mode button** in the header to change the theme.
- The sidebar can be collapsed/expanded with the button on its left edge.
- To report issues or suggest improvements, use the **"Send Feedback"** menu.