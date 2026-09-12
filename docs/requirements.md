# REQUIREMENTS — Software House + IT Training Institute Management System

Faithful, condensed restatement of the client's 120-section specification. This is the **requirement
source of truth**: every phase contract in `docs/phases/` must trace back to a section here. Section
numbers match the original document. Architecture decisions that interpret these requirements live in
[`../DEVELOPMENT_LOG.md`](../DEVELOPMENT_LOG.md) §4; conventions in [`../CLAUDE.md`](../CLAUDE.md).

The organisation runs **two businesses in one platform**: a Software House and an IT / Computer
Training Institute, with centralised authentication, RBAC, finance, reporting and a fully dynamic
public website.

---

## A. Platform (§1–6)

**Stack (§1).** Laravel, PHP, MySQL; middleware, policies, gates, events, notifications, jobs, queues,
scheduler; REST API where required. Blade frontend, HTML5/CSS3/JS, AJAX where suitable, Tailwind or
Bootstrap. Clean Laravel architecture and best practices.

**UI/UX (§2).** Premium SaaS-style admin dashboard. Light theme default + dark + system. Fully
responsive (mobile/tablet/desktop). Modern sidebar, dashboard cards, charts, tables, filters, search,
pagination, modal forms, toast notifications, confirmation dialogs, smooth animations, skeleton
loaders, loading indicators, empty states. Consistent professional design throughout.

**Authentication (§3).** Login, logout, forgot/reset/change password, email verification, profile
management, profile picture, active/inactive/suspended account, last login, login history, IP logging,
device logging, session management. Authorization always checked on the backend.

**RBAC (§4).** Fully database-driven; nothing hardcoded. Default roles: Super Admin, Admin, HR,
Accountant, Project Manager, Developer, Designer, SEO Expert, Digital Marketer, Sales Executive,
Receptionist, Support Agent, Institute Manager, Course Coordinator, Teacher/Trainer, Student, Client,
Collaborator. Admin can create unlimited custom roles. Per-module abilities: view, create, edit,
delete, approve, reject, assign, print, export, import, upload, download, change status, view
financial data, view reports, view logs. Menus, buttons, routes and actions all respect permissions.

**Super Admin (§5).** Manages users, roles, permissions, modules, website, software house, institute,
employees, students, teachers, clients, collaborators, projects, leads, CRM, finance, reports,
settings, logs, backups. No other role automatically inherits Super Admin authority.

**Module management (§6).** Super Admin enables/disables modules: CRM, Projects, HR, Payroll,
Attendance, Finance, Collaborators, Institute, Courses, Students, Teachers, Exams, Certificates,
Support, Blog, Careers, Website CMS. A disabled module hides its sidebar item, blocks routes, blocks
API access, and **preserves existing data**.

---

## B. Public website + CMS (§7–17, §89–92, §100–105)

**Dynamic website (§7).** One professional public site advertising both software-house services and
institute courses, fully managed from the admin panel: enable/disable/reorder sections, edit headings
and descriptions, upload images, change icons, buttons and URLs, manage SEO.

**Header (§8).** Logo, company name, menu, login button, contact button, admission button, CTA button.
Navigation is dynamic.

**Hero (§9).** Heading, subtitle, description, hero image, background image, background video, primary
button, secondary button. Statistics: projects completed, happy clients, students trained, active
courses, team members, years experience.

**About (§10).** Company introduction, software-house introduction, institute introduction, mission,
vision, history, why choose us, images, statistics.

**Services (§11).** Fields: name, slug, category, short description, full description, icon, image,
starting price, technologies, features, SEO title, SEO description, featured, status, display order.
Examples: web development, mobile app development, software development, ERP development, UI/UX,
graphic designing, SEO, digital marketing, e-commerce, cloud solutions, maintenance.

**Portfolio (§12).** Project name, client, category, description, technologies, images, project URL,
completion date, featured, status.

