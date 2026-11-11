# Final Defense Revision Notes (SportsHub)

This document records the Final Defense documentation revisions aligned with the current implemented SNNHS SportsHub system (Laravel 12, PHP 8.2, MySQL, Blade/Tailwind/PWA). It covers the Conceptual Framework, Project Gantt Chart (May–November 2026), Use Case Diagram, Class Diagram, and Sequence Diagram.

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

## Sources updated in this repository

- `docs/figures/figure1.html` — Conceptual Framework (IPO-E) source updated to include secure application status tracking, reference-number/email status check, revision workflow, status history, admin instructions, and in-app notifications. Regenerate/export `docs/figures/figure1.png` from this HTML for the manuscript/slide figure.
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
- Update Use Case/Class/Sequence diagram drawings in the Word/figure files to match the revised summaries above.
- Regenerate `docs/figures/figure1.png` from `docs/figures/figure1.html`.
- Replace Gantt Table 3/Figure references in `.docx` manuscripts with the May–November 2026 timeline.
- Keep public-facing documentation clear that in-app notifications work for linked student accounts; unlinked public applicants track status through the secure reference+email status page (no external SMS/email API required).