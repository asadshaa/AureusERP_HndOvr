# Laravel Architecture

**Status:** Implemented & Verified  
**Parent Note:** [[System Architecture]]  

---

## 1. Application Bootstrap & Service Providers

The application bootstraps through Laravel 13 standards with modular extensions:

```text
bootstrap/app.php
    ├── Middleware Registration (Web, API, Company Scope, SetLocale)
    └── Exception Handling
           ▼
app/Providers/
    ├── AppServiceProvider (Core event listeners, NativePHP bindings)
    └── Filament/AdminPanelProvider (Filament panel configuration & plugins)
           ▼
plugins/webkul/{plugin}/src/*ServiceProvider.php
    └── Registers migrations, views, translations, commands, and routes
```

### Monorepo Merge-Plugin
Package discovery is coordinated by `wikimedia/composer-merge-plugin` defined in root `composer.json`:
- Merges `plugins/webkul/*/composer.json`
- Each plugin maintains its own PSR-4 namespace (e.g. `Webkul\Account\` in `plugins/webkul/accounts/src/`)
- Database migrations are loaded using `$this->loadMigrationsFrom(...)` in each plugin's service provider.

---

## 2. Configuration & Environment Discipline

1. **No `env()` in Application Code:**
   In accordance with Laravel best practices, `env()` is never called in controllers, services, or models. It is only called in `config/*.php`. Code accesses values via `config('key')`.
2. **Dedicated Config Files:**
   - `config/accounting_drive.php`: Google Drive API credentials, default upload folders, and MIME constraints.
   - `config/webrtc.php`: Signaling servers, STUN endpoints, and handshake timeouts.
   - `config/accounting_peers.php`: Known instance endpoints for direct ledger exchanges.

---

## 3. Database Layer & Migrations

- **Strict Schema Discipline:** All migrations use forward-only additive schema changes guarded by `Schema::hasTable` and `Schema::hasColumn`.
- **Soft Deletes:** Key business models (`Company`, `Employee`, `Partner`, `Account`) use `Illuminate\Database\Eloquent\SoftDeletes` to preserve foreign key integrity and historical audit trails.
- **Transactions:** High-stakes accounting operations (e.g. `BankJournalService::postBatch()` or `EmployeeRequestService::synchronize()`) wrap multi-table writes in `DB::transaction()` to ensure atomicity.

---

## 4. Source References

```text
bootstrap/app.php
app/Providers/AppServiceProvider.php
app/Providers/Filament/AdminPanelProvider.php
composer.json
routes/console.php
```
