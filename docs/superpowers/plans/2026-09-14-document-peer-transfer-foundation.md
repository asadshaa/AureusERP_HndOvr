# Document Peer Transfer — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user send an existing accounting document to another user's registered device, delivered through the server, fully permission-checked and audited.

**Architecture:** A `user_devices` registry in the security plugin and an `accounting_document_transfers` record in the accounting plugin. `DocumentTransferService` wraps the existing `DocumentService` — it never touches storage or bytes directly. Delivery in this plan is server-path only; the WebRTC peer transport is a separate later plan that swaps the delivery mechanism while reusing every table, permission and audit action built here.

**Tech Stack:** Laravel 13.8, PHP 8.3, Filament 5.6, Livewire 4.3, Pest, MySQL 8.4, Sanctum 4.3.

**Spec:** This plan implements the "Hybrid, phase 2" scope from the P2P connectivity research report (published artifact `b133d5ad-591a-41ca-a523-4e95e3596815`), narrowed by these decisions taken with the user:

- Peers are on **different networks** (not same LAN)
- **Browser only** — no software may be installed on participating machines, which rules out Tailscale and native agents
- Payload is **normal documents under 20 MB**

The research report's conclusion stands: at 20 MB with a 60–70% corporate TURN-relay rate, peer transport saves roughly a third of the bandwidth on files too small for anyone to notice. This plan therefore builds only the part that is valuable under every transport — and is a hard prerequisite for the peer transport if it is ever built.

---

## Global Constraints

- **MySQL stays authoritative.** Nothing in this plan writes ledger data, approvals, or postings.
- **Never write document bytes outside `DocumentStorageProvider`.** All byte access goes through `DocumentService`; this plan adds no new storage path.
- **Company isolation is derived from the authenticated user, never from client input.** Use `$user->default_company_id`. Do NOT accept `company_id` as a request parameter — the existing REST controllers do this and it is a known defect, not a pattern to copy.
- **Every state change writes to `accounting_document_audits`** via the existing audit mechanism, including denied attempts.
- **New migrations must be added to the plugin's `hasMigrations([...])` array** in its service provider, or they will not run. The file existing on disk is not enough.
- **Run Pint on changed PHP:** `./vendor/bin/pint --dirty`
- **Document size cap is 20 MB** (`DocumentService::MAX_FILE_SIZE_BYTES`). This plan does not change it.
- **Test command:** `./vendor/bin/pest <path>` — the accounting suite is `AccountingFeature`.
- **KNOWN TEST BLOCKER:** `TestBootstrapHelper::ensurePluginInstalled('accounts')` crashes the whole test file, because `AccountSeeder::run()` at `plugins/webkul/accounts/database/seeders/AccountSeeder.php:28` does `$company = Company::first();` with no null guard and `aureuserp_testing.companies` has no permanent rows. **Do not use `TestBootstrapHelper` in Tasks 1–7.** Follow the `DocumentServiceTest.php` pattern instead: `require_once` only `DocumentTestHelper.php` and build fixtures with `documentTestUser()`. Task 8 needs Filament panel bootstrapping and therefore does hit this blocker — see that task.

## Open Decision — resolve before Task 4

The only justification for peer transport that survives the constraints above is **confidentiality**: WebRTC's DTLS is mandatory, so even a TURN relay sees ciphertext only, which presigned S3 cannot match.

If confidentiality is the actual driver, then **server-path delivery in Tasks 4–5 defeats the purpose**, because the server holds plaintext. In that case the fallback must become "transfer unavailable, both devices must be online" rather than a server relay, and Task 5 changes shape.

If the driver is convenience or bandwidth, this plan is correct as written. **Confirm with the user before starting Task 4.** Tasks 1–3 are safe under either answer.

---

### Task 1: Device registry table and model

**Files:**
- Create: `plugins/webkul/security/database/migrations/2026_09_15_000001_create_user_devices_table.php`
- Modify: `plugins/webkul/security/src/SecurityServiceProvider.php` (add to `hasMigrations` array, after line 36)
- Create: `plugins/webkul/security/src/Models/UserDevice.php`
- Test: `plugins/webkul/accounting/tests/Feature/P2P/UserDeviceTest.php`

**Interfaces:**
- Consumes: nothing (first task)
- Produces: `Webkul\Security\Models\UserDevice` with `$guarded = []`, casts `last_seen_at`/`revoked_at` to `datetime`, relations `user()` and `company()`, scopes `scopeActive(Builder $q): Builder` and `scopeForUser(Builder $q, int $userId): Builder`, and method `isActive(): bool`.

- [ ] **Step 1: Write the failing test**

Create `plugins/webkul/accounting/tests/Feature/P2P/UserDeviceTest.php`:

```php
<?php

use Webkul\Security\Models\UserDevice;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

it('creates an active device for a user and reports it as active', function () {
    $user = documentTestUser();

    $device = UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => "Asad's MacBook",
        'platform'               => 'macos',
        'public_key'             => 'BASE64-SPKI-PLACEHOLDER',
        'public_key_fingerprint' => str_repeat('a', 64),
    ]);

    expect($device->exists)->toBeTrue()
        ->and($device->isActive())->toBeTrue()
        ->and($device->revoked_at)->toBeNull()
        ->and($device->user->id)->toBe($user->id)
        ->and($device->company->id)->toBe($user->default_company_id);
});

it('reports a revoked device as inactive and excludes it from the active scope', function () {
    $user = documentTestUser();

    $live = UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'Live device',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => str_repeat('b', 64),
    ]);

    $revoked = UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'Revoked device',
        'public_key'             => 'KEY-2',
        'public_key_fingerprint' => str_repeat('c', 64),
        'revoked_at'             => now(),
    ]);

    expect($revoked->isActive())->toBeFalse();

    $activeIds = UserDevice::query()->active()->forUser($user->id)->pluck('id')->all();

    expect($activeIds)->toContain($live->id)
        ->and($activeIds)->not->toContain($revoked->id);
});

it('rejects a duplicate public key fingerprint', function () {
    $user = documentTestUser();
    $fingerprint = str_repeat('d', 64);

    UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'First',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => $fingerprint,
    ]);

    expect(fn () => UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'Second',
        'public_key'             => 'KEY-2',
        'public_key_fingerprint' => $fingerprint,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/UserDeviceTest.php`
Expected: FAIL with `Class "Webkul\Security\Models\UserDevice" not found`

- [ ] **Step 3: Write the migration**

Create `plugins/webkul/security/database/migrations/2026_09_15_000001_create_user_devices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A device a user has registered so documents can be sent to it.
     *
     * The private key never leaves the device; only the SPKI public key
     * and its SHA-256 fingerprint are stored here. The fingerprint is
     * unique so the same keypair cannot be registered twice, which is
     * what makes it usable as a stable device identity later when the
     * peer transport needs to bind a transfer to a specific endpoint.
     *
     * Revocation is a timestamp rather than a delete, so a revoked
     * device still resolves for audit rows that reference it.
     */
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('label');
            $table->string('platform')->nullable();
            $table->text('public_key');
            $table->string('public_key_fingerprint', 64)->unique();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
```

- [ ] **Step 4: Register the migration**

In `plugins/webkul/security/src/SecurityServiceProvider.php`, add this line as the last entry of the `hasMigrations([...])` array (immediately after `'2026_01_23_074142_add_multi_factor_auth_columns_in_users_table',`):

```php
                '2026_09_15_000001_create_user_devices_table',
```

- [ ] **Step 5: Write the model**

Create `plugins/webkul/security/src/Models/UserDevice.php`:

```php
<?php

namespace Webkul\Security\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Models\Company;

class UserDevice extends Model
{
    protected $table = 'user_devices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/UserDeviceTest.php`
Expected: PASS, 3 tests

- [ ] **Step 7: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/security/database/migrations/2026_09_15_000001_create_user_devices_table.php plugins/webkul/security/src/Models/UserDevice.php plugins/webkul/security/src/SecurityServiceProvider.php plugins/webkul/accounting/tests/Feature/P2P/UserDeviceTest.php
git commit -m "feat(security): add user device registry for document transfer targets"
```

---

### Task 2: Device registration service

**Files:**
- Create: `plugins/webkul/security/src/Services/DeviceRegistrationService.php`
- Test: `plugins/webkul/accounting/tests/Feature/P2P/DeviceRegistrationServiceTest.php`

**Interfaces:**
- Consumes: `Webkul\Security\Models\UserDevice` from Task 1
- Produces: `Webkul\Security\Services\DeviceRegistrationService` with:
  - `register(User $user, string $label, string $publicKey, ?string $platform = null): UserDevice` — also stamps `last_seen_at`, since registering IS the device checking in
  - `revoke(User $actor, UserDevice $device): UserDevice` — throws `RuntimeException` if the actor does not own the device
  - `activeFor(User $user): Collection`

- [ ] **Step 1: Write the failing test**

Create `plugins/webkul/accounting/tests/Feature/P2P/DeviceRegistrationServiceTest.php`:

```php
<?php

use Webkul\Security\Models\UserDevice;
use Webkul\Security\Services\DeviceRegistrationService;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    $this->service = app(DeviceRegistrationService::class);
});

it('registers a device, deriving the fingerprint from the public key', function () {
    $user = documentTestUser();

    $device = $this->service->register($user, 'Work laptop', 'SPKI-PUBLIC-KEY-BYTES', 'windows');

    expect($device->user_id)->toBe($user->id)
        ->and($device->company_id)->toBe($user->default_company_id)
        ->and($device->label)->toBe('Work laptop')
        ->and($device->platform)->toBe('windows')
        ->and($device->public_key_fingerprint)->toBe(hash('sha256', 'SPKI-PUBLIC-KEY-BYTES'))
        ->and($device->last_seen_at)->not->toBeNull()
        ->and($device->isActive())->toBeTrue();
});

it('returns the existing device when the same public key is registered again', function () {
    $user = documentTestUser();

    $first  = $this->service->register($user, 'Original label', 'SAME-KEY');
    $second = $this->service->register($user, 'Renamed', 'SAME-KEY');

    expect($second->id)->toBe($first->id)
        ->and($second->label)->toBe('Renamed')
        ->and(UserDevice::query()->count())->toBe(1);
});

it('revokes a device the actor owns', function () {
    $user   = documentTestUser();
    $device = $this->service->register($user, 'Laptop', 'KEY');

    $revoked = $this->service->revoke($user, $device);

    expect($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->isActive())->toBeFalse();
});

it('refuses to revoke a device belonging to another user', function () {
    $owner    = documentTestUser();
    $outsider = documentTestUser();
    $device   = $this->service->register($owner, 'Laptop', 'KEY');

    expect(fn () => $this->service->revoke($outsider, $device))
        ->toThrow(RuntimeException::class, 'You cannot revoke a device you do not own.');

    expect($device->fresh()->isActive())->toBeTrue();
});

