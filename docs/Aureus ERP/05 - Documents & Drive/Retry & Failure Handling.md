# Retry & Failure Handling

Status: Implemented / Verified
Related: [[Document Architecture]], [[Drive Integration]], [[Sync Workflow]], [[Technical Debt]]

Document transmission and cloud synchronization must maintain business resilience in the face of transient network outages, Google API rate limits, file system corruptions, and invalid credentials.

---

## 1. Resilience Philosophy

1. **ERP Ledger Primacy**: Core ERP operations (posting invoices, paying bills, reconciling statements) must never block or fail due to cloud synchronization issues.
2. **Explicit Failure Visibility**: Failures must never fail silently. They are logged to `accounting_document_drive_syncs` with structured error messages.
3. **Idempotent Retries**: Reprocessing a failed sync job must safely update existing Drive files without creating duplicate orphan files.

---

## 2. Failure Classification & Handlers

The sync engine distinguishes between transient failures (eligible for retry) and fatal errors (requiring operator intervention):

| Failure Category | Examples | Handling Strategy | Max Retries |
|---|---|---|---|
| **Transient Network** | DNS timeout, HTTP 502/503/504, Connection reset | Exponential backoff (`tries = 5`, backoff: 10s, 30s, 60s, 300s) | 5 |
| **API Quota / Rate Limit** | HTTP 429 Too Many Requests | Parse `Retry-After` header; delay worker execution | 5 |
| **Auth / Credential** | HTTP 401 Unauthorized, Expired Service Account Key | Abort retries; mark `failed`; surface banner alert to System Administrator | 0 |
| **Integrity Mismatch** | Local disk SHA-256 does not match database record | Abort sync; flag document as corrupted; log audit alert | 0 |
| **Payload Size Limit** | File exceeds Google Drive API single request limit | Switch to chunked resumable upload protocol | 3 |

---

## 3. Queue Configuration & Exponential Backoff

In `plugins/webkul/accounting/src/Jobs/SyncDocumentToDriveJob.php`:

```php
class SyncDocumentToDriveJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function failed(\Throwable $exception): void
    {
        $this->syncRecord->update([
            'status'        => 'failed',
            'error_message' => DriveErrorFormatter::format($exception),
            'failed_at'     => now(),
        ]);
    }
}
```

---

## 4. Monitoring & Administrator Intervention

1. **Filament Failed Syncs Filter**:
   - In `DocumentResource`, an administrative filter isolates all records with `sync_status = 'failed'`.
   - Table bulk action: **Retry Failed Syncs** re-queues selected jobs with cleared retry counters.
2. **Health Check Command**:
   - `accounting:check-document-integrity` verifies whether files referenced in `accounting_document_versions` exist physically on the storage disk.
   - Any missing payload is flagged immediately for backup restoration.

---

## 5. Verification

- Tested via `DriveHardenedScenariosTest.php` and `DocumentIntegrityCheckerTest.php`.
- Simulated network failure conditions, bad tokens, and truncated file streams confirm graceful fallback.
