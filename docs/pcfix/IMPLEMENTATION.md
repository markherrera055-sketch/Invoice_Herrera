# PCFix Hub — TO-BE Process Implementation on Invoice Ninja v5

PCFix Hub (computer repair, IT support and managed IT services; Oriental Mindoro, Philippines) moves from
invoice-centred, partly manual operations to one integrated platform built on the existing
**Invoice Ninja 5.13.49** code base. Invoice Ninja was extended, not rebuilt or replaced.

---

## 0. Architecture decisions (from the repository inspection)

| Finding | Decision |
|---|---|
| Backend is Laravel 12 / PHP 8.3 / MySQL; live data already exists. | Every schema change is **additive** (new tables, nullable/defaulted columns). A DB backup was taken before migrating: `storage/app/pcfix-backups/ninja-before-pcfix-2026-11-15.sql`. |
| The admin UI ships as **compiled** Flutter (`public/main.dart.js`) and React (`public/react`) bundles. Their source is not in this repo. | Staff features live in a new server-rendered **Operations Console** at `/ops`. It reuses Invoice Ninja accounts, sessions, CSRF and permissions, and deep-links into the existing admin app for invoices, quotes, payments and expenses. |
| Customers already use the Blade **client portal** (`/client`), which already supports quote approve/reject, invoices and payments. | Customer features (repair requests, tracking, warranty claims) were added **inside the existing portal**, and the existing quote-approval pages are reused. |
| Invoice Ninja carries the request's company/user/token in `TruthSource`. Permissions are strings on `company_user`; `EntityPolicy` compares `company_id`. | The console sets the same `TruthSource` (plus a request-scoped copy), so the existing auth keeps working. Operations roles are stored in `company_user.ops_role` and enforced with Laravel Gates `ops.*`. |
| `BaseModel::resolveRouteBinding` does **not** scope by company. | New controllers never use implicit binding. Every lookup goes through `OpsModel::forCompanyOrFail()` (IDOR guard). |
| Invoice Ninja already has Clients, Quotes (+ approval events), Invoices, Payments, reminders, receipts, **Products with stock fields + vendor_id**, **Vendors**, **Purchase Orders**, Expenses, `NinjaMailer`. | All of these are reused. New tables only exist where Invoice Ninja has no equivalent. |
| Invoice Ninja deducts stock on invoice create/convert when `track_inventory` is on, and `auto_convert_quote` (default **true**) turns an approved quote into a sent invoice immediately. | Parts are deducted **once**, when used on a repair. Repair invoices are built through the repository without a second deduction. Quotes linked to a repair job are never auto-converted; one guarded line was added in `QuoteService::approve`. A test caught this double deduction. |

### Modified Invoice Ninja files (all additive, minimal)

| File | Change |
|---|---|
| `config/app.php` | Registers `App\Providers\PCFixServiceProvider`. |
| `app/Http/Kernel.php` | Adds the `ops_auth` middleware alias. |
| `app/Http/ViewComposers/PortalComposer.php` | Adds "Repairs" and "Warranty" to the client portal sidebar. |
| `app/Services/Quote/QuoteService.php` | `approve()` skips auto-convert for repair-job quotes (`InvoiceGenerator::defersInvoicing`). |
| `.env.example` | Documents optional `PCFIX_*` variables. |

Everything else is new code under `app/**/Ops`, `resources/views/ops`, `routes/ops*.php`, `config/pcfix.php`.

---

## 1. Implemented TO-BE processes

### Module 1 — Customer service request and job intake
Customer submits (public form / client portal / staff for phone & walk-in) → **validated** (FormRequest, honeypot,
throttling) → **duplicate-submission guard** (same email/contact + serial + issue within 10 min returns the
existing request) → **duplicate customer check** (email, phone last-10-digits, ≥85% name similarity, name+address)
→ exactly one strong match is linked automatically; anything else is flagged for staff review → unique number
`SR-YYYY-00001` → staff link an existing customer or create one through `ClientRepository` → technician records
the diagnosis → repair job + **Invoice Ninja quote** emailed → customer approves or rejects digitally in the
existing portal → job scheduled and assigned.