**Team (§13).** Name, photo, designation, department, bio, skills, experience, social links, portfolio
URL, public visibility, display order.

**Testimonials (§14).** Client reviews and student reviews: name, photo, company/course, review,
rating, status. Admin approval required.

**Blog (§15).** Categories, tags, posts, authors, drafts, published, scheduled posts, featured image,
SEO, views, related posts.

**Careers (§16).** Job: title, department, location, employment type, salary range, description,
requirements, experience, deadline, status. Candidate: name, email, phone, CV, portfolio, cover
letter. Statuses: New, Reviewing, Shortlisted, Interview, Selected, Rejected.

**Contact & inquiries (§17).** Public form: name, email, phone, WhatsApp, company, service, course,
budget, subject, message. **Software inquiries route to CRM; course inquiries route to the Institute
Inquiry module.**

**Public course website (§89–90).** Popular/featured courses, upcoming batches, online and physical
courses, trainers, student reviews, success stories, facilities, admission open, apply now. Course
landing page at `/courses/{slug}` showing name, image, description, duration, fee, level, type,
trainer, outline, requirements, outcomes, upcoming batches, FAQs, apply now, WhatsApp inquiry. **The
referral parameter must survive the admission flow.**

**Student reviews (§91).** Student, course, review, rating, photo, video URL — admin approval required.
**Success stories (§92).** Student name, photo, course, story, achievement, company/platform, video,
featured.

**Website CMS (§100).** Admin manages header, footer, hero, about, services, courses, portfolio, team,
trainers, testimonials, student reviews, success stories, statistics, FAQs, blog, careers, contact,
CTAs. Every section supports enable, disable, edit, reorder, status.

**Custom pages (§101).** About, privacy policy, terms, refund policy, course policy, custom pages:
title, slug, content, banner, SEO title, meta description, status.

**Menus (§102).** Header and footer menus: label, parent, URL, icon, display order, open new tab, status.

**System settings (§103).** Company name, logo, favicon, phone, WhatsApp, email, address, business
hours, currency, timezone, date format, time format, theme, social links, Google map, copyright.
Collaborator settings: student commission base, project commission base, commission approval mode,
minimum payout, payout request enabled, referral system enabled, automatic commission enabled.

**SMTP (§104).** Host, port, username, password, encryption, from email, from name, test email
feature, encrypted storage.

**SEO (§105).** Per public page: SEO title, meta description, keywords, canonical URL, Open Graph
image, index/noindex. Sitemap support.

---

## C. CRM, clients, projects (§18–23)

**Leads (§18).** Name, company, phone, WhatsApp, email, country, interested service, budget, lead
source, assigned person, follow-up date, notes, status. Sources: Website, Facebook, Instagram, TikTok,
Google, WhatsApp, Referral, Walk-in, Call, Other. Statuses: New, Contacted, Interested, Negotiation,
Proposal Sent, Won, Lost. **Kanban view required.**

**Clients (§19).** Client ID, name, company, email, phone, WhatsApp, country, address, tax details,
profile, status. Client panel shows projects, tasks, milestones, files, progress, invoices, payments,
meetings, tickets, messages, notifications. **Clients see only their own data.**

**Projects (§20).** Project ID, name, client, **referred by collaborator**, project manager,
employees, collaborators, description, project type, start date, deadline, budget, project value,
priority, progress, status. Statuses: Planning, Pending, In Progress, Review, Testing, Completed, On
Hold, Cancelled.

**Milestones (§21).** Project, name, description, start date, deadline, progress, status.

**Tasks (§22).** Project, title, description, assigned employee, assigned collaborator, reporter,
priority, status, start date, deadline, estimated hours, actual hours. Features: subtasks, checklists,
comments, attachments, activity history, mentions, Kanban board.

**Time tracking (§23).** Start/pause/stop timer, manual entry, daily/weekly hours, employee hours,
collaborator hours, project hours.

---

