# Final Defense Revision Notes (SportsHub)

This document records the Final Defense documentation revisions aligned with the current implemented SNNHS SportsHub system (Laravel 12, PHP 8.2, MySQL, Blade/Tailwind/PWA). It covers the Conceptual Framework, Project Gantt Chart (May–November 2026), Use Case Diagram, Class Diagram, and Sequence Diagram.

## Revision 4 — Section 1.4 Scope and Limitations rewritten (current)

Section 1.4 now describes the project as it is actually built and deployed. The wording was checked against the code, not against the earlier draft.

**What was corrected**
- The public status page (reference number + application email) is named in the scope, because applicants without an account use it.
- The Messages & Follow-ups bullet now states the real composing options: category, priority, recipient group (one athlete, a selected set, all active athletes, or one sport), and drafts.
- The athlete bullet now includes the acknowledgment of a medical follow-up and the reply to the sports office.
- The administrator bullet now includes the administrator's own profile.
- The delivery bullet now states where the system runs: a live container deployment with PHP 8.3 served by Apache and the data in MySQL.
- The evaluation bullet is now in the past tense, because the ISO/IEC 25010 evaluation with fifteen (15) respondents has been carried out, and it mentions the automated feature test suite that backs the software.
- The scope no longer says the evaluation "will be conducted".

**Limitations added, each tied to a real boundary of the system**
1. Notifications are in-app only; no SMS, email, or push, so an athlete who does not open the portal is not alerted.
2. Only two account roles exist; there is no finer permission level for a faculty sports adviser, a medical officer, or a team leader.
3. Coaches have no accounts. A coach is a record (name, email, phone, specialty, type, assigned sport, status) that the Sports Coordinator maintains; a coach cannot sign in, cannot receive a message or a medical follow-up, and cannot open, close, or confirm a session, an achievement, or a certificate, so every action that involves a coach is carried out by the Sports Coordinator.
4. There is no parent or guardian portal; the consent form and the medical certificate are scanned documents verified by hand.
5. The clearance status depends on the uploaded certificate and the coordinator's entry; the system does not verify fitness to play against the school clinic.
6. The system runs as a single hosted instance with one database, without an automated backup and restore routine, multi-school support, or high availability.
7. The evaluation rests on fifteen respondents at one school, and the automated test suite proves the behaviour of the software but cannot stand in for a longer period of actual use.

The old limitations that were kept are unchanged: no financial management, no grading, no external system integration, no native mobile application, the generalizability of the evaluation, the dependence on manual data entry, and the limits of the offline caching.

**Files updated**
- `docs/SportsHub_Revised.md` — section 1.4 replaced.
- `docs/Capstone_Project_Final_Documentation.md` and `.txt` — section 1.4 replaced.
- `docs/LatestDocuments_SportsHub_CORRECTED.txt` — section 1.4 replaced with the same wording in the numbered layout of that export.
- `docs/SportsHub_Capstone_Final_Revised.docx` — the twelve scope bullets and the eleven limitation bullets of the Word manuscript were rewritten in place; the numbering and the run formatting of the list are unchanged.

**Manual step still open in the Google Doc**
The Google copy of the manuscript has to receive the same section by hand: delete the current scope and limitation bullets of 1.4 and paste the new ones from `docs/SportsHub_Revised.md` (lines 96 to 118). The section is the only part that changed, so nothing else in the Google Doc has to be touched.

## Revision 3 — Clearer headings and forgiving action addresses (current)

- **Repeated headings removed.** The athlete pages no longer print their own title twice: *My Application* now carries the section *Application details*, *Messages & Follow-ups* carries *Received messages*, and *Sports Programs* carries *Enrolled programs*. On the administrator side the records sections carry *Examination records*, *Recorded accomplishments*, and *Sent messages and drafts*.
- **Action addresses no longer return an error page.** Acknowledging, replying, completing, and cancelling are form actions that only accept a POST or PATCH. A pasted or bookmarked address now sends the visitor back to the message itself instead of showing "405 Method Not Allowed".
- **An empty reply explains itself.** The reply field shows the reason when the athlete sends nothing, instead of a silent reload.
- The reply and acknowledgment flows were re-run against a real MySQL server (`phpunit.mysql.xml`) to prove the driver behaviour, not only the in-memory database.