### Module 2 — Repair job assignment and service execution
Statuses: Pending, Awaiting Approval, Approved, Assigned, In Progress, Waiting for Parts, Completed, Cancelled
(guarded transition map). Priorities Low/Normal/High/Urgent. **Deterministic assignment**: eligible = technician
profile + available + account active + technician role + required skill (inferred from device/issue, editable) +
below capacity; ranked by weighted workload (urgent 4 … low 1), then job count, then least recently assigned.
Urgent work may exceed capacity by one. Managers can override, and the reason is logged. Technician workspace:
start/pause, work notes and diagnosis (optionally customer-visible), parts used (atomic stock deduction), revised
quotation for additional work (work pauses until the customer approves; a rejection resumes the original scope),
completion confirmation → accounting notified. Repair history per customer and per device.

### Module 3 — Automated invoice generation and payment processing
Repair completed → accounting **verifies** → invoice **generated automatically** via `InvoiceRepository`
(Invoice Ninja calc engine, numbering, invitations) from all customer-approved quotes. Parts used beyond the
approved quantities are billed only if accounting opts in. The invoice is linked to customer, service request,
repair job and quotes (quotes are marked *converted*), then emailed with the native invoice mailer. Payments,
status sync (Unpaid / Partially Paid / Paid / Overdue), receipts and email reminders remain **Invoice Ninja's own
engine**. A listener records payments on the repair timeline. Optional SMS overdue reminders (Twilio). Payment
monitoring dashboard.

### Module 4 — Warranty and after-sales
A warranty (default 90 days, parts & labor) is created automatically when a paid repair is verified, linked to
customer, device, repair job and invoice. Status Active / Expiring Soon / Expired / Claimed / Void is computed
from dates and open claims. Claim (portal or staff) → **automatic eligibility check** (by warranty, serial or
customer) → technician verification (re-checked at decision time) → **approve**: high-priority warranty job,
auto-assigned, covered parts/labor billed at zero (no invoice when nothing is chargeable) / **reject**: a regular
job is opened for a paid Invoice Ninja quotation → claim history updated. A daily job sends one "expiring soon"
notice per warranty.

### Module 5 — Expense, inventory and supplier management
**Inventory = Invoice Ninja products** (no parallel item table) + `track_stock`, category, storage location,
minimum level (`stock_notification_threshold`) and supplier (`vendor_id`). Every change goes through a
row-locked ledger (`inventory_movements`): stock in/out, physical count, repair usage/return, purchase receipt.
**Low stock** → inventory staff notified (once per item per day) and a purchase request is raised automatically
(one open request per item). **Purchase request → manager approval** (no self-approval) → **Invoice Ninja
Purchase Order** → partial or full **receipt** updates stock and latest cost and marks the PO *Received* →
supplier invoice recorded as an **Invoice Ninja expense** linked to the vendor. Covers software license
requests. Suppliers are Invoice Ninja vendors, shown with products supplied, purchase history and expense /
unpaid totals. Monthly expense summaries by category. **Inventory reports**: stock-level / valuation report and
stock-movement ledger (type and date filters) exported as CSV from *Inventory Items* and *Stock Movements*.

### Module 6 — Financial reporting and customer account management
Dashboard and reports computed from live records using **the same formulas as Invoice Ninja's ChartQueries**:
revenue (payments − refunds), expenses (with taxes), net income, outstanding / overdue receivables, paid /
unpaid / partial counts; sales trend by day/week/month/year (SVG bar chart with hover tooltips and a table
view); repair, inventory and customer analytics; date / month / year filters; CSV export (formula-injection
safe) and print-to-PDF. Figures in other currencies are reported separately, never mixed.

---

## 2. Existing Invoice Ninja modules reused

