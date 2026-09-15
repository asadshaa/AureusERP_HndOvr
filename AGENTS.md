# Aureus ERP — AGENTS.md

<laravel-boost-guidelines>

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely when building this Laravel application.

## Foundational Context

This application is a Laravel application.

IMPORTANT: Treat the actual repository and installed dependencies as the source of truth for package versions.

Before making version-sensitive changes, verify versions using:

- `composer.json`
- `composer.lock`
- `package.json`
- `package-lock.json`
- Laravel Boost application information when available

Do not assume a Laravel, Filament, Livewire, PHP, Pest, PHPUnit, Tailwind, or other package version when the repository shows otherwise.

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `pest-testing` — Tests applications using Pest. Activate when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, browser testing, debugging test failures, working with datasets or mocking; or when the user mentions tests, specs, TDD, expects, assertions, coverage, or needs to verify functionality works.
- `tailwindcss-development` — Styles applications using Tailwind CSS. Activate when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyling, cards, buttons, or other visual/UI changes.

## Conventions

- Follow all existing code conventions used in this application.
- When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods.
- Check for existing components, services, models, actions, and abstractions before writing new ones.
- Prefer reuse over duplication.
- Do not create new top-level/base directories without approval.
- Do not change application dependencies without approval.

## Verification Scripts

Do not create temporary verification scripts or use Tinker when automated tests already cover the functionality and prove that it works.

Tests are the preferred verification mechanism.

## Frontend Bundling

If a frontend change is not reflected in the UI, consider whether the application needs:

```bash
npm run build
```

or:

```bash
npm run dev
```

or the repository's existing development command.

## Documentation Files

Only create documentation files when explicitly requested by the user.

## Replies

Keep explanations concise and implementation-focused.

Focus on:

- what changed
- what was verified
- what failed
- what requires user action

Do not explain obvious concepts unless asked.

=== boost rules ===

# Laravel Boost

Laravel Boost is available for application-aware inspection and Laravel ecosystem guidance.

Use Boost whenever its tools are relevant.

## Searching Documentation

Use Boost's `search-docs` before making Laravel ecosystem implementation decisions when available.

This is especially important for:

- Laravel
- Filament
- Livewire
- Pest
- Tailwind CSS
- Sanctum
- Laravel MCP
- other installed Laravel ecosystem packages

Boost automatically uses the installed package versions.

Use broad, simple, topic-focused queries.

Examples:

```text
bulk table actions
resource table testing
eloquent relationships
file uploads
database transactions
```

Do not unnecessarily include package names in queries because Boost already knows the installed packages.

## Artisan

Use `list-artisan-commands` when an Artisan command or its available parameters are uncertain.

When generating files through Artisan, use the appropriate:

```bash
php artisan make:...
```

command.

Pass:

```bash
--no-interaction
```

where applicable.

## URLs

Whenever sharing an application URL, use Boost's `get-absolute-url` when available instead of guessing the scheme, host, IP, domain, or port.

## Tinker / Debugging

Use Boost's `tinker` when PHP/application execution is required for debugging.

Use `database-query` when only read-only database inspection is required.

Use `database-schema` before making assumptions about database tables, columns, indexes, or relationships.

## Browser Logs

Use `browser-logs` for browser/runtime errors when available.

Only recent logs should be considered relevant.

=== php rules ===

# PHP

Always use curly braces for control structures, including single-line bodies.

Use explicit return type declarations for methods and functions.

Use appropriate PHP type hints for parameters.

Example:

```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    //
}
```

Use constructor property promotion where appropriate.

Example:

```php
public function __construct(
    protected ExchangeRateService $exchangeRates,
) {}
```

Do not create public empty constructors.

Prefer PHPDoc blocks over unnecessary inline comments.

Only use inline comments when logic is unusually complex and cannot be made clear through naming and structure.

Add useful array-shape PHPDoc definitions where appropriate.

Follow the repository's existing Enum conventions.