## Revision 2 — Unified Messages & Follow-ups module (current)

The separate **Messages** module and **Medical Follow-ups** module were merged into one **Messages & Follow-ups** module that both dashboards share. The manuscript text below was updated to match the implemented system.

- `docs/SportsHub_Revised.md` — abstract, purpose (1.2), objectives (1.3), scope (1.4), functional requirements (4.1), module list (4.4), deployment note (4.4), use-case actors and class diagram (5.2), the new medical follow-up sequence flow, and the implementation and conclusion sections (5.3, 6.1).
- `docs/LatestDocuments_SportsHub_CORRECTED.txt` — the same revisions in the plain-text export: scope items 6–12, the new functional requirements, the module table (Table 1), the class diagram entity table (Table 4, now seventeen entities), the relationship paragraph, and the deployment note.
- `docs/figures/figure1.html` — conceptual framework unchanged; the in-app notification output now also covers general messages and medical follow-ups.

### What the module does now
- One compose screen with a **category** (General Message or Medical Follow-up), a **priority**, a **recipient type** (one athlete, selected athletes, all active athletes, or by sport), drafts, and per-recipient read tracking.
- The medical fields appear only for a medical follow-up: follow-up type, date, time, location, additional medical instructions, and whether a response is required.
- Follow-up statuses: **Scheduled, Sent, Read, Acknowledged, Completed, Overdue, Cancelled**. Only an administrator completes or cancels a follow-up; opening a message is never an acknowledgment.
- The athlete reads and acknowledges in the same inbox, and the administrator monitors read, acknowledged, completed, and overdue counts per athlete.

### New database entities
- `medical_follow_ups` — one row per message that is a follow-up: `message_id` (unique FK), `follow_up_type`, `scheduled_date`, `scheduled_time`, `location`, `additional_instructions`, `response_required`, `status`, `completed_by`, `completed_at`, `cancelled_at`.
- `medical_follow_up_recipients` — one row per athlete per follow-up: `read_at`, `acknowledged_at`, `completed_at`, `completed_by`, `status`.
- `messages` gained `category` (`general` / `medical_follow_up`) and `reply_to_message_id`; `message_recipients` keeps the delivery and read tracking shared by both categories.
- The former `medical_conversations` / `medical_messages` tables were migrated into this unified structure, so no parallel messaging system remains.

### Manual step still open in the Google Doc
The manuscript text was revised in place with find and replace, but **two rows have to be added to Table 4 (Entities of the SportsHub Class Diagram)** by hand, because a text replacement cannot create table rows. The class diagram now states **seventeen primary entities**, so the table needs the two missing rows:

| Entity | Purpose | Key Attributes |
|---|---|---|
| MedicalFollowUp | Medical schedule of a message | id, message_id, follow_up_type, scheduled_date, scheduled_time, location, additional_instructions, response_required, status, completed_at, cancelled_at |
| MedicalFollowUpRecipient | Acknowledgment and completion of one athlete for a follow-up | id, medical_follow_up_id, recipient_id, read_at, acknowledged_at, completed_at, completed_by, status |

The same two rows are already present in `docs/LatestDocuments_SportsHub_CORRECTED.txt`, which can be copied over.

## Diagrams (all four regenerated)

Every diagram was rebuilt as an HTML source that renders itself, so the figures can be regenerated after any change:

| Figure | Source | Rendered image | What changed |
|---|---|---|---|
| Conceptual Framework (IPO-E) | `docs/figures/figure1.html` | `docs/figures/figure1.png` | new input box (message and follow-up data), the unified module in the athlete and administrator processes, follow-up tracking and the unified inbox in the output, 446 automated tests in the evaluation tools, and the unified messaging tables in the database layer |
| Use-Case Diagram | `docs/figures/figure10-use-case.html` | `docs/figures/figure10-use-case.png` | new use cases for sending a general message or medical follow-up, monitoring read / acknowledged / completed / overdue, completing or cancelling a follow-up, reading the inbox, acknowledging a follow-up, and replying to the sports office |
| Class Diagram | `docs/figures/figure11-class.html` | `docs/figures/figure11-class.png` | seventeen entities; the four classes of the unified module are highlighted; the new relationships (message to delivery records, follow-up to message, follow-up to per-athlete rows) are drawn |
| Sequence Diagrams | `docs/figures/figure12-sequence.html` | `docs/figures/figure12a-athlete-checkin.png`, `figure12b-application-approval.png`, `figure12c-certificate-issuance.png`, `figure12d-medical-followup.png` | four separate diagrams; the fourth one is the medical follow-up and its acknowledgment: one transaction for message, follow-up and delivery rows, the read state recorded on opening, the acknowledgment stored only when the athlete confirms it, and the completion or cancellation by the sports office |

Render a figure again with Chrome, for example:

```
chrome --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=1 ^
  --window-size=1700,<page height> --screenshot=docs/figures/figure11-class.png ^
  file:///C:/xampp/htdocs/SportsHub/docs/figures/figure11-class.html
```

**In the Google Doc**, the three sequence images are replaced by the four separate ones (`figure12a-…` to `figure12d-…`), the two captions that are no longer used are deleted, and the figures after them move one number up. The Use-Case and Class Diagram images are replaced one for one.

The caption lines of the Google Doc, because the find-and-replace bar stopped responding while the figures were rendered:

| Current caption | New caption |
|---|---|
| Figure 12 - Athlete Check-in | Figure 12. Athlete Check-in |
| Figure 13 - Application Approval and Account Creation | Figure 13. Application Approval and Account Creation |
| Figure 14 - Certificate Request and Issuance | Figure 14. Certificate Request and Issuance |
| *(new)* | Figure 15. Medical Follow-up and Acknowledgment |
| Figure 15 … Figure 40 | Figure 16 … Figure 41 |

The sentence above the figures reads `The Sequence Diagram (Figures 12 to 14) documents` and becomes `The Sequence Diagrams (Figures 12 to 15) document`.

### The application status check still to be typed into the Google Doc

The public **Check Application Status** page (reference number + email, no account) is described in the manuscript, the use-case diagram, and the sequence diagram, but four sentences of the Google Doc text still leave it out. The browser session signed out before these could be applied, so they are listed here:

| Where | Current text | New text |
|---|---|---|
| Public requirements (4.1) | `- View the public landing page and browse posted announcements and available sports programs` | keep the line and add after it: `- Check the status of an application that was already submitted, without an account, using the application reference number together with the email address used on the application` |
| Public module (4.4) | `...an overview of available sports programs, and a button to submit a sports application.` | `...an overview of available sports programs, a button to submit a sports application, and a button to check the status of an application that was already submitted, using the reference number and the email address of the applicant.` |
| Public interface (5.3) | `It contains the landing page, the application form, the login page, and the password reset page.` | `It contains the landing page, the application form, the application status check, the login page, and the password reset page.` and add after the sentence about Figures 7 and 8: `The status-check page takes the application reference number and the email address used on the application, and shows only the status, the sport applied for, the submission and update dates, the review explanation, and the office instructions, so a reference number alone never opens the record.` |
| Module table (Table 1) | `Public Landing Page	Public	Active announcements, sports program overview, and the application entry point` | `Public Landing Page	Public	Active announcements, sports program overview, the application entry point, and the secure application status check (reference number and email)` |

`docs/Capstone_Project_Final_Documentation.md` and `docs/Capstone_Project_Final_Documentation.txt` already carry all four changes.

## The revised Word manuscript

`docs/SportsHub_Capstone_Final_Revised.docx` is the Word export of *SportsHub _ Revised 11_11_26* with the system changes applied. The original file in `Downloads` is left untouched.

**Figures replaced**

