<?php

declare(strict_types=1);

use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogTag;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\Technology;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\PaymentMethodOption;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\LeaveType;
use App\Models\Hr\SalaryComponent;
use App\Models\Institute\Classroom;
use App\Models\Institute\CourseCategory;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;

/*
|--------------------------------------------------------------------------
| Small reference tables (phase-24-25 §6.4 "Pagination", §11.7 PRF-05)
|--------------------------------------------------------------------------
|
| The seventeen models PRF-05 will let a listing screen load whole.
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
| **This list is closed.** It is the seventeen the contract names and nothing else. Adding a model to
| it is a decision about the shape of the business, which belongs in `DEVELOPMENT_LOG.md` §4 with a
| number — never a way to quieten a failing scan. The remedy for a listing that is genuinely too big
| is `->paginate()`, an explicit `->limit()` with a searchable control behind it, or a stream.
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
    | HR structure — phase 16
    |----------------------------------------------------------------------
    | An organisation chart, a leave policy and a salary structure. Employees grow; the boxes they
    | are filed into do not — and when they do, somebody sat in a meeting about it first.
    */
    Department::class => 'departments',
    Designation::class => 'designations',
    LeaveType::class => 'leave_types',
    SalaryComponent::class => 'salary_components',

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

];