## D. HR and payroll (§24–28)

**Employees (§24).** Employee ID, name, photo, department, designation, phone, email, address, joining
date, salary, emergency contact, documents, skills, employment type, status.

**Departments (§25).** Dynamic: Development, Design, Marketing, SEO, Sales, HR, Accounts, Support,
Institute, Management.

**Attendance (§26).** Check in, check out, present, absent, late, leave, half day, early leave,
working hours.

**Leave (§27).** Employee, leave type, from date, to date, reason, attachment, status (Pending,
Approved, Rejected).

**Payroll (§28).** Basic salary, allowances, bonus, commission, deductions, advance, tax, net salary.
Generate salary slip.

---

## E. Finance (§29–32)

**Tracked income (§29).** Client payments, project payments, course fees, student fees, admission
fees, collaborator payments, other income, expenses.

**Expenses (§30).** Category, amount, date, description, payment method, receipt, project/institute,
added by, approval status.

**Invoices (§31).** Invoice number, client, project, items, quantity, rate, tax, discount, total, paid
amount, remaining amount, due date, notes. Statuses: Draft, Sent, Partial, Paid, Overdue, Cancelled.

**Payment methods (§32).** Cash, bank transfer, card, manual payment, online-gateway-ready
architecture.

---

## F. Collaborator system (§33–60) — the commercial core

**Who (§33).** Freelancers, agencies, referral partners, business partners, external developers and
designers, marketing partners, consultants, trainers, sales partners. They may bring students, course
admissions, clients, projects and leads. They get a **completely separate panel**.

**Profile (§34).** Collaborator ID, name, company, photo, phone, WhatsApp, email, country, address,
skills, services, collaboration type, joining date, status (Pending, Active, Inactive, Suspended).

**Commission settings (§35).** Per collaborator, independent: student commission enabled, student
commission type, student commission percentage, student fixed commission, project commission enabled,
project commission type, project commission percentage, project fixed commission, commission effective
from, commission effective to, commission status. Types: percentage, fixed amount. (Example:
Collaborator A student 10% / project 15%; Collaborator B 8% / 12%.)

**Dashboard (§36).** Total and active referred students, total referred projects, total project value,
student commission earned, project commission earned, total earnings, pending earnings, available
balance, paid earnings, commission history, assigned projects, assigned tasks, referrals, leads,
meetings, messages, notifications.

**Student referral linking (§37).** At admission: "Referred By Collaborator" — none or select. Store
`collaborator_id`, `referral_code`, `referral_source`, `referral_date`. The student stays linked. If an
admin later changes the collaborator: require permission, store old and new collaborator, record the
reason, create an audit log.

**Referral code (§38).** Every collaborator gets a unique collaborator ID, referral code and referral
URL — e.g. code `COL-1024`, URL `/admission?ref=COL-1024`. Arriving through the URL preselects that
collaborator; receptionist/admin may also select manually.

**Student commission trigger (§39).** **Do NOT pay commission merely because a student registered.**
Commission is created from **fee transactions**. On every valid fee slip/payment the system checks:
does the student have a collaborator; is that collaborator active; is student commission enabled; which
rule applies; what amount is commissionable; has commission already been created for this exact
transaction. No collaborator ⇒ do nothing.

**Student fee commission (§40).** Example: fee paid PKR 10,000 at 10% ⇒ PKR 1,000. Create a commission
ledger entry, a wallet credit, and store student reference, fee slip reference, paid amount, commission
base amount, percentage, commission amount, date, status.

**Fee slip contents (§41).** Student, course, batch, collaborator, gross fee, discount, net fee, paid
amount, commissionable amount, commission percentage, commission amount. Admin configures the
commission base: gross fee, net fee after discount, or actual paid amount — **default: actual paid
amount**, so commission never accrues on unpaid money.

**Installments (§42).** Commission generates per received installment (3 × PKR 10,000 at 10% ⇒ 3 × PKR
1,000). Never pay full commission before payment is received.

