# Tech Stack

**Status:** Verified against `composer.json` & `package.json`  
**Parent Note:** [[Project Overview]]  

---

## 1. Core Runtime & Framework

| Technology | Verified Version | Purpose |
|---|---|---|
| **PHP** | `^8.3.0` | Core language runtime (using constructor promotion, typed properties, match statements) |
| **Laravel Framework** | `13.8.0` (`^13.0`) | Web application framework, Eloquent ORM, service container, job queues |
| **Filament** | `^5.0` (`filament/filament`) | Admin panel UI, Resource scaffolding, Forms, Tables, Infolists, Action modals |
| **Livewire** | `^4.0` / `^3.5` | Dynamic reactive components powering Filament forms and table tables |
| **Tailwind CSS** | Configured in Vite | Modern responsive utility-first CSS styling |
| **MySQL** | `8.4` (Strict Mode) | Relational storage engine with foreign keys, InnoDB, `company_id` partitioning |

---

## 2. Key Composer Packages

Verified from [composer.json](file:///c:/Intern/Handover/Erp/composer.json) and [composer.lock](file:///c:/Intern/Handover/Erp/composer.lock):

- **Modular Monorepo:**
  - `wikimedia/composer-merge-plugin`: Merges `plugins/webkul/*/composer.json` into the root application namespace.
- **Security & Authorization:**
  - `spatie/laravel-permission` (`^6.0`): Core Role-Based Access Control (RBAC).
  - `bezhansalleh/filament-shield` (`^4.0`): Automated permission generator and Filament UI gating.
- **Document & Office Generation:**
  - `barryvdh/laravel-dompdf` (`^3.1`): Server-side PDF generation for invoices, bills, and payment vouchers.
  - `maatwebsite/excel` (`^3.1`): Multi-sheet Excel workbook parsing and exports.
- **Cloud & External Services:**
  - `google/apiclient` (`^2.18`): Google Drive API v3 client for automated two-way PDF sync.
- **Math & Decimals:**
  - `brick/math`: Immutable arbitrary-precision decimal operations (`BigDecimal`) for currency exchange and tax calculations.
- **Auditing & Activity Tracking:**
  - `spatie/laravel-activitylog` (`^4.9`): Audit trail powering the chatter feed and change logs.

---

## 3. Testing, Tooling & Quality Assurance

- **Pest PHP** (`^4.4`): Feature and unit testing runner with dataset testing.
- **Laravel Pint** (`^1.27`): Opinionated code formatting and linting.
- **Laravel Boost** (`^2.1`): Framework runtime introspection and MCP tooling.
- **Vite** (`^6.0`): Fast asset compilation and HMR for frontend resources.

---

## 4. Hardware & Mobile Integration

- **NativePHP Mobile** (`^3.3`): Mobile client runtime integration in `AppServiceProvider`.
- **WebRTC Data Channels:** P2P secure direct document synchronization between paired ERP instances.