| Figure | Before | After |
|---|---|---|
| Figure 1 — Conceptual Framework | the old IPO-E diagram | `docs/figures/figure1.png` with the unified module, the message and follow-up input data, the follow-up tracking in the output and the unified messaging tables in the database layer |
| Figure 10 — Use-Case Diagram | the old diagram | `docs/figures/figure10-use-case.png` with the send / monitor / complete / acknowledge use cases |
| Figure 11 — Class Diagram | the old diagram | `docs/figures/figure11-class.png` with the seventeen entities and the four classes of the unified module highlighted |
| Figures 12 to 15 — Sequence Diagrams | three separate images | four separate images, `figure12a-athlete-checkin.png`, `figure12b-application-approval.png`, `figure12c-certificate-issuance.png` and `figure12d-medical-followup.png`; the medical follow-up and its acknowledgment is the new Figure 15, and every figure after it moved one number up |
| Figure 24 — Messages page (athlete) | the old athlete inbox | `docs/figures/figure23-athlete-inbox.png`, the unified inbox with the follow-up acknowledgment |
| Figure 38 — Messages page (administrator) | the old administrator Messages screen | `docs/figures/figure37-admin-messages.png`, the unified module with the new tabs and summary cards |

**Text applied**

- the public requirement *"Check the status of an application that was already submitted, without an account, using the application reference number together with the email address used on the application"*
- the module table cell now reads *"... the application entry point, and the secure application status check (reference number and email)"*
- the public interface paragraph now lists the status-check page and explains that it only returns the status, the sport, the dates, the explanation and the office instructions
- *The Sequence Diagram (Figures 12 to 14) documents* became *The Sequence Diagrams (Figures 12 to 15) document*, the sequence figures were split into four captioned figures, the figures from 15 to 40 were renumbered to 16 to 41, and the administrator figure caption became *Figure 38. Messages & Follow-ups Page - SportsHub*

## Sources updated in this repository

- `docs/Capstone_Project_Final_Documentation.md` — **the final documentation of the project**: the complete manuscript (title page, abstract, chapters 1 to 8 with the references) with the unified Messages & Follow-ups module, the four regenerated diagrams embedded, and a new *Appendix A. Implemented System Summary* that lists the delivered modules, the seventeen entities of the schema, the follow-up workflow, and the technology, deployment, and verification facts (446 automated tests, ISO/IEC 25010 result 4.46).
- `docs/Capstone_Project_Final_Documentation.txt` — the same document as plain text with the tables converted to tab separated rows, ready to paste into the Google Doc or a Word template.
- `docs/figures/figure1.html` — Conceptual Framework (IPO-E) source updated with the unified Messages & Follow-ups module: the message and follow-up input data, the module in the athlete and administrator processes, the follow-up tracking and unified inbox in the output, the 446 automated tests, and the unified messaging tables in the database layer. Regenerate/export `docs/figures/figure1.png` from this HTML for the manuscript/slide figure.
- `docs/figures/figure10-use-case.html`, `docs/figures/figure11-class.html`, `docs/figures/figure12-sequence.html` — new sources for the Use-Case Diagram, the Class Diagram, and the Sequence Diagrams, each with the unified module. Their rendered images are `docs/figures/figure10-use-case.png`, `docs/figures/figure11-class.png`, and the four sequence figures `figure12a-athlete-checkin.png` to `figure12d-medical-followup.png` (the sequence source renders one panel at a time with `?flow=1` to `?flow=4`).
- `docs/SportsHub_Revised.md` — Revised text for conceptual framework, Gantt (May–Nov), use case, class diagram, and sequence diagrams; application workflow/status model aligned to current implementation.
- `docs/LatestDocuments_SportsHub_CORRECTED.txt` — Gantt chart (Table 3) updated to May–November 2026; key modeling sections should be cross-checked against `SportsHub_Revised.md` and the implemented system.
- `docs/SportsHub_Capstone_Final.docx` and `docs/SportsHub_Final.docx` — Binary Word documents; diagram images and formatted tables inside these files must be updated manually using the content below and the revised figure/text sources.

## 1. Conceptual Framework (IPO-E)

**Figure 1** remains an Input–Process–Output–Evaluation model with feedback to Input/Process.