**Discounts (§43).** Fee 30,000 − discount 5,000 = net 25,000; at 10% on net paid ⇒ 2,500. The
calculation rule is controlled globally by admin.

**Refund / reversal (§44).** If a student payment is cancelled, reversed or refunded, adjust the linked
commission. **Never silently delete commission history** — create a reversal ledger entry, a negative
commission, a reference to the original commission, the refund reference, date, reason and performer.

**Project referral linking (§45).** Every project supports "Referred By Collaborator", storing
`collaborator_id`, `referral_code`, `referral_date`, `project_commission_type`,
`project_commission_rate`.

**Project commission trigger (§46–48).** Not on project creation — on **received project payment**.
Client pays ⇒ check the project's collaborator ⇒ active? ⇒ rule? ⇒ calculate ⇒ ledger entry ⇒ wallet
credit. Example: project value 200,000 at 15%; client pays 100,000 ⇒ 15,000; pays the rest ⇒ another
15,000; total 30,000. Admin configures the project commission base: total project value, client paid
amount, net project amount after discount, or a specific milestone payment — **default: actual client
payment received**.

**Project payment reversal (§49).** Refund/reversal/cancellation creates a corresponding negative
commission entry. Never delete history.

**Wallet (§50).** Per collaborator: available balance, pending balance, paid balance, total student
commission, total project commission, total adjustments, last updated. **Do not rely only on a stored
balance — all totals must be reproducible from ledger transactions.**

**Commission ledger (§51).** Ledger ID, collaborator, source type, source ID, student/project, fee
slip / payment ID, gross amount, commission base, commission type, commission rate, commission amount,
entry type, status, date, notes. Source types: Student Fee, Student Installment, Project Payment,
Manual Adjustment, Refund Reversal. Entry types: Credit, Debit. Statuses: Pending, Approved,
Available, Paid, Reversed, Cancelled.

**Duplicate prevention (§52).** Critical: the same fee slip or project payment must **never** generate
commission twice. Use unique transaction-level safeguards storing `fee_payment_id`,
`project_payment_id`, `collaborator_id`, `commission_source_type`; check before creation; use database
transactions.

**Approval workflow (§53).** Admin-configurable. Automatic: payment ⇒ commission ⇒ available balance.
Manual: payment ⇒ pending commission ⇒ admin approval ⇒ available balance.

**Payouts (§54).** Payout number, collaborator, amount, payment method, bank details, account details,
transaction ID, requested date, paid date, notes, status (Requested, Pending, Approved, Paid, Rejected,
Cancelled). Admin configures whether collaborators may request withdrawals themselves.

**Payout methods (§55).** Bank account, Easypaisa, JazzCash, other manual method. Sensitive payout data
must be protected.

**Statement (§56).** Opening balance, student commissions, project commissions, manual adjustments,
reversals, payouts, closing balance. Filters: date range, student, project, source, status. Export:
print, PDF, CSV/Excel.

**Collaborator student list (§57).** Own referred students only: name, course, batch, registration
date, status, total paid, commission earned. No sensitive student information unless allowed.

**Collaborator project list (§58).** Own referred/assigned projects: project name, client name, project
value, amount received, commission rate, commission earned, status — each field permission-gated;
sensitive project fields hidden by default.

**Collaborator permissions (§59).** View own dashboard; view own referred students; view student basic
details; view student fee status; view own student commission; view own projects; view project client;
view project value; view project payments; view own project commission; view assigned tasks; update
assigned tasks; upload files; download files; add comments; view meetings; send messages; request
payout; download statement.

**Collaborator activity log (§60).** Login, student referral, project referral, task update, file
upload, file download, commission created, commission approved, commission reversed, payout request,
payout paid.

---

## G. IT Institute (§61–88)