=== laravel/core rules ===

# Laravel Application Rules

Use Laravel-native approaches unless Aureus ERP already establishes another convention.

Prefer:

- Eloquent models
- Eloquent relationships
- policies and gates
- service classes
- Form Requests
- named routes
- queues for expensive operations
- configuration files
- events/listeners where appropriate

## Database

Prefer Eloquent models and relationships over raw SQL.

Avoid `DB::` when Eloquent or an existing model abstraction provides the required functionality.

Laravel Query Builder may be used for genuinely complex operations or where existing application architecture already uses it appropriately.

Prevent N+1 query problems using eager loading.

Before modifying the database:

1. Inspect the existing schema.
2. Inspect relevant models.
3. Inspect existing migrations.
4. Inspect relationships and foreign keys.
5. Inspect multi-company implications.
6. Inspect existing tests.

Never assume a table or column does not exist.

Preserve all existing column attributes when modifying columns.

Respect foreign-key and deletion behavior.

Do not introduce destructive migrations without explicitly identifying their consequences.

## Model Creation

When creating models, use the appropriate Artisan generator.

Create useful factories and seeders where consistent with existing application conventions and required by the task.

## Controllers & Validation

Use Form Request classes for validation rather than large inline controller validation blocks.

Follow existing sibling Form Request conventions.

## Authentication & Authorization

Use Laravel's authentication and authorization features.

Never rely solely on frontend/UI visibility for authorization.

Authorization must also be enforced server-side.

## URL Generation

Prefer named routes and:

```php
route(...)
```

when generating internal application links.

## Queues

Use queued jobs implementing `ShouldQueue` for time-consuming operations where appropriate.

## Configuration

Do not use:

```php
env(...)
```

directly in application code.

Use configuration values such as:

```php
config('app.name')
```

Environment variables belong in configuration files.

=== application architecture rules ===

# Aureus ERP Architecture

Aureus ERP is a modular ERP application.

Important areas include:

- Accounting
- Accounts / Chart of Accounts
- Banking
- Bank Statement Imports
- Transaction Mapping
- FS Tags
- Journal Entries
- General Ledger
- Financial Reporting
- Multi-company accounting
- Multi-currency accounting
- Permissions
- Plugin management
- Filament administration
- Supporting ERP modules

Always respect existing plugin/module boundaries.

Before creating new abstractions, determine whether equivalent functionality already exists elsewhere in the ERP.

=== multi-company rules ===

# Multi-Company Safety

Aureus ERP is multi-company.

Any business or accounting change must be evaluated for company isolation.

Determine whether records must be scoped through:

- `company_id`
- authenticated user's company
- company relationships
- explicit cross-company permissions

Never allow one company's records to leak into another company's:

- accounts
- bank statements
- transactions
- transaction mappings
- journals
- reports
- imports
- configurations
- operational data

Never remove company scoping simply to make a query work.

=== accounting rules ===

# Accounting Integrity

Accounting changes require additional scrutiny.

Before modifying accounting functionality, inspect affected:

- accounts
- Chart of Accounts
- bank statements
- bank statement lines
- transaction mappings
- FS Tags
- journal entries
- journal lines
- posting logic
- reconciliation
- exchange rates
- currencies
- tax treatments
- cash-flow categories
- reports
- company boundaries

Accounting workflows must preserve:

- balanced journal entries
- debit/credit integrity
- auditability
- company isolation
- currency integrity
- source traceability
- posting status
- reconciliation integrity

Do not silently modify posted accounting records unless the existing architecture explicitly supports the operation.

=== bank import rules ===

# Bank Import & Transaction Mapping

When changing bank imports, trace the complete workflow:

```text
Source File
    ↓
Parser
    ↓
Normalized Transaction
    ↓
Bank Statement
    ↓
Bank Statement Line
    ↓
Transaction Mapping
    ↓
Review / Approval
    ↓
Draft Journal
    ↓
Posting
    ↓
General Ledger
    ↓
Financial Reports
```

