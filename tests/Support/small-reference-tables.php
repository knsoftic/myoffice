<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogTag;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\Technology;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\PaymentMethodOption;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\LeaveType;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\WorkShift;
use App\Models\Institute\Classroom;
use App\Models\Institute\CourseCategory;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Support\TicketDepartment;

/*
|--------------------------------------------------------------------------
| Small reference tables (phase-24-25 §6.4 "Pagination", §11.7 PRF-05)
|--------------------------------------------------------------------------
|
| The twenty-one models PRF-05 will let a listing screen load whole.
|
| **A small reference table is one a human maintains by hand, so its row count is bounded by the
| business rather than by usage.** Nobody signs up for a leave type and no customer creates a
| finance category: somebody in the office adds one, deliberately, perhaps twice a year, and the
| table settles at a few dozen rows and stays there. That is the entire test for membership here,
| and it is a test about *who writes the rows*, not about how many there are today. A table that
| grows because the business is doing well — students, fees, receipts, leads, projects, ledger
| entries, courses, batches — is not on this list however small it looks in a fresh install, because
| the whole point of PRF-05 is to catch the screen that was fine on the demo data and times out in
| year three.
|
| PRF-05's rule, in full (§6.4): `::all()`, `->get()` and `->cursor()` on a business model inside an
| `index` / `board` / `calendar` / `export` action fail, unless the model is listed here or the query
| carries an explicit `->limit()`. Exports stream (`LazyCollection` + `chunkById`) and never `->get()`.
|
| **This list is closed to anything that grows with usage, and it is opened only by a numbered
| decision.** The test is not how many rows a table has today: it is whether its size is decided by
| a person maintaining a list or by the system being used. A branch is opened by the business; an
| invoice is not. Adding a model here belongs in `DEVELOPMENT_LOG.md` §4 with a number — never as a
| way to quieten a failing scan. The remedy for a listing that is genuinely too big is
| `->paginate()`, an explicit `->limit()` with a searchable control behind it, or a stream.
|
| **Four were added on PRF-05's first run under D171** (`Branch`, `WorkShift`, `TicketDepartment`,
| `PortfolioCategory`), each the same kind of table as a sibling §6.4 already names — `classrooms`
| and `leave_types` are HR configuration exactly as `work_shifts` is, `blog_categories` is site
| taxonomy exactly as `portfolio_categories` is — so the seventeen §6.4 lists are examples rather
| than a census. They account for 7 of the 44 queries that first run reported. **The other 37 were
| fixed in the controllers**: 17 filter `<select>`s and 18 listings took an explicit `->limit()`, and
| 2 reads that must stay exact — the attendance export and the student result summary — became
| `chunkById` walks. That ratio is the point: the list is the last resort, not the first.
|
| Keyed by model class rather than by table name: PRF-05 reads controller source, where what it can
| see is `LeaveType::query()`, and resolving a class through the file's `use` statements is exact
| where guessing a table name from a class name is not. The value is the table, so a reader can line
| the entry up against `index-manifest.php` and against the contract's own wording.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Access control — phases 1 and 2
    |----------------------------------------------------------------------
    | Roles, permissions and modules are declared in code (`PermissionRegistry`, `ModuleSeeder`) and
    | seeded; settings likewise (`SettingsRegistry`). Their row counts are properties of the source
    | tree, not of the traffic, which is as bounded as a table gets.
    */
    Role::class => 'roles',
    Permission::class => 'permissions',
    Module::class => 'modules',
    Setting::class => 'settings',

    /*
    |----------------------------------------------------------------------
    | Company structure — phase 1
    |----------------------------------------------------------------------
    | **A branch is a building.** `Branch` is "a physical location / campus" (phase-01 §1.5, D11): one
    | exists from the first seed and another appears when the company signs a lease, which is a board
    | decision and not a page view. It is the same argument §6.4 already accepts for `Classroom` — "a
    | room the building actually contains" — one level up, and it is why four `index` screens hand the
    | whole table to a branch `<select>`.
    */
    Branch::class => 'branches',

    /*
    |----------------------------------------------------------------------
    | HR structure — phase 16
    |----------------------------------------------------------------------
    | An organisation chart, a leave policy and a salary structure. Employees grow; the boxes they
    | are filed into do not — and when they do, somebody sat in a meeting about it first.
    */
    Department::class => 'departments',
    Designation::class => 'designations',
    LeaveType::class => 'leave_types',
    SalaryComponent::class => 'salary_components',
    // `WorkShift` is the working day itself — "morning", "evening", a per-branch roster pattern with a
    // `sort_order` an administrator drags. Its index screen is the same inline-editor shape as the
    // leave-type and salary-component screens two lines above, and it is bounded by the same thing:
    // **somebody in the office adds a shift, deliberately, and then nobody adds another for a year.**
    WorkShift::class => 'work_shifts',

    /*
    |----------------------------------------------------------------------
    | Institute catalogue — phases 14 and 17
    |----------------------------------------------------------------------
    | Course *categories* and classrooms, not courses and not batches: a category is a shelf the
    | institute decides to have and a classroom is a room the building actually contains, while
    | courses and batches accumulate every term. That distinction is the whole list in miniature.
    */
    CourseCategory::class => 'course_categories',
    Classroom::class => 'classrooms',

    /*
    |----------------------------------------------------------------------
    | Finance configuration — phase 13
    |----------------------------------------------------------------------
    | The chart of accounts and the ways money may be taken. Configuration of the money system, never
    | a figure from it: no ledger, payment, invoice or fee model is on this list and none may be added
    | (spine INV-13).
    */
    // `PaymentMethodOption` maps to `payment_methods` — the model was renamed away from the table
    // so it would not collide with the `payment_methods` *setting* group, and the table kept its name.
    PaymentMethodOption::class => 'payment_methods',
    FinanceCategory::class => 'finance_categories',

    /*
    |----------------------------------------------------------------------
    | Public website taxonomy — phases 3 and 4
    |----------------------------------------------------------------------
    | The taxonomies an editor curates. `ServiceCategory` and `FaqCategory` are here while `Service`
    | and `Faq` are not, and `BlogTag` is here while `BlogPost` is not: a tag is coined once, a post
    | is written every week.
    */
    ServiceCategory::class => 'service_categories',
    Technology::class => 'technologies',
    BlogCategory::class => 'blog_categories',
    BlogTag::class => 'blog_tags',
    FaqCategory::class => 'faq_categories',
    // `PortfolioCategory` sits one line above `Technology` in `Site\PortfolioController::index` — the same
    // `->public()->orderBy('sort_order')->get()`, the same filter rail — and `Technology` is on this list
    // while the category was left off it. **A portfolio category is coined once, a portfolio item is
    // added with every project delivered**; `PortfolioItem` is therefore paginated and stays off the list.
    PortfolioCategory::class => 'portfolio_categories',

    /*
    |----------------------------------------------------------------------
    | Support desk configuration — phase 22
    |----------------------------------------------------------------------
    | The queues a ticket can be filed into ("Billing", "Technical"), each with an SLA and a sort order a
    | manager maintains — `Department` for the help desk. Tickets accumulate; the two or three boxes they
    | are filed into do not.
    */
    TicketDepartment::class => 'ticket_departments',

];