**Courses (§62).** Name, code, slug, category, short description, full description, image, thumbnail,
duration, number of classes, course fee, admission fee, registration fee, installment available, level,
type, trainer, outline, requirements, learning outcomes, certificate available, featured, status.
Types: physical, online, hybrid. Levels: beginner, intermediate, advanced.

**Course examples (§63).** Web development, HTML, CSS, JavaScript, PHP, MySQL, WordPress, graphic
designing, Photoshop, Illustrator, InDesign, video editing, SEO, digital marketing, social media
marketing, Shopify, e-commerce, freelancing, Flutter, React Native, mobile development, game
development, UI/UX, computer basics, office automation. Unlimited courses.

**Categories (§64).** Dynamic: add, edit, delete, enable, disable, reorder.
**Outline (§65).** Course → modules → topics → lectures → assignments → resources.

**Students (§66).** Student ID, registration number, name, father name, gender, date of birth,
CNIC/B-Form, phone, WhatsApp, email, address, city, profile photo, guardian name, guardian phone,
education, school/college, joining date, **referred by collaborator**, referral code, status. Statuses:
Inquiry, Applied, Registered, Active, Completed, Dropped, Suspended.

**Online admission (§67).** Public form: student name, father name, phone, WhatsApp, email, city,
education, course, batch, preferred timing, online/physical, referral code, message. A valid referral
code automatically attaches the collaborator.

**Admission process (§68).** Inquiry → follow-up → application → registration → fee collection → batch
assignment → active student.

**Admission record (§69).** Student, course, batch, collaborator, course fee, discount, scholarship,
admission fee, registration fee, total, paid, pending, payment method, admission date, counselor.

**Batches (§70).** Name, course, teacher, start date, end date, days, start time, end time, classroom,
student capacity, current students, online/physical/hybrid, status.

**Timetable (§71).** Course, batch, teacher, day, start time, end time, classroom, meeting URL, notes.
Views: daily, weekly, teacher-wise, batch-wise, classroom-wise.

**Teachers (§72–73).** Teacher ID, name, photo, phone, email, qualification, experience, skills,
specialization, courses, joining date, salary, bio, status; may link to an employee. Teacher panel:
assigned courses, batches, students, timetable, attendance, course progress, materials, assignments,
exams, results, notifications.

**Student panel (§74).** Profile, course, batch, teacher, timetable, attendance, fees, pending fee,
installments, course progress, materials, assignments, exams, results, certificate, notifications.
**Student sees only own data.**

**Student attendance (§75).** Present, absent, leave, late. Reports: daily, monthly, attendance
percentage, batch attendance.

**Student fees (§76).** Types: course fee, admission fee, registration fee, monthly fee, installment,
exam fee, certificate fee, other. Fields: student, course, batch, **collaborator**, gross fee,
discount, scholarship, net fee, paid amount, pending amount, payment method, due date, receipt number,
status (Paid, Partial, Pending, Overdue).

**Installments (§77).** Student, installment number, amount, due date, paid amount, payment date,
receipt, status. **Commission must follow actual installment payment.**

**Discounts and scholarships (§78).** Fixed discount, percentage discount, scholarship, promotional
discount, referral discount. Track approved by, date, reason.

**Course material (§79).** PDF, notes, images, videos, documents, ZIP, source code, external links;
assigned to course, batch or student.

**Assignments (§80).** Teacher creates title, course, batch, description, file, deadline, total marks.
Student uploads a submission or text and views status. Teacher reviews, marks, adds feedback.

**Exams (§81).** Types: quiz, weekly test, monthly test, midterm, final, practical. Fields: name,
course, batch, date, total marks, passing marks, duration, instructions.

**Results (§82).** Student, exam, obtained marks, total marks, percentage, grade, pass/fail, remarks.
Printable result card.

**Course progress (§83).** Course, module, topic, completion percentage (e.g. HTML completed, CSS
completed, JavaScript in progress, PHP pending).

