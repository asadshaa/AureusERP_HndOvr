<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

it('registers the accounting_documents disk in filesystems configuration', function () {
    $diskConfig = Config::get('filesystems.disks.accounting_documents');

    expect($diskConfig)->toBeArray()
        ->and($diskConfig['driver'])->toBe('local')
        ->and($diskConfig['visibility'])->toBe('private')
        ->and($diskConfig['throw'])->toBeTrue();
});

it('can read, write, check existence, and delete files on the local accounting_documents disk', function () {
    $disk = Storage::disk('accounting_documents');

    $testCompanyId = 999;
    $testYear = date('Y');
    $testPath = "companies/{$testCompanyId}/{$testYear}/invoices/test-document.pdf";
    $testPayload = '%PDF-1.4 Fake PDF Content for Aureus Storage Foundation Verification';

    // 1. Ensure clean slate
    if ($disk->exists($testPath)) {
        $disk->delete($testPath);
    }
    expect($disk->exists($testPath))->toBeFalse();

    // 2. Put file
    $stored = $disk->put($testPath, $testPayload);
    expect($stored)->toBeTrue();
    expect($disk->exists($testPath))->toBeTrue();

    // 3. Read back and verify exact byte integrity
    $retrieved = $disk->get($testPath);
    expect($retrieved)->toBe($testPayload);

    // 4. Verify checksum
    $checksum = hash('sha256', $retrieved);
    expect($checksum)->toBe(hash('sha256', $testPayload));

    // 5. Verify size
    expect($disk->size($testPath))->toBe(strlen($testPayload));

    // 6. Delete file and verify cleanup
    $deleted = $disk->delete($testPath);
    expect($deleted)->toBeTrue();
    expect($disk->exists($testPath))->toBeFalse();
});

it('enforces private storage visibility configuration on accounting_documents disk', function () {
    $diskConfig = Config::get('filesystems.disks.accounting_documents');

    expect($diskConfig['visibility'])->toBe('private');

    if (DIRECTORY_SEPARATOR !== '\\') {
        $disk = Storage::disk('accounting_documents');
        $testPath = 'companies/1/receipts/visibility-check.txt';
        $disk->put($testPath, 'confidential financial evidence');
        expect($disk->getVisibility($testPath))->toBe('private');
        $disk->delete($testPath);
    }
});

it('resolves S3 driver and adapter when configured without missing class errors', function () {
    Config::set('filesystems.disks.accounting_documents_s3_test', [
        'driver'                  => 's3',
        'key'                     => 'dummy-key',
        'secret'                  => 'dummy-secret',
        'region'                  => 'us-east-1',
        'bucket'                  => 'aureus-test-bucket',
        'use_path_style_endpoint' => false,
        'visibility'              => 'private',
        'throw'                   => true,
    ]);

    $disk = Storage::disk('accounting_documents_s3_test');

    $adapter = $disk->getAdapter();
    expect($adapter)->toBeInstanceOf(AwsS3V3Adapter::class);
});