it('lists only active devices for the user', function () {
    $user = documentTestUser();

    $live    = $this->service->register($user, 'Live', 'KEY-1');
    $revoked = $this->service->register($user, 'Dead', 'KEY-2');
    $this->service->revoke($user, $revoked);

    $ids = $this->service->activeFor($user)->pluck('id')->all();

    expect($ids)->toBe([$live->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DeviceRegistrationServiceTest.php`
Expected: FAIL with `Target class [Webkul\Security\Services\DeviceRegistrationService] does not exist`

- [ ] **Step 3: Write the service**

Create `plugins/webkul/security/src/Services/DeviceRegistrationService.php`:

```php
<?php

namespace Webkul\Security\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Webkul\Security\Models\User;
use Webkul\Security\Models\UserDevice;

/**
 * Owns the lifecycle of a registered device. Kept deliberately free of
 * any transfer or document concern: this is identity only, so HR or any
 * other plugin can reuse it without depending on accounting.
 *
 * The fingerprint is a SHA-256 of the SPKI public key bytes. It is
 * derived here rather than accepted from the client, so a caller cannot
 * claim a fingerprint that does not match the key it presented.
 */
class DeviceRegistrationService
{
    public function register(User $user, string $label, string $publicKey, ?string $platform = null): UserDevice
    {
        $fingerprint = hash('sha256', $publicKey);

        $device = UserDevice::query()
            ->where('public_key_fingerprint', $fingerprint)
            ->first();

        if ($device !== null) {
            if ($device->user_id !== $user->id) {
                throw new RuntimeException('This device key is already registered to another user.');
            }

            $device->update(['label' => $label, 'platform' => $platform, 'last_seen_at' => now()]);

            return $device->refresh();
        }

        return UserDevice::query()->create([
            'user_id'                => $user->id,
            'company_id'             => $user->default_company_id,
            'label'                  => $label,
            'platform'               => $platform,
            'public_key'             => $publicKey,
            'public_key_fingerprint' => $fingerprint,
            'last_seen_at'           => now(),
        ]);
    }

    public function revoke(User $actor, UserDevice $device): UserDevice
    {
        if ($device->user_id !== $actor->id) {
            throw new RuntimeException('You cannot revoke a device you do not own.');
        }

        $device->update(['revoked_at' => now()]);

        return $device->refresh();
    }

    /**
     * @return Collection<int, UserDevice>
     */
    public function activeFor(User $user): Collection
    {
        return UserDevice::query()
            ->active()
            ->forUser($user->id)
            ->orderBy('id')
            ->get();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DeviceRegistrationServiceTest.php`
Expected: PASS, 5 tests

- [ ] **Step 5: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/security/src/Services/DeviceRegistrationService.php plugins/webkul/accounting/tests/Feature/P2P/DeviceRegistrationServiceTest.php
git commit -m "feat(security): add device registration service with ownership-checked revocation"
```

---

### Task 3: Transfer table, model, audit actions and permission

**Files:**
- Create: `plugins/webkul/accounting/database/migrations/2026_09_15_000002_create_accounting_document_transfers_table.php`
- Modify: `plugins/webkul/accounting/src/AccountingServiceProvider.php` (add to `hasMigrations` array)
- Create: `plugins/webkul/accounting/src/Models/DocumentTransfer.php`
- Create: `plugins/webkul/accounting/src/Enums/DocumentTransferStatus.php`
- Modify: `plugins/webkul/accounting/src/Enums/DocumentAuditAction.php`
- Modify: `plugins/webkul/accounting/resources/lang/en/enums/document-audit-action.php`
- Modify: `plugins/webkul/accounting/src/Support/AccountingPermissions.php`
- Create: `config/accounting_transfer.php`
- Test: `plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferModelTest.php`

**Interfaces:**
- Consumes: `UserDevice` from Task 1
- Produces:
  - `Webkul\Accounting\Enums\DocumentTransferStatus` — cases `Pending = 'pending'`, `Delivered = 'delivered'`, `Expired = 'expired'`, `Cancelled = 'cancelled'`
  - `Webkul\Accounting\Models\DocumentTransfer` — relations `document()`, `sender()`, `recipient()`, `recipientDevice()`, `company()`; scopes `scopePending(Builder $q): Builder`, `scopeForRecipient(Builder $q, int $userId): Builder`; methods `isPending(): bool`, `hasExpired(): bool`
  - `AccountingPermissions::TransferDocuments = 'accounting_transfer_documents'`
  - Audit cases `DocumentAuditAction::TransferSent = 'transfer_sent'`, `TransferDelivered = 'transfer_delivered'`, `TransferCancelled = 'transfer_cancelled'`

- [ ] **Step 1: Write the failing test**

Create `plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferModelTest.php`:

```php
<?php

use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentTransfer;
use Webkul\Security\Models\UserDevice;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

function transferFixture(array $overrides = []): DocumentTransfer
{
    $sender    = documentTestUser();
    $recipient = documentTestUser();

    $document = Document::factory()->create(['company_id' => $sender->default_company_id]);

    $device = UserDevice::query()->create([
        'user_id'                => $recipient->id,
        'company_id'             => $recipient->default_company_id,
        'label'                  => 'Recipient device',
        'public_key'             => 'KEY-'.bin2hex(random_bytes(4)),
        'public_key_fingerprint' => hash('sha256', bin2hex(random_bytes(8))),
    ]);

    return DocumentTransfer::query()->create(array_merge([
        'company_id'          => $sender->default_company_id,
        'document_id'         => $document->id,
        'sender_id'           => $sender->id,
        'recipient_id'        => $recipient->id,
        'recipient_device_id' => $device->id,
        'status'              => DocumentTransferStatus::Pending,
        'expires_at'          => now()->addDays(7),
    ], $overrides));
}

it('creates a pending transfer with its relations resolvable', function () {
    $transfer = transferFixture();

    expect($transfer->status)->toBe(DocumentTransferStatus::Pending)
        ->and($transfer->isPending())->toBeTrue()
        ->and($transfer->hasExpired())->toBeFalse()
        ->and($transfer->document)->not->toBeNull()
        ->and($transfer->sender)->not->toBeNull()
        ->and($transfer->recipient)->not->toBeNull()
        ->and($transfer->recipientDevice)->not->toBeNull();
});

it('reports a past expiry as expired', function () {
    $transfer = transferFixture(['expires_at' => now()->subMinute()]);

    expect($transfer->hasExpired())->toBeTrue();
});

it('scopes pending transfers to the recipient', function () {
    $mine = transferFixture();
    transferFixture();

    $ids = DocumentTransfer::query()
        ->pending()
        ->forRecipient($mine->recipient_id)
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$mine->id]);
});

it('excludes delivered transfers from the pending scope', function () {
    $transfer = transferFixture(['status' => DocumentTransferStatus::Delivered]);

    expect(DocumentTransfer::query()->pending()->forRecipient($transfer->recipient_id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferModelTest.php`
Expected: FAIL with `Class "Webkul\Accounting\Enums\DocumentTransferStatus" not found`

- [ ] **Step 3: Write the status enum**

Create `plugins/webkul/accounting/src/Enums/DocumentTransferStatus.php`:

```php
<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentTransferStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    case Delivered = 'delivered';

    case Expired = 'expired';

    case Cancelled = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Delivered => 'Delivered',
            self::Expired   => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Pending   => 'warning',
            self::Delivered => 'success',
            self::Expired   => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
```

- [ ] **Step 4: Write the migration**

Create `plugins/webkul/accounting/database/migrations/2026_09_15_000002_create_accounting_document_transfers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One record per "send this document to that device" act.
     *
     * This is the authorization record, not a copy of the file: bytes
     * always stay under DocumentStorageProvider and are served through
     * DocumentService. The row exists so the server -- not either peer --
     * decides who may receive what, and so the act is auditable whether
     * delivery happens through the server or, later, directly between
     * two browsers.
     *
     * expected_sha256 is denormalised from the document version at send
     * time on purpose: it is what the recipient must verify against, and
     * it must not silently change if a new version is added mid-flight.
     *
     * The later peer-transport phase adds expected_fingerprint and a
     * server signature to this table; nothing here needs to change for
     * that.
     */
    public function up(): void
    {
        Schema::create('accounting_document_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('accounting_documents')->cascadeOnDelete();

            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_device_id')->nullable()->constrained('user_devices')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->string('expected_sha256', 64)->nullable();
            $table->text('note')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['recipient_id', 'status']);
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_document_transfers');
    }
};
```

- [ ] **Step 5: Register the migration**

In `plugins/webkul/accounting/src/AccountingServiceProvider.php`, add as the last entry of the `hasMigrations([...])` array (immediately after `'2026_09_11_000001_create_accounting_document_drive_syncs_table',`):

```php
                '2026_09_15_000002_create_accounting_document_transfers_table',
```

- [ ] **Step 6: Write the model**

Create `plugins/webkul/accounting/src/Models/DocumentTransfer.php`:

```php
<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Security\Models\User;
use Webkul\Security\Models\UserDevice;
use Webkul\Support\Models\Company;

class DocumentTransfer extends Model
{
    protected $table = 'accounting_document_transfers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'       => DocumentTransferStatus::class,
            'expires_at'   => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function recipientDevice(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'recipient_device_id');
    }

    public function isPending(): bool
    {
        return $this->status === DocumentTransferStatus::Pending;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentTransferStatus::Pending);
    }

    public function scopeForRecipient(Builder $query, int $userId): Builder
    {
        return $query->where('recipient_id', $userId);
    }
}
```

- [ ] **Step 7: Add the audit actions**

In `plugins/webkul/accounting/src/Enums/DocumentAuditAction.php`, add these three cases immediately after `case DriveSyncFailed = 'drive_sync_failed';`:

```php
    // Device-to-device document transfer. Delivery is server-path in this
    // phase; these same cases cover a direct peer transport later, with
    // the path recorded in the audit row's metadata rather than as a
    // separate action.
    case TransferSent = 'transfer_sent';

    case TransferDelivered = 'transfer_delivered';

    case TransferCancelled = 'transfer_cancelled';
```

And add these three arms to the `getLabel()` match, immediately after the `self::DriveSyncFailed` arm:

```php
            self::TransferSent      => __('accounting::enums/document-audit-action.transfer-sent'),
            self::TransferDelivered => __('accounting::enums/document-audit-action.transfer-delivered'),
            self::TransferCancelled => __('accounting::enums/document-audit-action.transfer-cancelled'),
```

- [ ] **Step 8: Add the translations**

In `plugins/webkul/accounting/resources/lang/en/enums/document-audit-action.php`, add these three entries to the returned array:

```php
    'transfer-sent'      => 'Sent to device',
    'transfer-delivered' => 'Delivered to device',
    'transfer-cancelled' => 'Transfer cancelled',
```

- [ ] **Step 9: Add the permission**

In `plugins/webkul/accounting/src/Support/AccountingPermissions.php`, add this constant after the `DeleteDocuments` constant:

```php
    /**
     * Sending a document to another user's device is a distinct act from
     * downloading it yourself: it moves a copy to a machine the sender
     * does not control. Read-only oversight roles (Internal Auditor, VP
     * Finance, FP&A) deliberately do NOT get this -- their permission
     * bundles are explicit allowlists, so they are excluded by omission.
     */
    public const TransferDocuments = 'accounting_transfer_documents';
```

And add it to the `all()` array, immediately after `self::DeleteDocuments,`:

```php
            self::TransferDocuments,
```

Note: `accountant()` and `controller()` derive from `all()` minus an exclusion list, so they gain this automatically. `internalAuditor()`, `vpFinance()` and `fpaAnalyst()` are explicit allowlists and correctly will not.

- [ ] **Step 10: Add the config file**

Create `config/accounting_transfer.php`:

```php
<?php

return [
    // How long a pending transfer stays claimable before it expires.
    'expiry_hours' => (int) env('ACCOUNTING_TRANSFER_EXPIRY_HOURS', 168),

    // Cap on simultaneously pending outbound transfers per sender, to
    // bound bulk-exfiltration attempts through this feature.
    'max_pending_per_sender' => (int) env('ACCOUNTING_TRANSFER_MAX_PENDING', 25),
];
```

- [ ] **Step 11: Run test to verify it passes**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferModelTest.php`
Expected: PASS, 4 tests

- [ ] **Step 12: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/accounting/database/migrations/2026_09_15_000002_create_accounting_document_transfers_table.php plugins/webkul/accounting/src/Models/DocumentTransfer.php plugins/webkul/accounting/src/Enums/DocumentTransferStatus.php plugins/webkul/accounting/src/Enums/DocumentAuditAction.php plugins/webkul/accounting/resources/lang/en/enums/document-audit-action.php plugins/webkul/accounting/src/Support/AccountingPermissions.php plugins/webkul/accounting/src/AccountingServiceProvider.php config/accounting_transfer.php plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferModelTest.php
git commit -m "feat(accounting): add document transfer record, status enum, audit actions and permission"
```

---

### Task 4: Sending a transfer

**RESOLVE THE OPEN DECISION ABOVE BEFORE STARTING THIS TASK.**

**Files:**
- Create: `plugins/webkul/accounting/src/Services/DocumentTransferService.php`
- Test: `plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferSendTest.php`

**Interfaces:**
- Consumes: `DocumentTransfer`, `DocumentTransferStatus`, `DocumentAuditAction::TransferSent`, `AccountingPermissions::TransferDocuments` from Task 3; `UserDevice` from Task 1; the existing `DocumentService::find(User $user, int $id, ?string $ipAddress): Document`
- Produces: `Webkul\Accounting\Services\DocumentTransferService::send(User $sender, int $documentId, UserDevice $device, ?string $note = null, ?string $ipAddress = null): DocumentTransfer`

- [ ] **Step 1: Write the failing test**

Create `plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferSendTest.php`:

```php
<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\DocumentTransferService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\UserDevice;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->transfers = app(DocumentTransferService::class);
    $this->documents = app(DocumentService::class);
});

function deviceFor($user, string $label = 'Device'): UserDevice
{
    return UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => $label,
        'public_key'             => 'KEY-'.bin2hex(random_bytes(4)),
        'public_key_fingerprint' => hash('sha256', bin2hex(random_bytes(8))),
    ]);
}

function uploadedDocumentFor($service, $user)
{
    return $service->upload(
        $user,
        $user->default_company_id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );
}

it('creates a pending transfer, pins the checksum, and audits the send', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);
    $device    = deviceFor($recipient);

    $document = uploadedDocumentFor($this->documents, $sender);

    $transfer = $this->transfers->send($sender, $document->id, $device, 'For your review', '10.0.0.9');

    expect($transfer->status)->toBe(DocumentTransferStatus::Pending)
        ->and($transfer->company_id)->toBe($company->id)
        ->and($transfer->sender_id)->toBe($sender->id)
        ->and($transfer->recipient_id)->toBe($recipient->id)
        ->and($transfer->recipient_device_id)->toBe($device->id)
        ->and($transfer->note)->toBe('For your review')
        ->and($transfer->expected_sha256)->toBe($document->currentVersion->checksum_sha256)
        ->and($transfer->expires_at)->not->toBeNull();

    $audit = $document->audits()->where('action', DocumentAuditAction::TransferSent)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($sender->id)
        ->and($audit->ip_address)->toBe('10.0.0.9')
        ->and($audit->metadata['recipient_id'])->toBe($recipient->id)
        ->and($audit->metadata['device_id'])->toBe($device->id);
});

it('refuses to send without the transfer permission', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);

    $document = uploadedDocumentFor($this->documents, $sender);

    expect(fn () => $this->transfers->send($sender, $document->id, deviceFor($recipient)))
        ->toThrow(RuntimeException::class);
});

it('refuses to send to a device in another company', function () {
    $sender = documentTestUser(null, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $outsider = documentTestUser();

    $document = uploadedDocumentFor($this->documents, $sender);

    expect(fn () => $this->transfers->send($sender, $document->id, deviceFor($outsider)))
        ->toThrow(RuntimeException::class, 'That device belongs to a different company.');
});

it('refuses to send to a revoked device', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);

    $device = deviceFor($recipient);
    $device->update(['revoked_at' => now()]);

    $document = uploadedDocumentFor($this->documents, $sender);

    expect(fn () => $this->transfers->send($sender, $document->id, $device))
        ->toThrow(RuntimeException::class, 'That device has been revoked.');
});

it('refuses to send once the pending cap is reached', function () {
    config()->set('accounting_transfer.max_pending_per_sender', 1);

    $company = Company::factory()->create(['is_active' => true]);

    $sender = documentTestUser($company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);
    $recipient = documentTestUser($company);
    $device    = deviceFor($recipient);

    $this->transfers->send($sender, uploadedDocumentFor($this->documents, $sender)->id, $device);

    expect(fn () => $this->transfers->send($sender, uploadedDocumentFor($this->documents, $sender)->id, $device))
        ->toThrow(RuntimeException::class, 'You have too many pending transfers.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferSendTest.php`
Expected: FAIL with `Target class [Webkul\Accounting\Services\DocumentTransferService] does not exist`

- [ ] **Step 3: Write the service**

Create `plugins/webkul/accounting/src/Services/DocumentTransferService.php`:

```php
<?php

namespace Webkul\Accounting\Services;

use RuntimeException;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\DocumentTransfer;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Models\UserDevice;

/**
 * Sends an existing document to another user's registered device.
 *
 * Deliberately a thin layer over DocumentService: it never opens the
 * storage disk, never reads bytes, and never bypasses a permission
 * check. Its only job is to decide -- server-side -- whether this sender
 * may put this document on that device, and to leave an audit trail of
 * the decision.
 *
 * Company scope always comes from the authenticated user's
 * default_company_id, never from caller input.
 */
class DocumentTransferService
{
    public function __construct(private readonly DocumentService $documents) {}

    public function send(
        User $sender,
        int $documentId,
        UserDevice $device,
        ?string $note = null,
        ?string $ipAddress = null,
    ): DocumentTransfer {
        if (! $sender->can(AccountingPermissions::TransferDocuments)) {
            throw new RuntimeException('You do not have permission to send documents to a device.');
        }

        // Resolves within the sender's own company and throws otherwise,
        // so cross-company sends fail before anything else happens.
        $document = $this->documents->find($sender, $documentId, $ipAddress);

        if ($device->company_id !== $sender->default_company_id) {
            throw new RuntimeException('That device belongs to a different company.');
        }

        if (! $device->isActive()) {
            throw new RuntimeException('That device has been revoked.');
        }

        $pending = DocumentTransfer::query()
            ->pending()
            ->where('sender_id', $sender->id)
            ->count();

        if ($pending >= (int) config('accounting_transfer.max_pending_per_sender')) {
            throw new RuntimeException('You have too many pending transfers.');
        }

        $transfer = DocumentTransfer::query()->create([
            'company_id'          => $sender->default_company_id,
            'document_id'         => $document->id,
            'sender_id'           => $sender->id,
            'recipient_id'        => $device->user_id,
            'recipient_device_id' => $device->id,
            'status'              => DocumentTransferStatus::Pending,
            'expected_sha256'     => $document->currentVersion?->checksum_sha256,
            'note'                => $note,
            'expires_at'          => now()->addHours((int) config('accounting_transfer.expiry_hours')),
        ]);

        $document->audits()->create([
            'company_id' => $document->company_id,
            'actor_id'   => $sender->id,
            'action'     => DocumentAuditAction::TransferSent,
            'ip_address' => $ipAddress,
            'metadata'   => [
                'transfer_id'  => $transfer->id,
                'recipient_id' => $device->user_id,
                'device_id'    => $device->id,
                'device_label' => $device->label,
            ],
        ]);

        return $transfer;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferSendTest.php`
Expected: PASS, 5 tests

- [ ] **Step 5: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/accounting/src/Services/DocumentTransferService.php plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferSendTest.php
git commit -m "feat(accounting): send a document to a registered device, permission-checked and audited"
```

---

### Task 5: Claiming a transfer

**Files:**
- Modify: `plugins/webkul/accounting/src/Services/DocumentTransferService.php`
- Test: `plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferClaimTest.php`

**Interfaces:**
- Consumes: `DocumentTransferService::send()` from Task 4; the existing `DocumentService::retrieveContents(User $user, int $documentId, ?string $ipAddress): array` which returns `['contents' => string, 'version' => DocumentVersion]`
- Produces: three new methods on `DocumentTransferService`:
  - `pendingFor(User $user): Collection`
  - `claim(User $recipient, DocumentTransfer $transfer, ?string $ipAddress = null): array` — returns `['contents' => string, 'version' => DocumentVersion, 'transfer' => DocumentTransfer]`
  - `cancel(User $actor, DocumentTransfer $transfer, ?string $ipAddress = null): DocumentTransfer`

- [ ] **Step 1: Write the failing test**

Create `plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferClaimTest.php`:

```php
<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\DocumentTransferService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\UserDevice;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->transfers = app(DocumentTransferService::class);
    $this->documents = app(DocumentService::class);

    $this->company = Company::factory()->create(['is_active' => true]);

    $this->sender = documentTestUser($this->company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);

    $this->recipient = documentTestUser($this->company, [
        AccountingPermissions::ViewDocuments,
        AccountingPermissions::DownloadDocuments,
    ]);

    $this->device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => hash('sha256', 'KEY-1'),
    ]);

    $this->document = $this->documents->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $this->transfer = $this->transfers->send($this->sender, $this->document->id, $this->device);
});

it('lists a pending transfer for its recipient only', function () {
    expect($this->transfers->pendingFor($this->recipient)->pluck('id')->all())->toBe([$this->transfer->id])
        ->and($this->transfers->pendingFor($this->sender)->count())->toBe(0);
});

it('delivers the bytes, marks the transfer delivered, and audits it', function () {
    $result = $this->transfers->claim($this->recipient, $this->transfer, '10.1.2.3');

    expect($result['contents'])->toBeString()
        ->and(hash('sha256', $result['contents']))->toBe($this->transfer->expected_sha256)
        ->and($result['version']->original_filename)->toBe('freight.pdf')
        ->and($result['transfer']->status)->toBe(DocumentTransferStatus::Delivered)
        ->and($result['transfer']->delivered_at)->not->toBeNull();

    $audit = $this->document->audits()->where('action', DocumentAuditAction::TransferDelivered)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($this->recipient->id)
        ->and($audit->metadata['transfer_id'])->toBe($this->transfer->id)
        ->and($audit->metadata['path'])->toBe('server');
});

it('refuses a claim by anyone other than the recipient', function () {
    expect(fn () => $this->transfers->claim($this->sender, $this->transfer))
        ->toThrow(RuntimeException::class, 'This transfer was not sent to you.');

    expect($this->transfer->fresh()->status)->toBe(DocumentTransferStatus::Pending);
});

it('refuses a second claim of the same transfer', function () {
    $this->transfers->claim($this->recipient, $this->transfer);

    expect(fn () => $this->transfers->claim($this->recipient, $this->transfer->fresh()))
        ->toThrow(RuntimeException::class, 'This transfer is no longer pending.');
});

it('refuses a claim after expiry', function () {
    $this->transfer->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $this->transfers->claim($this->recipient, $this->transfer->fresh()))
        ->toThrow(RuntimeException::class, 'This transfer has expired.');
});

it('lets the sender cancel a pending transfer and audits it', function () {
    $cancelled = $this->transfers->cancel($this->sender, $this->transfer);

    expect($cancelled->status)->toBe(DocumentTransferStatus::Cancelled);

    expect($this->document->audits()->where('action', DocumentAuditAction::TransferCancelled)->exists())->toBeTrue();

    expect(fn () => $this->transfers->claim($this->recipient, $this->transfer->fresh()))
        ->toThrow(RuntimeException::class, 'This transfer is no longer pending.');
});

it('refuses cancellation by an unrelated user', function () {
    $outsider = documentTestUser($this->company);

    expect(fn () => $this->transfers->cancel($outsider, $this->transfer))
        ->toThrow(RuntimeException::class, 'You cannot cancel this transfer.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferClaimTest.php`
Expected: FAIL with `Call to undefined method ...::pendingFor()`

- [ ] **Step 3: Add the three methods**

Add these to `DocumentTransferService`, and add `use Illuminate\Support\Collection;` to its imports:

```php
    /**
     * @return Collection<int, DocumentTransfer>
     */
    public function pendingFor(User $user): Collection
    {
        return DocumentTransfer::query()
            ->pending()
            ->forRecipient($user->id)
            ->where('expires_at', '>', now())
            ->with(['document.currentVersion', 'sender'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{contents: string, version: \Webkul\Accounting\Models\DocumentVersion, transfer: DocumentTransfer}
     */
    public function claim(User $recipient, DocumentTransfer $transfer, ?string $ipAddress = null): array
    {
        if ($transfer->recipient_id !== $recipient->id) {
            throw new RuntimeException('This transfer was not sent to you.');
        }

        if (! $transfer->isPending()) {
            throw new RuntimeException('This transfer is no longer pending.');
        }

        if ($transfer->hasExpired()) {
            throw new RuntimeException('This transfer has expired.');
        }

        // retrieveContents() re-verifies the stored checksum and audits
        // the read, so integrity is checked by the same code path a
        // normal download uses -- not a parallel one.
        $result = $this->documents->retrieveContents($recipient, $transfer->document_id, $ipAddress);

        if ($transfer->expected_sha256 !== null
            && hash('sha256', $result['contents']) !== $transfer->expected_sha256) {
            throw new RuntimeException('The document changed after this transfer was created.');
        }

        $transfer->update([
            'status'       => DocumentTransferStatus::Delivered,
            'delivered_at' => now(),
        ]);

        $transfer->document->audits()->create([
            'company_id' => $transfer->company_id,
            'actor_id'   => $recipient->id,
            'action'     => DocumentAuditAction::TransferDelivered,
            'ip_address' => $ipAddress,
            'metadata'   => [
                'transfer_id' => $transfer->id,
                'sender_id'   => $transfer->sender_id,
                'device_id'   => $transfer->recipient_device_id,
                // Recorded explicitly so a later direct peer transport is
                // distinguishable from server delivery in the audit trail.
                'path'        => 'server',
            ],
        ]);

        return [
            'contents' => $result['contents'],
            'version'  => $result['version'],
            'transfer' => $transfer->refresh(),
        ];
    }

    public function cancel(User $actor, DocumentTransfer $transfer, ?string $ipAddress = null): DocumentTransfer
    {
        if (! in_array($actor->id, [$transfer->sender_id, $transfer->recipient_id], true)) {
            throw new RuntimeException('You cannot cancel this transfer.');
        }

        if (! $transfer->isPending()) {
            throw new RuntimeException('This transfer is no longer pending.');
        }

        $transfer->update(['status' => DocumentTransferStatus::Cancelled]);

        $transfer->document->audits()->create([
            'company_id' => $transfer->company_id,
            'actor_id'   => $actor->id,
            'action'     => DocumentAuditAction::TransferCancelled,
            'ip_address' => $ipAddress,
            'metadata'   => ['transfer_id' => $transfer->id],
        ]);

        return $transfer->refresh();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferClaimTest.php`
Expected: PASS, 7 tests

- [ ] **Step 5: Run the whole P2P directory**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/`
Expected: PASS, 24 tests across 5 files

- [ ] **Step 6: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/accounting/src/Services/DocumentTransferService.php plugins/webkul/accounting/tests/Feature/P2P/DocumentTransferClaimTest.php
git commit -m "feat(accounting): claim and cancel document transfers with checksum re-verification"
```

---

### Task 6: Expiry sweep command

**Files:**
- Create: `plugins/webkul/accounting/src/Console/Commands/ExpireDocumentTransfersCommand.php`
- Modify: `plugins/webkul/accounting/src/AccountingServiceProvider.php` (add to `hasCommands` array and imports)
- Test: `plugins/webkul/accounting/tests/Feature/P2P/ExpireDocumentTransfersCommandTest.php`

**Interfaces:**
- Consumes: `DocumentTransfer`, `DocumentTransferStatus` from Task 3
- Produces: artisan command `accounting:transfers:expire`

- [ ] **Step 1: Write the failing test**

Create `plugins/webkul/accounting/tests/Feature/P2P/ExpireDocumentTransfersCommandTest.php`:

```php
<?php

use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentTransfer;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

function transferWithExpiry($expiresAt, DocumentTransferStatus $status = DocumentTransferStatus::Pending): DocumentTransfer
{
    $sender    = documentTestUser();
    $recipient = documentTestUser();

    return DocumentTransfer::query()->create([
        'company_id'   => $sender->default_company_id,
        'document_id'  => Document::factory()->create(['company_id' => $sender->default_company_id])->id,
        'sender_id'    => $sender->id,
        'recipient_id' => $recipient->id,
        'status'       => $status,
        'expires_at'   => $expiresAt,
    ]);
}

it('marks pending transfers past their expiry as expired', function () {
    $stale = transferWithExpiry(now()->subDay());
    $live  = transferWithExpiry(now()->addDay());

    $this->artisan('accounting:transfers:expire')
        ->expectsOutputToContain('Expired 1 transfer')
        ->assertExitCode(0);

    expect($stale->fresh()->status)->toBe(DocumentTransferStatus::Expired)
        ->and($live->fresh()->status)->toBe(DocumentTransferStatus::Pending);
});

it('leaves already-delivered transfers untouched', function () {
    $delivered = transferWithExpiry(now()->subDay(), DocumentTransferStatus::Delivered);

    $this->artisan('accounting:transfers:expire')->assertExitCode(0);

    expect($delivered->fresh()->status)->toBe(DocumentTransferStatus::Delivered);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/ExpireDocumentTransfersCommandTest.php`
Expected: FAIL — command `accounting:transfers:expire` does not exist

- [ ] **Step 3: Write the command**

Create `plugins/webkul/accounting/src/Console/Commands/ExpireDocumentTransfersCommand.php`:

```php
<?php

namespace Webkul\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\DocumentTransfer;

/**
 * Flips pending transfers past their expiry to Expired.
 *
 * claim() already refuses an expired transfer, so this is housekeeping
 * for reporting and for the recipient's inbox rather than a security
 * control -- an unrun sweep cannot let a stale transfer be claimed.
 *
 * This repository has no application scheduler wired (bootstrap/app.php
 * registers no withSchedule), so run it from external cron.
 */
class ExpireDocumentTransfersCommand extends Command
{
    protected $signature = 'accounting:transfers:expire';

    protected $description = 'Mark pending document transfers past their expiry as expired';

    public function handle(): int
    {
        $count = DocumentTransfer::query()
            ->pending()
            ->where('expires_at', '<=', now())
            ->update(['status' => DocumentTransferStatus::Expired]);

        $this->info("Expired {$count} transfer(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the command**

In `plugins/webkul/accounting/src/AccountingServiceProvider.php`, add to the `hasCommands([...])` array:

```php
                ExpireDocumentTransfersCommand::class,
```

And add the import:

```php
use Webkul\Accounting\Console\Commands\ExpireDocumentTransfersCommand;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/ExpireDocumentTransfersCommandTest.php`
Expected: PASS, 2 tests

- [ ] **Step 6: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/accounting/src/Console/Commands/ExpireDocumentTransfersCommand.php plugins/webkul/accounting/src/AccountingServiceProvider.php plugins/webkul/accounting/tests/Feature/P2P/ExpireDocumentTransfersCommandTest.php
git commit -m "feat(accounting): add expiry sweep command for pending document transfers"
```

---

### Task 7: API surface

**Files:**
- Create: `plugins/webkul/security/src/Http/Controllers/API/V1/DeviceController.php`
- Modify: `plugins/webkul/security/routes/api.php`
- Create: `plugins/webkul/accounting/routes/api.php`
- Create: `plugins/webkul/accounting/src/Http/Controllers/API/V1/DocumentTransferController.php`
- Modify: `plugins/webkul/accounting/src/AccountingServiceProvider.php` (add `->hasRoute('api')`)
- Test: `plugins/webkul/accounting/tests/Feature/P2P/TransferApiTest.php`

**Interfaces:**
- Consumes: `DeviceRegistrationService` (Task 2), `DocumentTransferService` (Tasks 4–5)
- Produces these Sanctum-protected endpoints:
  - `GET admin/api/v1/devices`
  - `POST admin/api/v1/devices` — body `{label, public_key, platform?}`
  - `DELETE admin/api/v1/devices/{device}`
  - `GET admin/api/v1/document-transfers` — the caller's pending inbox
  - `POST admin/api/v1/document-transfers` — body `{document_id, device_id, note?}`
  - `POST admin/api/v1/document-transfers/{transfer}/claim`
  - `POST admin/api/v1/document-transfers/{transfer}/cancel`

**Company scope is taken from the authenticated user, never from the request body.** Do not add a `company_id` parameter.

- [ ] **Step 1: Write the failing test**

Create `plugins/webkul/accounting/tests/Feature/P2P/TransferApiTest.php`:

```php
<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\UserDevice;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->company = Company::factory()->create(['is_active' => true]);

    $this->sender = documentTestUser($this->company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::TransferDocuments,
        AccountingPermissions::ViewDocuments,
    ]);

    $this->recipient = documentTestUser($this->company, [
        AccountingPermissions::ViewDocuments,
        AccountingPermissions::DownloadDocuments,
    ]);
});

it('registers a device and lists it', function () {
    $this->actingAs($this->sender, 'sanctum')
        ->postJson('admin/api/v1/devices', [
            'label'      => 'Windows desktop',
            'public_key' => 'SPKI-BYTES',
            'platform'   => 'windows',
        ])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Windows desktop');

    $this->actingAs($this->sender, 'sanctum')
        ->getJson('admin/api/v1/devices')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects an unauthenticated device registration', function () {
    $this->postJson('admin/api/v1/devices', ['label' => 'X', 'public_key' => 'Y'])
        ->assertUnauthorized();
});

it('sends a transfer and shows it in the recipient inbox', function () {
    $device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => hash('sha256', 'KEY-1'),
    ]);

    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $this->actingAs($this->sender, 'sanctum')
        ->postJson('admin/api/v1/document-transfers', [
            'document_id' => $document->id,
            'device_id'   => $device->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $this->actingAs($this->recipient, 'sanctum')
        ->getJson('admin/api/v1/document-transfers')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->sender, 'sanctum')
        ->getJson('admin/api/v1/document-transfers')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lets the sender cancel a pending transfer over the API', function () {
    $device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-3',
        'public_key_fingerprint' => hash('sha256', 'KEY-3'),
    ]);

    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $created = $this->actingAs($this->sender, 'sanctum')
        ->postJson('admin/api/v1/document-transfers', [
            'document_id' => $document->id,
            'device_id'   => $device->id,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->sender, 'sanctum')
        ->postJson("admin/api/v1/document-transfers/{$created}/cancel")
        ->assertOk();

    $this->actingAs($this->recipient, 'sanctum')
        ->getJson('admin/api/v1/document-transfers')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns 422 when sending without the transfer permission', function () {
    $device = UserDevice::query()->create([
        'user_id'                => $this->recipient->id,
        'company_id'             => $this->company->id,
        'label'                  => 'Recipient laptop',
        'public_key'             => 'KEY-2',
        'public_key_fingerprint' => hash('sha256', 'KEY-2'),
    ]);

    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::Invoice,
        'Freight invoice',
        null,
        fakeUploadedFileWithRealContent('freight.pdf', 'application/pdf'),
    );

    $this->actingAs($this->recipient, 'sanctum')
        ->postJson('admin/api/v1/document-transfers', [
            'document_id' => $document->id,
            'device_id'   => $device->id,
        ])
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/TransferApiTest.php`
Expected: FAIL with 404 — routes do not exist

- [ ] **Step 3: Write the device controller**

Create `plugins/webkul/security/src/Http/Controllers/API/V1/DeviceController.php`:

```php
<?php

namespace Webkul\Security\Http\Controllers\API\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Webkul\Security\Models\UserDevice;
use Webkul\Security\Services\DeviceRegistrationService;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceRegistrationService $devices) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->devices->activeFor($request->user())->map(fn (UserDevice $device) => [
                'id'           => $device->id,
                'label'        => $device->label,
                'platform'     => $device->platform,
                'last_seen_at' => $device->last_seen_at,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'      => ['required', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:4096'],
            'platform'   => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $device = $this->devices->register(
                $request->user(),
                $data['label'],
                $data['public_key'],
                $data['platform'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id'       => $device->id,
            'label'    => $device->label,
            'platform' => $device->platform,
        ]], 201);
    }

    public function destroy(Request $request, UserDevice $device): JsonResponse
    {
        try {
            $this->devices->revoke($request->user(), $device);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['message' => 'Device revoked.']);
    }
}
```

- [ ] **Step 4: Add the device routes**

In `plugins/webkul/security/routes/api.php`, add these three lines inside the existing `auth:sanctum` group (after the `logout` route):

```php
    Route::get('devices', [DeviceController::class, 'index']);
    Route::post('devices', [DeviceController::class, 'store']);
    Route::delete('devices/{device}', [DeviceController::class, 'destroy']);
```

And add the import at the top:

```php
use Webkul\Security\Http\Controllers\API\V1\DeviceController;
```

- [ ] **Step 5: Write the transfer controller**

Create `plugins/webkul/accounting/src/Http/Controllers/API/V1/DocumentTransferController.php`:

```php
<?php

namespace Webkul\Accounting\Http\Controllers\API\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Throwable;
use Webkul\Accounting\Models\DocumentTransfer;
use Webkul\Accounting\Services\DocumentTransferService;
use Webkul\Security\Models\UserDevice;

class DocumentTransferController extends Controller
{
    public function __construct(private readonly DocumentTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->transfers->pendingFor($request->user())->map(fn (DocumentTransfer $transfer) => [
                'id'         => $transfer->id,
                'document'   => $transfer->document?->title,
                'filename'   => $transfer->document?->currentVersion?->original_filename,
                'sender'     => $transfer->sender?->name,
                'note'       => $transfer->note,
                'status'     => $transfer->status->value,
                'expires_at' => $transfer->expires_at,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer'],
            'device_id'   => ['required', 'integer'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        $device = UserDevice::query()->findOrFail($data['device_id']);

        try {
            $transfer = $this->transfers->send(
                $request->user(),
                $data['document_id'],
                $device,
                $data['note'] ?? null,
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return response()->json(['data' => [
            'id'         => $transfer->id,
            'status'     => $transfer->status->value,
            'expires_at' => $transfer->expires_at,
        ]], 201);
    }

    public function claim(Request $request, DocumentTransfer $transfer)
    {
        try {
            $result = $this->transfers->claim($request->user(), $transfer, $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->streamDownload(
            fn () => print ($result['contents']),
            $result['version']->original_filename,
        );
    }

    public function cancel(Request $request, DocumentTransfer $transfer): JsonResponse
    {
        try {
            $this->transfers->cancel($request->user(), $transfer, $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Transfer cancelled.']);
    }
}
```

- [ ] **Step 6: Add the accounting routes**

Create `plugins/webkul/accounting/routes/api.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Webkul\Accounting\Http\Controllers\API\V1\DocumentTransferController;

Route::prefix('admin/api/v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('document-transfers', [DocumentTransferController::class, 'index']);
    Route::post('document-transfers', [DocumentTransferController::class, 'store']);
    Route::post('document-transfers/{transfer}/claim', [DocumentTransferController::class, 'claim']);
    Route::post('document-transfers/{transfer}/cancel', [DocumentTransferController::class, 'cancel']);
});
```

- [ ] **Step 7: Register the route file**

The accounting plugin does not currently declare any routes. In `plugins/webkul/accounting/src/AccountingServiceProvider.php`, inside `configureCustomPackage()`, add `->hasRoute('api')` immediately after `->hasTranslations()`:

```php
            ->hasRoute('api')
```

- [ ] **Step 8: Run test to verify it passes**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/TransferApiTest.php`
Expected: PASS, 5 tests

- [ ] **Step 9: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/security/src/Http/Controllers/API/V1/DeviceController.php plugins/webkul/security/routes/api.php plugins/webkul/accounting/routes/api.php plugins/webkul/accounting/src/Http/Controllers/API/V1/DocumentTransferController.php plugins/webkul/accounting/src/AccountingServiceProvider.php plugins/webkul/accounting/tests/Feature/P2P/TransferApiTest.php
git commit -m "feat: add device and document-transfer API endpoints, company-scoped from the token"
```

---

### Task 8: Filament UI

**Files:**
- Modify: `plugins/webkul/accounting/src/Filament/RelationManagers/DocumentAttachmentsRelationManager.php`
- Create: `plugins/webkul/accounting/src/Filament/Clusters/Accounting/Pages/DocumentInbox.php`
- Create: `plugins/webkul/accounting/resources/views/filament/clusters/accounting/pages/document-inbox.blade.php`
- Test: `plugins/webkul/accounting/tests/Feature/P2P/SendToDeviceActionTest.php`

**Interfaces:**
- Consumes: `DocumentTransferService` (Tasks 4–5), `AccountingPermissions::TransferDocuments` (Task 3), `UserDevice` (Task 1)
- Produces: a `sendToDevice` record action on the Supporting-documents table, and a `DocumentInbox` page listing transfers sent to the current user

**BLOCKER — read before starting.** A Livewire test of this action needs the Filament panel booted, which is the pattern in `RelationManagerRenderingTest.php`, and that file calls `TestBootstrapHelper::ensurePluginInstalled('accounts')` — which hits the `AccountSeeder` null-company crash described in Global Constraints. Either fix that seeder first (add a null guard at `AccountSeeder.php:28`, a one-line change that unblocks the whole existing Documents suite), or keep this task's test to the permission-wiring assertion below and verify rendering manually in Step 6. **Do not delete or weaken an assertion to make a test pass.**

- [ ] **Step 1: Write the test**

Create `plugins/webkul/accounting/tests/Feature/P2P/SendToDeviceActionTest.php`:

```php
<?php

use Webkul\Accounting\Filament\RelationManagers\DocumentAttachmentsRelationManager;
use Webkul\Accounting\Support\AccountingPermissions;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

it('grants the transfer permission only to users who were given it', function () {
    $allowed = documentTestUser(null, [
        AccountingPermissions::ViewDocuments,
        AccountingPermissions::TransferDocuments,
    ]);

    $denied = documentTestUser(null, [
        AccountingPermissions::ViewDocuments,
    ]);

    expect($allowed->can(AccountingPermissions::TransferDocuments))->toBeTrue()
        ->and($denied->can(AccountingPermissions::TransferDocuments))->toBeFalse();
});

it('declares the relation manager table that hosts the action', function () {
    // The action is registered with ->authorize(TransferDocuments), so
    // Filament both hides it and refuses execution for a user without
    // that permission. Rendering is verified manually in Step 6 until the
    // AccountSeeder blocker is fixed.
    expect(method_exists(DocumentAttachmentsRelationManager::class, 'table'))->toBeTrue();
});
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/pest plugins/webkul/accounting/tests/Feature/P2P/SendToDeviceActionTest.php`
Expected: PASS, 2 tests

- [ ] **Step 3: Add the action to the relation manager**

In `plugins/webkul/accounting/src/Filament/RelationManagers/DocumentAttachmentsRelationManager.php`, add these imports:

```php
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Webkul\Accounting\Services\DocumentTransferService;
use Webkul\Security\Models\UserDevice;
```

Then add this action to the `recordActions([...])` array, immediately after the existing `download` action:

```php
                Action::make('sendToDevice')
                    ->label('Send to device')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->authorize(AccountingPermissions::TransferDocuments)
                    ->schema([
                        Select::make('device_id')
                            ->label('Recipient device')
                            ->required()
                            ->searchable()
                            ->options(fn (): array => UserDevice::query()
                                ->active()
                                ->where('company_id', Auth::user()?->default_company_id)
                                ->where('user_id', '!=', Auth::id())
                                ->with('user')
                                ->get()
                                ->mapWithKeys(fn (UserDevice $device): array => [
                                    $device->id => "{$device->user?->name} — {$device->label}",
                                ])
                                ->all())
                            ->helperText('Only active devices registered inside your company are listed.'),
                        Textarea::make('note')
                            ->label('Note')
                            ->maxLength(500),
                    ])
                    ->action(function (DocumentAttachment $record, array $data): void {
                        try {
                            $device = UserDevice::query()->findOrFail($data['device_id']);

                            app(DocumentTransferService::class)->send(
                                Auth::user(),
                                $record->document_id,
                                $device,
                                $data['note'] ?? null,
                                request()->ip(),
                            );

                            Notification::make()->success()->title('Document sent to device')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not send this document')->body($e->getMessage())->send();
                        }
                    }),
```

- [ ] **Step 4: Create the inbox page**

Create `plugins/webkul/accounting/src/Filament/Clusters/Accounting/Pages/DocumentInbox.php`:

```php
<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Services\DocumentTransferService;
use Webkul\Accounting\Support\AccountingPermissions;

class DocumentInbox extends Page
{
    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $slug = 'document-inbox';

    protected string $view = 'accounting::filament.clusters.accounting.pages.document-inbox';

    public static function canAccess(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ViewDocuments) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'Document Inbox';
    }

    public function getTitle(): string
    {
        return 'Document Inbox';
    }

    public function getTransfers(): Collection
    {
        return app(DocumentTransferService::class)->pendingFor(Auth::user());
    }
}
```

- [ ] **Step 5: Create the inbox view**

Create `plugins/webkul/accounting/resources/views/filament/clusters/accounting/pages/document-inbox.blade.php`:

```blade
<x-filament-panels::page>
    @php($transfers = $this->getTransfers())

    @if ($transfers->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No documents have been sent to your devices.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($transfers as $transfer)
                    <div class="flex items-center justify-between gap-4 py-3">
                        <div>
                            <p class="font-medium">{{ $transfer->document?->title }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                From {{ $transfer->sender?->name }}
                                &middot; {{ $transfer->document?->currentVersion?->original_filename }}
                                &middot; expires {{ $transfer->expires_at?->diffForHumans() }}
                            </p>
                            @if ($transfer->note)
                                <p class="mt-1 text-sm italic text-gray-500 dark:text-gray-400">{{ $transfer->note }}</p>
                            @endif
                        </div>

                        <x-filament::button
                            tag="a"
                            href="{{ url('admin/api/v1/document-transfers/'.$transfer->id.'/claim') }}"
                            icon="heroicon-o-arrow-down-tray"
                        >
                            Download
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 6: Verify manually in the browser**

1. `php artisan migrate`
2. Register a device for two different users via `POST admin/api/v1/devices`
3. As a user holding `accounting_transfer_documents`, open any Bill with a supporting document and use **Send to device**
4. Log in as the recipient and open **Accounting → Document Inbox**; confirm the transfer is listed and downloads
5. Confirm the document's **History** modal now shows `Sent to device` and `Delivered to device` rows
6. Confirm a read-only role (Internal Auditor) does **not** see the Send to device action

- [ ] **Step 7: Run the full accounting suite**

Run: `./vendor/bin/pest --testsuite=AccountingFeature`
Expected: no new failures versus the pre-existing baseline. Record that baseline before starting if you have not already — `Documents/DriveFolderPathResolverTest.php` and `Documents/RelationManagerRenderingTest.php` already fail on this branch because of the `AccountSeeder` bug, and that is not caused by this work.

- [ ] **Step 8: Run Pint and commit**

```bash
./vendor/bin/pint --dirty
git add plugins/webkul/accounting/src/Filament/RelationManagers/DocumentAttachmentsRelationManager.php plugins/webkul/accounting/src/Filament/Clusters/Accounting/Pages/DocumentInbox.php plugins/webkul/accounting/resources/views/filament/clusters/accounting/pages/document-inbox.blade.php plugins/webkul/accounting/tests/Feature/P2P/SendToDeviceActionTest.php
git commit -m "feat(accounting): add send-to-device action and recipient document inbox"
```

---

## After this plan

Update the code graph, since source relationships changed:

```bash
graphify update .
```

Then re-query to confirm the new service sits where intended:

```bash
graphify explain "DocumentTransferService"
graphify path "DocumentTransferService" "DocumentStorageProvider"
```

The second command should show `DocumentTransferService` reaching storage **only** through `DocumentService`. If a direct edge appears, the layering has been violated.

## What this plan deliberately excludes

- **WebRTC, STUN, TURN, signaling, ICE.** That is the next plan, and it should only be written after a standalone POC measures the direct-connection rate on the customer's real networks. The research report sets the gate at 70% non-relay; below that, peer transport is a relay service wearing a P2P label and should be abandoned in favour of what this plan builds.
- **Raising the 20 MB cap, streaming uploads, resumable uploads.** Separate work, and a prerequisite for peer transport being worth anything.
- **Reverb / realtime push.** The inbox renders on page load, which is adequate for this payload size. Reverb becomes worthwhile when signaling arrives.
- **Recipient notifications.** The `chatter` plugin already has a notification mechanism; wiring it in is a small follow-up, not part of the transfer core.