Clients & contacts (`ClientRepository`), Quotes (`QuoteRepository`, `QuoteService` markSent / sendEmail /
approve / reject, portal approval pages, `QuoteWasApproved/Rejected/MarkedApproved` events), Invoices
(`InvoiceRepository`, calc engine, `markSent`, `sendEmail`), Payments (gateways, status sync, receipts,
`PaymentWasCreated`), reminders, Products, Vendors (`VendorRepository`), Purchase Orders
(`PurchaseOrderRepository`), Expenses & categories (`ExpenseRepository`), authentication (users, password hashes,
Google 2FA secret, company tokens via `CreateCompanyToken`), `TruthSource`, the mailer (`NinjaMailerJob` +
`email.admin.generic`), the client portal layout & sidebar, the hashed-id scheme, and the Laravel scheduler.

## 3. Newly developed modules

| Area | Code |
|---|---|
| Models | `app/Models/Ops/*` (ServiceRequest, Device, RepairJob, RepairJobUpdate, RepairJobPart, RepairJobQuote, TechnicianProfile, InventoryMovement, Warranty, WarrantyClaim, PurchaseRequest(+Item), OpsNotification, OpsAuditLog, OpsSequence) |
| Services | `app/Services/Ops/*`: ServiceRequestService, CustomerMatcher, RepairJobService, TechnicianAssigner, QuoteBuilder, InvoiceGenerator, WarrantyService, InventoryService, PurchaseRequestService, DashboardMetrics, OpsAccess, OpsNotifier, SmsGateway, AuditLogger, OpsUi |
| HTTP | `app/Http/Controllers/Ops/*` (console + `Api/OpsApiController`), `ClientPortal/OpsPortalController`, `Http/Requests/Ops/ServiceRequestSubmission`, `Http/Middleware/OpsAuthenticate` |
| Events/automation | `app/Listeners/Ops/*`, `app/Console/Commands/PCFixDaily.php` |
| Config / routes | `config/pcfix.php`, `routes/ops.php`, `routes/ops_portal.php`, `routes/ops_api.php`, `app/Providers/PCFixServiceProvider.php` |

## 4. Database changes and relationships

Migration: `database/migrations/2026_11_15_000001_create_pcfix_operations_tables.php` (tested up → down → up).

| Table | Purpose / key relations |
|---|---|
| `company_user.ops_role` *(new column)* | Operations role per user and company |
| `products.track_stock, category, storage_location` *(new columns)* | Inventory attributes on existing products |
| `ops_sequences` | Row-locked per-company numbering (SR/RJ/WR/WC/PR) |
| `technician_profiles` | user ↔ company: skills (JSON), branch, availability, capacity |
| `devices` | client → devices (type, brand, model, serial) |
| `service_requests` | → client, contact, device, repair_job, quote; duplicate flags, submission hash |
| `repair_jobs` | → client, device, service_request, technician (users), quote, invoice, warranty_claim |
| `repair_job_quotes` | job ↔ Invoice Ninja quotes (initial / revision) |
| `repair_job_updates` | Status history, notes, diagnosis, customer visibility |
| `repair_job_parts` | job → product (qty, cost, price, warranty-covered) → inventory_movement |
| `inventory_movements` | product ledger: signed qty, balance after, cost, reference |
| `warranties` | → client, device, repair_job, invoice; coverage, dates, status |
| `warranty_claims` | → warranty, client, contact, device, repair_job, quote; eligibility snapshot |
| `purchase_requests` / `purchase_request_items` | → vendor, purchase_order (BIGINT), expense; items → product |
| `ops_notifications` | In-app inbox + delivery log; **unique dedupe key** per channel+recipient |
| `ops_audit_logs` | Append-only audit trail |

All tables have foreign keys, company-leading indexes, and soft deletes where records are user-facing.