### Input
- Administrator/coordinator data: sports programs, athlete profiles, coach records, events, medical records/injuries, achievements, credentials.
- Student application data: personal details, student ID, grade level, gender, sport of interest, contact details/email.
- Eligibility documents: medical certificate (fit to play), PSA birth certificate, parent/guardian consent form.
- No athlete account is created at submission. After submission, the system generates a unique **application reference number** for secure status tracking.

### Process
- **Public Landing Page Module:** announcements, sports programs, application form/uploads, secure application status check (reference number + matching email), login/password recovery.
- **Athlete Module:** dashboard, schedules/calendar, attendance check-in/records, achievements/certificates, application status and feedback/office instructions, in-app notifications, profile/password.
- **Administrator Module:** application review and document verification; status actions (Pending, Under Review, Requires Revision, Approved, Rejected); required explanations for rejection/revision; admin/office instructions; reviewer and decision date/time; application status-history audit trail; duplicate athlete-account prevention on repeated approvals; sports/coaches/events/attendance; medical records; certificates; in-app notifications/reports.
- Implementation: PHP 8.2, Laravel 12 (MVC), MySQL via Eloquent ORM, validation, role-based access, and audit-oriented status tracking.

### Output
- Organized records: athlete registry, coach directory, application records/reference numbers/status history, attendance/medical/participation records.
- Reports/documents: athlete/coach/attendance lists, CSV/PDF exports, achievement certificates, application reference and status feedback.
- Interfaces: public landing/status-check pages, athlete dashboard/application view, administrator command centre, in-app notification feed.

### Evaluation
- ISO/IEC 25010 (Usability, Reliability, Performance Efficiency), 15-item Likert questionnaire, direct observation, and automated feature tests (including application, status tracking, notifications, attendance, achievements, certificates, medical, and password workflows).

## 2. Project Gantt Chart (May–November 2026)

| Phase | Tasks / Activities | May | Jun | Jul | Aug | Sep | Oct | Nov |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| 1. Planning Requirements | Requirements gathering, stakeholder consultation, scope definition, SNNHS workflow review | ● | ● | | | | | |
| 2. Prototype & System Design | UI prototype, conceptual framework, Use-Case/Class/Sequence diagrams, database planning | | ● | ● | | | | |
| 3. Development | Public/admin/athlete modules, application workflow, attendance, medical, achievements, notifications, status tracking enhancements | | | ● | ● | ● | ● | |
| 4. Testing & Feedback | Functional/automated testing, ISO/IEC 25010 evaluation, questionnaire, bug fixes, user-feedback revisions | | | | | ● | ● | ● |
| 5. Finalization | Security/deployment checks, final revisions, documentation, Final Defense manuscript/diagrams | | | | | | ● | ● |
| 6. Presentation | Final defense rehearsal, presentation, submission of capstone document | | | | | | | ● |

## 3. Use Case Diagram (revised summary)

### Actors / boundaries
- **Public (unauthenticated):** landing page only. View announcements, browse sports programs, submit application (receives reference number), securely check application status (reference number + email), reset forgotten password.
- **Admin/Sports Coordinator (authenticated):** log in/out; manage applications (Pending/Under Review/Requires Revision/Approved/Rejected), verify/reject documents, require explanations for rejection/revision, add office instructions, maintain status history/reviewer/date, create approved athlete accounts with duplicate prevention, reset passwords; manage sports/coaches/events/calendar/attendance/medical/achievements/certificates/certificate requests; compose and send general messages and medical follow-ups and monitor their read, acknowledgment, and completion state; send in-app notifications; generate reports.
- **Athlete (authenticated):** log in, first-login password change as applicable; dashboard, schedules/calendar/events, announcements, attendance records/check-in, achievements/certificate requests, application status/reference/feedback/office instructions, in-app notifications, coach/program info, profile/password, log out; read general messages and medical follow-ups in one inbox and acknowledge a follow-up that requires a response.

## 4. Class Diagram (revised summary)

Primary entities (18): `User`, `Sport`, `Coach`, `Application`, `ApplicationStatusHistory`, `Announcement`, `Event`, `AttendanceSession`, `Attendance`, `MedicalRecord`, `MedicalIncident`, `Message`, `MessageRecipient`, `MedicalFollowUp`, `MedicalFollowUpRecipient`, `Achievement`, `CertificateRequest`, `Notification`.