**Certificates (§84).** Certificate number, student, course, batch, trainer, start date, completion
date, grade, QR verification code. **Public certificate verification page.**

**Student ID card (§85).** Printable: logo, photo, student name, student ID, course, batch, joining
date, QR code. Admin controls the template.

**Course inquiries (§86).** Sources: website, WhatsApp, Facebook, Instagram, TikTok, call, walk-in,
referral. Statuses: New, Contacted, Interested, Demo Scheduled, Admission Confirmed, Not Interested.

**Demo class (§87).** Lead/student, course, teacher, date, time, classroom, meeting URL, status
(Scheduled, Attended, Missed, Converted).

**Institute dashboard (§88).** Cards: total students, active students, new admissions, courses, active
batches, teachers, today's classes, fee collected, pending fees, overdue fees, new inquiries,
collaborator-referred students, student referral commission. Charts: monthly admissions, course-wise
students, fee collection, attendance, inquiry conversion, collaborator-wise admissions.

---

## H. Collaboration, communication, reporting (§93–99, §106–108)

**Support tickets (§93).** Ticket number, user, subject, department, priority, description, attachment,
assigned agent, status (Open, In Progress, Waiting, Resolved, Closed).

**Internal messaging (§94).** Admin↔employee, employee↔employee, client↔project manager,
collaborator↔authorized staff, student↔institute staff, teacher↔institute management. Conversations,
messages, attachments, read status, timestamp.

**Meetings (§95).** Title, date, time, participants, project/course, meeting URL, notes, status.

**Files (§96).** May belong to projects, tasks, clients, employees, collaborators, students, courses,
batches, invoices, tickets. Permissions control visibility.

**Notifications (§97).** In-app: new project, new task, new lead, new admission, new student, fee paid,
fee due, student commission added, project commission added, commission approved, commission reversed,
payout paid, exam scheduled, result published, certificate generated, new message. Architecture must
support email too.

**Admin master dashboard (§98).** Software house: clients, projects, leads, employees, collaborators,
revenue, expenses, profit, project commission. Institute: students, courses, teachers, admissions,
batches, fees, pending fees, inquiries, collaborator referrals, student commissions. Collaborator:
total collaborators, referred students, referred projects, total commission, pending commission, paid
commission, available wallet balance.

**Reports (§99).** Software house: clients, projects, leads, employees, attendance, payroll, invoices,
payments, income, expenses, profit & loss. Institute: students, courses, batches, teachers, attendance,
admissions, fees, pending fees, exams, results, certificates. Collaborator: performance, referred
students, referred projects, student commission, project commission, pending commission, paid
commission, payout history, commission reversals. Filters: today, yesterday, week, month, year, custom
range. Export: print, PDF, CSV/Excel.

**Activity log (§106).** User, action, module, description, IP, device, date, time. Examples: "Admin
changed collaborator commission from 10% to 15%", "Student fee generated collaborator commission",
"Project payment generated commission", "Commission reversed after refund", "Accountant processed
collaborator payout".

**Audit trail (§107).** Sensitive changes store old and new values, plus changed by, date, reason —
e.g. student collaborator A→B, project commission 10%→15%.

**Global search (§108).** Clients, employees, collaborators, students, teachers, courses, leads,
projects, tasks, invoices, tickets — respecting permissions.

---

## I. Engineering requirements (§109–119)

**Database (§109).** Normalised MySQL; foreign keys, indexes, relationships, soft deletes, created_at,
updated_at, created_by, updated_by. Collaborator entities: `collaborators`,
`collaborator_commission_settings`, `collaborator_wallets`, `collaborator_commission_ledger`,
`collaborator_payouts`, `collaborator_referrals`, `student_payments`, `project_payments`. Do not
duplicate financial records; use transaction-safe accounting logic.

**Financial integrity (§110).** Database transactions; prevent duplicate commission; immutable
references; record reversals instead of deleting history; maintain an audit trail; decimal types for
currency; **never floating-point for money**.

