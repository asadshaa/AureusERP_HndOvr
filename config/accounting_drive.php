<?php

use Webkul\Accounting\Enums\DocumentType;

return [

    /*
    |--------------------------------------------------------------------------
    | Google Drive document sync
    |--------------------------------------------------------------------------
    |
    | Off by default -- Aureus accounting operations never depend on this
    | being configured or reachable. When disabled, DispatchDriveSyncOnDocumentChanged
    | never dispatches a sync job at all (not "dispatches and it silently
    | no-ops" -- it doesn't fire), so there's zero overhead when unused.
    |
    */
    'enabled' => env('ACCOUNTING_DRIVE_SYNC_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | OAuth with a real account's refresh token, not a service account --
    | a bare service account cannot own files in a personal (non-Workspace)
    | Google Drive; writes fail outright with zero storage quota. The
    | refresh token is obtained once via `php artisan accounting:drive:authorize`
    | and then stored here via env, never in source control.
    |
    */
    'client_id'     => env('ACCOUNTING_DRIVE_CLIENT_ID'),
    'client_secret' => env('ACCOUNTING_DRIVE_CLIENT_SECRET'),
    'refresh_token' => env('ACCOUNTING_DRIVE_REFRESH_TOKEN'),

    // The narrowest scope that still allows detecting a file a human adds
    // manually to a recognized folder (needed for the future Drive->Aureus
    // import phase; export alone would need only drive.file).
    'scopes' => [
        'https://www.googleapis.com/auth/drive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where documents land in Drive
    |--------------------------------------------------------------------------
    |
    | Optional: a Shared Drive id, if this Google account has one. Null
    | means "My Drive" root of whichever account authorized the refresh
    | token above.
    |
    */
    'shared_drive_id' => env('ACCOUNTING_DRIVE_SHARED_DRIVE_ID'),

    'root_folder_name' => env('ACCOUNTING_DRIVE_ROOT_FOLDER', 'Aureus'),

    /*
    |--------------------------------------------------------------------------
    | Folder path templates
    |--------------------------------------------------------------------------
    |
    | The deterministic-but-not-hardcoded mapping DriveFolderPathResolver
    | reads. Each entry is a list of path SEGMENTS relative to the root
    | folder above; {company} and {identifier} are resolved per-document
    | by the resolver. Keyed by DocumentType value; 'default' covers any
    | type without its own entry. Change this to change the convention --
    | nothing else in the codebase hardcodes these paths.
    |
    */
    'path_templates' => [
        DocumentType::Invoice->value         => ['{company}', 'Accounting', 'Invoices', '{identifier}'],
        DocumentType::Bill->value            => ['{company}', 'Accounting', 'Bills', '{identifier}'],
        DocumentType::JournalEntry->value    => ['{company}', 'Accounting', 'Journal Entries', '{identifier}'],
        DocumentType::PaymentEvidence->value => ['{company}', 'Accounting', 'Payments', '{identifier}'],
        DocumentType::BankStatement->value   => ['{company}', 'Accounting', 'Bank Statements', '{identifier}'],
        'default'                            => ['{company}', 'Accounting', 'Other Documents'],
    ],

];
