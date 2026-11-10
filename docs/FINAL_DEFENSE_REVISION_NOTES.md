# Final Defense Revision Notes (SportsHub)

This document records the Final Defense documentation revisions aligned with the current implemented SNNHS SportsHub system (Laravel 12, PHP 8.2, MySQL, Blade/Tailwind/PWA). It covers the Conceptual Framework, Project Gantt Chart (May–November 2026), Use Case Diagram, Class Diagram, and Sequence Diagram.

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
- **Admin/Sports Coordinator (authenticated):** log in/out; manage applications (Pending/Under Review/Requires Revision/Approved/Rejected), verify/reject documents, require explanations for rejection/revision, add office instructions, maintain status history/reviewer/date, create approved athlete accounts with duplicate prevention, reset passwords; manage sports/coaches/events/calendar/attendance/medical/achievements/certificates/certificate requests; send in-app notifications; generate reports.
- **Athlete (authenticated):** log in, first-login password change as applicable; dashboard, schedules/calendar/events, announcements, attendance records/check-in, achievements/certificate requests, application status/reference/feedback/office instructions, in-app notifications, coach/program info, profile/password, log out.

## 4. Class Diagram (revised summary)

Primary entities (14): `User`, `Sport`, `Coach`, `Application`, `ApplicationStatusHistory`, `Announcement`, `Event`, `AttendanceSession`, `Attendance`, `MedicalRecord`, `MedicalIncident`, `Achievement`, `CertificateRequest`, `Notification`.

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

## Manual follow-up
- Update Use Case/Class/Sequence diagram drawings in the Word/figure files to match the revised summaries above.
- Regenerate `docs/figures/figure1.png` from `docs/figures/figure1.html`.
- Replace Gantt Table 3/Figure references in `.docx` manuscripts with the May–November 2026 timeline.
- Keep public-facing documentation clear that in-app notifications work for linked student accounts; unlinked public applicants track status through the secure reference+email status page (no external SMS/email API required).