Do not fix an import problem only at the UI layer when the underlying imported data is incorrect.

Bank imports must preserve original source information required for auditability.

For CSV/Excel supplied fields, distinguish between:

- source-provided values
- system-derived values
- FS Tag-derived values
- rule-derived values
- manually reviewed values

Preserve provenance where the application supports it.

Invalid or unresolved source values should be surfaced for review rather than silently discarded.

=== fs tag rules ===

# FS Tags

FS Tags are part of the accounting transaction mapping workflow.

When changing FS Tag behavior, inspect:

- `accounting_fs_tags`
- FS Tag models
- FS Tag services
- bank statement imports
- transaction mappings
- matching services
- journal generation
- reporting

FS Tag matching must respect:

- company boundaries
- active/inactive status
- configured FS Tag code
- configured account relationships

Do not assume FS Tag names and FS Tag codes are interchangeable.

Where an import supplies an FS Tag code, match against the configured FS Tag code according to the required workflow.

=== financial reporting rules ===

# Financial Reporting

Financial reports must derive from authoritative accounting data.

Avoid duplicating accounting truth into report-specific storage unless existing architecture explicitly requires snapshots.

When modifying reporting, inspect effects on:

- General Ledger
- Trial Balance
- Profit & Loss Statement
- Balance Sheet
- Cash Flow Statement
- reconciliation reports
- company-level reporting
- consolidated reporting where applicable

Report totals must reconcile to their underlying journal and ledger data.

=== filament rules ===

# Filament

Use the Filament version actually installed in the repository.

Before making version-sensitive Filament changes, consult Boost's version-specific documentation.

Inspect sibling Filament resources before introducing:

- table actions
- bulk actions
- forms
- filters
- notifications
- relation managers
- widgets
- custom pages

Authorization must remain enforced server-side.

For bulk accounting operations such as:

- approval
- posting
- deletion
- reconciliation

the underlying service must validate each selected record.

Do not rely solely on Filament action visibility.

=== testing rules ===

# Testing

Use the testing stack actually installed in the repository.

When working with Pest or testing:

1. Activate `pest-testing`.
2. Search version-specific documentation through Boost.
3. Inspect existing tests for conventions.
4. Prefer feature tests for application behavior.

Use factories where available.

Do not delete tests without explicit approval.

When fixing bugs, add or update regression tests whenever practical.

Run the narrowest relevant tests first.

Example:

```bash
php artisan test --compact --filter=RelevantTest
```

Then run broader relevant suites when appropriate.

Tests should verify behavior rather than implementation details.

=== pint rules ===

# Laravel Pint

Before finalizing PHP changes, run Pint on changed files.

Use the project's configured Pint command.

Prefer:

```bash
vendor/bin/pint --dirty
```

If the installed project configuration supports a specific agent formatting mode, follow that repository convention.

Avoid unrelated repository-wide formatting changes.

=== tailwind rules ===

# Tailwind CSS

When working on Tailwind or UI styling:

1. Activate `tailwindcss-development`.
2. Search version-specific documentation through Boost.
3. Follow existing project Tailwind conventions.
4. Reuse existing components and styling patterns.

Do not assume syntax from another Tailwind major version.

</laravel-boost-guidelines>

---

# Codebase Topology & Graphify Context

This repository contains a Graphify code knowledge graph under:

```text
graphify-out/
```

Graphify is used to understand structural relationships across the Aureus ERP codebase.

Laravel Boost and Graphify serve different purposes:

```text
Graphify
    ↓
Repository topology
Cross-file relationships
Dependency tracing
Architectural relationships

Laravel Boost
    ↓
Laravel runtime knowledge
Database/schema inspection
Installed package versions
Version-specific documentation
Application debugging
```

Use both where appropriate.

---

# Graphify

When:

```text
graphify-out/graph.json
```

exists, consult Graphify before answering substantial codebase questions or making architectural/cross-module changes.