**Security (§111).** CSRF, XSS, SQL-injection protection, password hashing, rate limiting, form
validation, authorization, file MIME validation, secure upload, session security, policies, middleware.
All sensitive endpoints verify permission on the backend.

**Data isolation (§112).** Client A cannot see Client B. Student A cannot see Student B's private
records. Collaborator A cannot see Collaborator B's earnings. A teacher sees only assigned batches.
Collaborators see only their own students, projects, commissions, wallet and payouts unless explicitly
granted more.

**Branch-ready (§113).** Prepare the institute for future branches (students, teachers, courses,
batches, fees, timetables, reports) without overcomplicating the initial implementation.

**Backups (§114).** Database backup, file backup, manual backup, scheduled backup; authorized users
only.

**Installation (§115).** `.env.example`, database setup, migration commands, seeder commands, storage
link, queue setup, scheduler setup, production notes. Create the initial Super Admin securely.

**Demo data (§116).** Test accounts for Super Admin, Admin, HR, Accountant, Project Manager, Developer,
Teacher, Receptionist, Collaborator, Client, Student. Sample clients, leads, projects, project
payments, collaborators, courses, students, batches, student fees, commission transactions, wallet
entries, payouts.

**Code quality (§117).** Controllers, models, form requests, services, policies, middleware, events,
jobs, notifications. Avoid massive controllers, duplicate code, hardcoded roles/permissions/website
content, unsafe financial calculations, duplicated commission logic. Dedicated services:
`StudentCommissionService`, `ProjectCommissionService`, `CollaboratorWalletService`, `PaymentService`,
`ReferralService`.

**Phases (§118).** The 25-phase plan tracked in `DEVELOPMENT_LOG.md` §5.

**Execution rule (§119).** Never generate the whole application at once. Per phase: explain the goal,
define tables, define relationships, create migrations, models, validations, services, controllers,
implement permissions, build the frontend, add routes, test functionality, test authorization, test
financial calculations, fix errors, mark completed features, move on. **Never destroy working
functionality. Never delete valid financial history. Never remove existing data during upgrades. Use
safe migrations.**

---

## J. Commission acceptance tests (§120) — the module is not complete until all pass

| # | Scenario | Expected |
|---|---|---|
| 1 | Student **without** collaborator pays a fee | **No** commission row created (not a zero row) |
| 2 | Student with collaborator pays PKR 10,000 at 10% | One commission of PKR 1,000 |
| 3 | The **same fee payment processed twice** | Exactly **one** commission record |
| 4 | Installments: PKR 10,000 paid three times | Three separate commissions |
| 5 | Student refund after a PKR 1,000 commission | A **−1,000 reversal** entry; original preserved |
| 6 | Project **without** collaborator receives payment | No collaborator commission |
| 7 | Project with collaborator: payment 100,000 at 15% | PKR 15,000 ledger credit |
| 8 | Project payment refunded | Commission reversal entry |
| 9 | Wallet 50,000, payout 20,000 | Available balance 30,000; payout history preserved |

---

## K. Final objective

One professional business platform combining Software House ERP, CRM, project management, HR, finance,
IT institute management, students, teachers, courses, admissions, fees, exams and results,
certificates, collaborator referral management, the student and project commission systems, the
collaborator wallet and payout system, and a dynamic corporate website CMS.

The public website advertises software-house services, computer courses, admissions and upcoming
batches. The admin panel controls the entire website and application. Students, teachers, clients and
collaborators each get their own secure panel. Collaborators are automatically linked to the students
and projects they refer; a referred student's valid fee payment and a referred project's valid client
payment each generate that collaborator's configured commission into the ledger and wallet. No
collaborator reference means no commission. All commission calculations must be secure, auditable,
reversible, duplicate-safe and permission-controlled. The architecture must stay scalable so new
services, courses, departments, branches, roles, commission rules and modules can be added without
rebuilding the system.