### Key application-related changes
- **Application:** `id`, `reference_number` (unique), applicant/profile fields, `email`, `sport_id`, eligibility document paths/statuses, `status` (`Pending`, `Under Review`, `Requires Revision`, `Approved`, `Rejected`), `rejection_reason`, `review_notes`, `admin_instructions`, `documents_requested`, `reviewed_by`, `reviewed_at`, `review_history`, `athlete_id`, timestamps.
- **ApplicationStatusHistory:** `id`, `application_id` (FK), `status`, `notes`, `admin_instructions`, `changed_by` (FK nullable), timestamps.
- Relationships: `Application belongsTo Sport`; `Application belongsTo User (athlete/reviewer as applicable)`; `Application hasMany ApplicationStatusHistory`; `ApplicationStatusHistory belongsTo Application`; `ApplicationStatusHistory belongsTo User (changed_by)`.

## 5. Sequence Diagrams (revised flows)

### A. Application submission and secure status check
1. Applicant (public) submits application form + documents.
2. `ApplicationController` validates input/policy, stores files privately, creates `Application` (`Pending`), generates unique `reference_number`, creates initial `ApplicationStatusHistory`.
3. System shows confirmation with reference number and link to status page.
4. Applicant opens status page and submits `reference_number` + `email`.
5. Controller verifies matching application; returns only non-sensitive status info (status, sport, submitted/updated dates, explanation, admin instructions). Reference number alone is not sufficient.

### B. Application review, revision/approval/rejection, and notification
1. Admin opens application (authorized).
2. Controller loads application, documents, reviewer, and status history.
3. Admin sets status: Under Review / Requires Revision (explanation required) / Approved (documents verified) / Rejected (explanation required); may add admin/office instructions.
4. Controller validates, updates application (`reviewed_by`, `reviewed_at`, notes/instructions), creates `ApplicationStatusHistory`, preserves legacy review history.
5. If application is linked to a student account, `StudentUpdateNotifier` sends in-app `StudentUpdateNotification` with non-sensitive preview and link to student application page.
6. For approved applications, athlete account creation follows existing secure flow and prevents duplicate accounts.

### C. Athlete check-in (existing)
Athlete checks into an open same-day/sport-matching session; service validates session/state/sport, records Present/Late based on grace period, rejects duplicates, and notifies coordinator.

### D. Certificate request and issuance (existing)
Athlete requests certificate (Pending, coordinators notified); admin approves/generates/stores PDF privately/releases/issues; athlete downloads issued certificate.

### E. Medical follow-up and acknowledgment (new)
1. Admin composes a message with the **Medical Follow-up** category; the medical fields (type, date, time, location, instructions, response required) appear only for that category.
2. `MessageDeliveryService` writes the message, the `MedicalFollowUp`, and one `MessageRecipient` + `MedicalFollowUpRecipient` row per athlete inside one transaction, and raises one in-app notification per recipient with the schedule included.
3. Athlete opens the follow-up: the delivery record is marked read and the follow-up moves to **Read** — the acknowledgment stays untouched.
4. Athlete presses **Acknowledge**: `acknowledged_at` is recorded with the exact time, the follow-up moves to **Acknowledged**, and the sports office receives an in-app notification of the confirmation.
5. Admin monitors the read / acknowledged / completed / overdue counts per athlete and may mark the follow-up (or one athlete's part) **Completed** after verification, or **Cancelled**.

## Manual follow-up
- Update Use-Case/Class/Sequence diagram drawings in the Word/figure files to match the revised summaries above.
- Regenerate `docs/figures/figure1.png`, `docs/figures/figure10-use-case.png`, `docs/figures/figure11-class.png`, and `docs/figures/figure12-sequence.png` from their HTML sources after any change.
- Replace Gantt Table 3/Figure references in `.docx` manuscripts with the May–November 2026 timeline.
- Keep public-facing documentation clear that in-app notifications work for linked student accounts; unlinked public applicants track status through the secure reference+email status page (no external SMS/email API required).