Prefer focused Graphify queries over reading the entire graph or manually grepping the repository.

## Codebase Questions

Use:

```bash
graphify query "<question>"
```

for codebase questions.

Examples:

```bash
graphify query "How are bank statement imports connected to transaction mappings?"
```

```bash
graphify query "What services are involved in journal posting?"
```

```bash
graphify query "How do FS Tags affect bank transaction mappings?"
```

## Relationship Tracing

Use:

```bash
graphify path "<A>" "<B>"
```

when determining relationships between components.

Example:

```bash
graphify path "BankStatementImportService" "BankTransactionMapping"
```

## Concept Explanation

Use:

```bash
graphify explain "<concept>"
```

for focused architectural concepts.

Example:

```bash
graphify explain "transaction mapping"
```

## Wiki

If:

```text
graphify-out/wiki/index.md
```

exists, use it for broad repository navigation.

The wiki is not produced by `graphify extract`. Generate it with:

```bash
graphify export wiki
```

## Graph Report

Use:

```text
graphify-out/GRAPH_REPORT.md
```

for:

- broad architecture review
- module topology
- community structure
- cross-module investigation
- cases where query/path/explain do not provide enough context

Do NOT read the entire `GRAPH_REPORT.md` for every small task.

`GRAPH_REPORT.md` is not produced by `graphify extract`. Generate or refresh it with:

```bash
graphify cluster-only . --no-label --no-viz
```

- `--no-label` keeps `Community N` placeholders instead of calling an LLM to name communities, so no API key is needed
- `--no-viz` skips `graph.html` generation, which matters here because this graph exceeds 5000 nodes

---

# `/graphify` Behavior

When the user explicitly types:

```text
/graphify
```

use the installed Graphify skill/instructions before doing anything else.

Establish relevant Graphify context before answering or modifying code.

---

# Dirty Graphify Files

Changes inside:

```text
graphify-out/
```

are expected after Graphify runs.

Dirty Graphify output is NOT a reason to skip Graphify.

Only skip Graphify when:

- the task specifically concerns stale/incorrect Graphify output, or
- the user explicitly says not to use Graphify.

Do not accidentally commit generated Graphify output unless repository policy or the user explicitly requires it.

---

# Graphify Update Policy

The Aureus ERP repository currently uses a code-only Graphify index.

After meaningful source-code changes, update Graphify using:

```bash
graphify update .
```

`update` re-extracts code files using local AST parsing only and requires no LLM API key.

There is no `--code-only` flag on `update`; passing one fails with:

```text
error: unknown update option: --code-only
```

The only flags `update` accepts are:

- `--force` — overwrite `graph.json` even if the rebuild has fewer nodes (use after refactors that deleted code)
- `--no-cluster` — skip clustering and write raw extraction only

To build the index from scratch, or to rebuild after deleting `graphify-out/`:

```bash
graphify extract . --code-only
```

`--code-only` belongs to `extract`, where it indexes code by local AST and skips doc/paper/image files, so no API key is required.

Do NOT use an update command that triggers semantic document/image extraction unless explicitly requested.

After significant architectural changes, re-query Graphify to verify the resulting dependency structure.

---

# Graphify Workflow for Aureus ERP

Before architectural or cross-module changes:

1. Query Graphify.
2. Identify affected modules.
3. Identify affected models.
4. Identify affected services.
5. Identify affected database tables and migrations.
6. Identify affected Filament/Livewire components.
7. Identify affected permissions.
8. Identify affected tests.
9. Trace upstream callers.
10. Trace downstream consumers.
11. Check company isolation.
12. Check accounting consequences.
13. Check reporting consequences.
14. Implement using existing abstractions.
15. Run relevant tests.
16. Run Pint.
17. Update Graphify.
18. Re-query affected components when appropriate.

For accounting work, pay particular attention to:

- accounting
- accounts
- support
- plugin manager
- companies
- permissions
- bank statement imports
- transaction mapping
- FS Tags
- reconciliation
- journal generation
- journal posting
- multi-currency
- financial reporting

---

# Source-of-Truth Priority

When information conflicts, use this priority:

1. Actual current repository source code
2. Actual current database/schema/runtime state
3. `composer.lock` and installed dependency state
4. Version-specific Laravel Boost information/documentation
5. Existing automated tests
6. Focused Graphify `query`, `path`, or `explain` results
7. `graphify-out/GRAPH_REPORT.md`
8. General instructions in this file

Never force the application to conform to an instruction that is demonstrably stale relative to the actual repository.

If Graphify and the current source code disagree:

1. Treat current source code as authoritative.
2. Determine whether Graphify is stale.
3. Update Graphify after the code state is understood.

---

# Change Discipline

Before editing code:

1. Understand the existing workflow.
2. Query Graphify when appropriate.
3. Inspect relevant source files.
4. Inspect sibling implementations.
5. Inspect the database/schema when relevant.
6. Identify downstream effects.
7. Inspect relevant tests.
8. Consult Boost documentation for version-sensitive framework behavior.

During implementation:

- preserve existing architecture
- preserve plugin boundaries
- preserve authorization
- preserve company isolation
- preserve accounting integrity
- preserve auditability
- avoid unrelated refactoring
- reuse existing abstractions
- do not introduce unnecessary dependencies

After implementation:

1. Run relevant tests.
2. Run Pint on changed PHP.
3. Update Graphify when source relationships changed.
4. Re-query affected architecture when appropriate.
5. Inspect the final Git diff.
6. Report failures rather than hiding them.

Never claim functionality was verified unless it was actually verified.

---

# Accounting Change Checklist

For any meaningful accounting change, determine whether it affects:

- Chart of Accounts
- bank statements
- bank statement lines
- transaction mappings
- FS Tags
- counterparties
- cash-flow categories
- tax treatment
- offset GL accounts
- journal generation
- journal posting
- General Ledger
- Trial Balance
- Profit & Loss
- Balance Sheet
- Cash Flow Statement
- reconciliation
- currencies
- exchange rates
- company isolation
- permissions
- auditability

Do not consider an accounting feature complete until its downstream accounting/reporting impact has been evaluated.

---

# Bulk Operations Safety

Bulk operations involving accounting records require the same validation as individual operations.

For operations such as:

- bulk approval
- bulk posting
- bulk deletion
- bulk reconciliation
- bulk journal generation

validate each selected record individually through the appropriate service/domain layer.

A bulk action must not bypass:

- authorization
- company scope
- posting status rules
- review status rules
- reconciliation rules
- accounting integrity checks

If one selected record cannot safely be processed, handle the failure explicitly rather than silently corrupting or skipping accounting state.

---

# Import Deletion Safety

Deleting an imported bank statement or other accounting import must consider all dependent records.

Before implementing or executing import deletion, inspect dependencies including:

```text
Import
  ↓
Bank Statement
  ↓
Bank Statement Lines
  ↓
Transaction Mappings
  ↓
Transfer Matches / Reconciliation
  ↓
Draft or Posted Journals
  ↓
Ledger / Reports
```

Never delete an import in a way that silently removes or corrupts posted accounting history.

If posted downstream records exist, deletion should be blocked or handled through the application's explicit accounting reversal/correction workflow.

---

# Final Verification

For significant changes, verification should include the narrowest relevant combination of:

- automated tests
- database/schema inspection
- application behavior
- accounting reconciliation
- authorization behavior
- multi-company isolation
- final Git diff
- Graphify dependency review

Do not substitute manual assumptions for automated tests where appropriate tests can be written.

---

# Response Style

Keep responses concise.

When reporting completed development work, clearly distinguish:

- changed
- verified
- failed
- remaining work

Do not generate unnecessary documentation, changelogs, architecture reports, verification reports, or other files unless explicitly requested.