## 5. API endpoints (new) — `X-API-TOKEN` (existing token auth), hashed ids

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/pcfix/dashboard?from&to&grain` | reports.financial |
| GET / POST | `/api/v1/pcfix/service-requests` | service_requests.view / .manage |
| GET | `/api/v1/pcfix/service-requests/{id}` | service_requests.view |
| GET | `/api/v1/pcfix/repair-jobs` | view_all, or own jobs for technicians |
| GET | `/api/v1/pcfix/repair-jobs/{id}` | view_all or assigned |
| POST | `/api/v1/pcfix/repair-jobs/{id}/status` | work (assigned) / manage |
| POST | `/api/v1/pcfix/repair-jobs/{id}/assign` | repair_jobs.manage (empty body = auto-assign) |
| GET | `/api/v1/pcfix/inventory?low=1` | inventory.view |
| POST | `/api/v1/pcfix/inventory/{id}/adjust` | inventory.manage |
| GET | `/api/v1/pcfix/warranty/check?client_id&serial_number` | warranty.view |
| GET | `/api/v1/pcfix/purchase-requests` | create / approve / receive |
| POST | `/api/v1/pcfix/purchase-requests/{id}/decide` | purchase_requests.approve |

No existing endpoint was changed.

## 6. New frontend pages

**Operations Console (`/ops`)**: Login (Invoice Ninja credentials + 2FA), Dashboard (role-aware queues, financial
cards, revenue vs expense chart, repair & parts analytics), Notifications · Customers (list, profile with repair /
warranty / transaction history, duplicate-profile finder) · Service Requests (list with status tabs, new, detail
with duplicate review, edit, evaluate & quote) · Repair Jobs (prioritised queue, detail/workspace with timeline,
quotes, parts, revision, completion, assignment ranking & override), Technician Assignments, Repair History ·
Billing & Payments (ready-to-invoice queue, payment monitoring buckets, automation status) · Warranty Records,
Warranty detail/edit, Warranty Claims, Claim verification, Serial check · Inventory Items, item detail/ledger,
create/edit, Stock Movements, Low Stock, CSV inventory reports · Suppliers, Purchase Requests (create, approve, order, receive, record
expense), Purchase Records · Expenses (category & monthly summaries), Financial Reports (filters, CSV, print) ·
Users & Roles (with permission matrix), Settings, Audit Log.

**Public**: `/service-request/{company_key}` online request form + confirmation page.

**Client portal (`/client`)**: Repairs (list, request a repair, request/repair tracking with progress steps,
quotations and customer-visible updates), Warranty (my warranties, submit claim, claim status).

UI: self-contained stylesheet (`public/css/pcfix-ops.css`, `pcfix-portal.css`; no build step and no new npm
dependency), light/dark mode, responsive (collapsible sidebar on phones), consistent badges with text labels,
empty / error states, confirmation prompts for irreversible actions, print styles.

## 7. Automated workflows and notifications

| Trigger | Action |
|---|---|
| New service request | Service managers notified (in-app + email); customer acknowledged (portal) |
| Quote approved (portal or admin) | Job → Approved, manager notified, technician auto-assigned and notified |
| Quote rejected | Initial: job cancelled. Revision: work resumes on original scope. Manager notified |
| Status change | Customer emailed (SMS for "completed" / "quote waiting" if configured) |
| Repair completed | Accounting notified; job enters the ready-to-invoice queue |
| Completion verified | Invoice generated and emailed; warranty created / claim closed |
| Payment received | Native status update + receipt (Invoice Ninja); repair timeline entry; billing notified |
| Invoice overdue | Native email reminders; optional SMS (daily, deduplicated per interval) |
| Warranty expiring | Customer notified once (daily job) |
| Stock ≤ minimum | Inventory staff notified (once per day); purchase request auto-raised (once) |
| Purchase request submitted / approved | Approvers notified / purchasing staff and requester notified |

Duplicate notifications are prevented by a unique key in `ops_notifications`. Email goes through
`NinjaMailerJob` (company mail settings). Email failures never roll back business transactions.

## 8. Role-based access control and security

Roles: Administrator, Service Manager, Technician, Accounting Staff, Inventory Staff, Management (staff), plus
Customer (portal contact). Invoice Ninja owners and admins hold every ability. The ability matrix is in
`config/pcfix.php` and shown on *Users & Roles*. Optionally, the matching Invoice Ninja permissions are merged in
(never removed).

- **Server-side enforcement**: route `can:ops.*` Gates and record-level checks in controllers (technicians can
  only open or act on assigned jobs; requesters see their own purchase requests; no self-approval).
- **IDOR**: every lookup is company-scoped (`forCompanyOrFail`); portal lookups are scoped to the contact's client.
- **CSRF** (web group), **SQL injection** (Eloquent bindings; raw analytics SQL uses bound parameters only),
  **XSS** (Blade escaping; notification bodies escaped because the shared email template prints unescaped),
  input validation and length limits, strip_tags on public input, honeypot, throttling on login / public / portal
  submits, session regeneration on login, `Cache-Control: no-store`, `X-Frame-Options`, `nosniff`, CSV
  formula-injection guard.
- **Integrity**: DB transactions with row locks for numbering, assignment, status changes, stock movements and
  invoicing; double-invoicing and over-receiving are blocked.
- **Audit**: logins, role changes, assignments and overrides, stock adjustments, approvals, invoicing,
  warranty changes.

## 9. Testing results

Test suite: `tests/Feature/PCFix/*` — **38 tests, all passing** (run against a separate `ninja_test`
database; see the totals reported with this delivery).

| File | Covers |
|---|---|
| `ServiceRequestIntakeTest` (7) | Public form, auto-link by email without duplicate client, idempotent re-submit, validation + honeypot, new client via IN repository, weak-match flagging, notification de-duplication |
| `RepairWorkflowTest` (10) | Real IN quote, native approval → least-loaded skilled technician, no-eligible + manual override, urgent capacity rule, atomic stock deduction & rollback, revision approve/reject, rejected quote cancels, invoice total from IN calc + quote converted + **stock counted once with track_inventory on** + warranty, opt-in unquoted parts, invalid transitions |
| `WarrantyAndInventoryTest` (8) | Eligibility (covered / expired / none), status computation, free warranty repair (no invoice), expired claim cannot be approved → paid job, single expiry notice, low stock → one purchase request + one email, full request→approval→PO→partial/full receipt→expense, over-receipt blocked |
| `ConsoleActionsTest` (5) | Every console form over HTTP as the browser posts it: full repair lifecycle (walk-in → edit → line-editor quote → approval → re-prioritise/re-assign → start/diagnosis/parts add-return/over-stock error → revision → complete → billing generate → portal view → notifications), intake review (link/create client, cancel), warranty forms (edit, staff claim, decide, portal claim, void), inventory & procurement forms (supplier create/update, item create/duplicate SKU/update/adjust/count, request → reject-without-reason error → approve → PO → receive → expense, CSV inventory reports + formula-injection guard, archive), admin role + native-permission merge + technician profile, owner/self-change protection |
| `AccessControlAndUiTest` (8) | Login with IN credentials + audit, **all 34 console pages render**, CSV export, technician isolation, role matrix, cross-company IDOR, portal isolation & submission, API token + abilities, dashboard revenue equals DB and moves exactly with a new payment |

Regression: see the summary delivered with this document for the result of Invoice Ninja's own related tests.
Live smoke test on the development database: migration applied with existing data intact; `/ops/login`,
public form, 404 for unknown company, API 403 without token; `pcfix:daily` ran cleanly and is scheduled.

## 10. Setup and execution

```bash
# 1. (Already done on this machine) back up, then migrate — additive only
mysqldump -uroot ninja > backup.sql
php artisan migrate

# 2. Optional settings in .env (see .env.example "PCFix Hub" block)
# 3. Scheduler (daily automation at 07:00 is registered automatically)
php artisan schedule:work            # or a cron entry: * * * * * php artisan schedule:run
php artisan pcfix:daily              # run manually

# 4. Open the console
#    http://<APP_URL>/ops            -> sign in with an Invoice Ninja owner/admin account
#    Users & Roles  -> assign roles (Technician also creates a technician profile)
#    Technician Assignments -> set skills, branch, availability, capacity
#    Settings -> copy the public service-request link
#    Inventory -> enable/create stock-tracked items with minimum levels and suppliers

# 5. Tests (separate database)
mysql -uroot -e "CREATE DATABASE ninja_test"
DB_DATABASE=ninja_test php artisan migrate --force
DB_DATABASE=ninja_test php vendor/bin/phpunit tests/Feature/PCFix
```

Recommended Invoice Ninja settings: keep payment receipts and reminders enabled (Settings → Client Portal /
Reminders). `auto_convert_quote` can stay on; repair quotes are handled automatically.

## 11. Known limitations and external integrations

- **Admin app (Flutter/React)** cannot be modified from this repository (compiled bundles only). New staff
  features are in the console; the admin app still manages invoices, quotes, payments, clients and vendors.
- **SMS** needs Twilio credentials (`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `PCFIX_SMS_FROM`). Without them
  SMS is skipped and logged. **Messenger** is not implemented (no supported integration).
- **BambooHR, SAP, Zoho Inventory, Xero, Microsoft AD** are documented context only. No credentials exist and
  no live integration was built. The inventory ledger replaces the separate spreadsheet (P9). A Zoho/SAP sync
  would need their API credentials.
- Deleting a repair invoice in the admin app while `track_inventory` is on lets Invoice Ninja's native logic add
  the parts back to stock (existing behaviour). Use *cancel* or *credit* instead of delete for repair invoices.
- Supplier invoices from purchase requests are recorded in the company currency.
- Customers submitting through the public form receive an on-screen reference number. Email acknowledgements go
  to known portal contacts.
- Duplicate-profile merging uses Invoice Ninja's existing *Merge client* feature (linked from the duplicate finder).

---

## AS-IS pain point → TO-BE improvement mapping

| ID | AS-IS pain point | TO-BE improvement (where) |
|---|---|---|
| P1 | Customers delay approving quotations; jobs postponed | Quote emailed instantly with portal approval link; "Action needed" banner and progress tracker in the portal; quote-waiting SMS (if configured); Awaiting Approval queue on the dashboard; manager notified on approve/reject (RepairJobService, portal `_progress`) |
| P2 | Manual encoding → incorrect/duplicate customer & device records | Standard validated intake form (public, portal, staff); customers self-enter data; device registry by serial; automatic duplicate detection (CustomerMatcher, ServiceRequestSubmission) |
| P3 | Jobs not assigned immediately; technicians overloaded | Automatic, deterministic assignment on approval by availability, skills and weighted workload; capacity limits; manager override with reason; technician workload view (TechnicianAssigner) |
| P4 | Technicians delay updating completion → late invoices | One-click completion in the technician workspace → accounting notified → ready-to-invoice queue → one-click verify & auto-generate invoice (RepairJobService::complete, InvoiceGenerator) |
| P5 | Customers take days to approve quotations | Digital approval in the portal (approve/reject), revised quotes for extra work, real-time status visibility, reminders through notifications; approval instantly triggers assignment |
| P6 | Late payments | Invoice generated and emailed the moment work is verified; native reminders + optional SMS overdue reminders; payment monitoring dashboard with overdue bucket; outstanding balances per customer |
| P7 | Warranty info not updated → confusion | Warranty auto-created on every verified paid repair; computed status; automatic eligibility by serial; claim workflow with technician verification and history; expiry notices (WarrantyService) |
| P8 | Spare-part purchases recorded outside Invoice Ninja | Purchase requests → Invoice Ninja purchase orders → supplier invoice recorded as Invoice Ninja expense linked to vendor (PurchaseRequestService) |
| P9 | Inventory not integrated; separate spreadsheet | Inventory on Invoice Ninja products with a locked movement ledger, automatic deduction on repair use, low-stock alerts and auto purchase requests, stock receipts (InventoryService) |
| P10 | Reports depend on timely data entry | Data captured at the source (intake, parts, completion, receipts) and reports computed live from records; dashboard with filters, trends and CSV/PDF export (DashboardMetrics) |
| P11 | Duplicate customer profiles | Auto-link on strong match, review queue for possible matches, explicit "create new customer" confirmation, duplicate-profile finder for existing data (CustomerMatcher, CustomerController) |
