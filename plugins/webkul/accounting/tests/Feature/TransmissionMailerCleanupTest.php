<?php

use Illuminate\Support\Facades\Mail;
use Webkul\Accounting\Models\OutboundTransmission;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Peers\TransmissionMailer;

it('identifies and cleans up ordinary temporary files', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'aureus-test-');
    expect(file_exists($tempFile))->toBeTrue();

    expect(TransmissionMailer::isTemporaryPath($tempFile))->toBeTrue();

    $deleted = TransmissionMailer::cleanupTemporaryAttachment($tempFile);
    expect($deleted)->toBeTrue()
        ->and(file_exists($tempFile))->toBeFalse();
});

it('rejects sibling directories with similar prefixes', function () {
    $fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mailer_test_'.uniqid();
    $targetTempDir = $fixtureRoot.DIRECTORY_SEPARATOR.'temp';
    $siblingDir = $fixtureRoot.DIRECTORY_SEPARATOR.'temp_sibling';

    @mkdir($targetTempDir, 0777, true);
    @mkdir($siblingDir, 0777, true);

    $siblingFile = $siblingDir.DIRECTORY_SEPARATOR.'sensitive_user_file.pdf';
    file_put_contents($siblingFile, 'do not delete');

    try {
        expect(TransmissionMailer::isTemporaryPath($siblingFile, $targetTempDir))->toBeFalse();

        $deleted = TransmissionMailer::cleanupTemporaryAttachment($siblingFile, $targetTempDir);
        expect($deleted)->toBeFalse()
            ->and(file_exists($siblingFile))->toBeTrue();
    } finally {
        @unlink($siblingFile);
        @rmdir($siblingDir);
        @rmdir($targetTempDir);
        @rmdir($fixtureRoot);
    }
});

it('resolves symlinks safely for temporary directories and files', function () {
    $fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'symlink_test_'.uniqid();
    $realTargetDir = $fixtureRoot.DIRECTORY_SEPARATOR.'private_target';
    $symlinkDir = $fixtureRoot.DIRECTORY_SEPARATOR.'symlinked_temp';

    @mkdir($realTargetDir, 0777, true);

    $canSymlink = false;
    try {
        // Windows may require developer mode or admin privilege for symlinks
        $canSymlink = @symlink($realTargetDir, $symlinkDir);
    } catch (Throwable) {
        $canSymlink = false;
    }

    if ($canSymlink && is_link($symlinkDir)) {
        try {
            $testFile = $realTargetDir.DIRECTORY_SEPARATOR.'test.pdf';
            file_put_contents($testFile, 'symlink content');

            $fileViaSymlink = $symlinkDir.DIRECTORY_SEPARATOR.'test.pdf';

            // Passing symlink directory as tempDir matches file via real path
            expect(TransmissionMailer::isTemporaryPath($testFile, $symlinkDir))->toBeTrue()
                ->and(TransmissionMailer::isTemporaryPath($fileViaSymlink, $realTargetDir))->toBeTrue();

            $deleted = TransmissionMailer::cleanupTemporaryAttachment($fileViaSymlink, $realTargetDir);
            expect($deleted)->toBeTrue()
                ->and(file_exists($testFile))->toBeFalse();
        } finally {
            if (file_exists($realTargetDir.DIRECTORY_SEPARATOR.'test.pdf')) {
                @unlink($realTargetDir.DIRECTORY_SEPARATOR.'test.pdf');
            }
            if (PHP_OS_FAMILY === 'Windows') {
                @rmdir($symlinkDir);
            } else {
                @unlink($symlinkDir);
            }
        }
    } else {
        // If the OS environment does not permit creating symlinks, verify simulated path resolution
        $simulatedBase = str_replace('\\', '/', $realTargetDir);
        $simulatedFile = $simulatedBase.'/sub/file.pdf';
        expect(TransmissionMailer::isTemporaryPath($simulatedFile, $simulatedBase))->toBeTrue();
    }

    @rmdir($realTargetDir);
    @rmdir($fixtureRoot);
});

it('handles missing files safely without errors', function () {
    $nonExistent = sys_get_temp_dir().DIRECTORY_SEPARATOR.'does_not_exist_'.uniqid().'.pdf';

    expect(TransmissionMailer::cleanupTemporaryAttachment($nonExistent))->toBeFalse();
});

it('protects files outside the temporary directory from deletion', function () {
    $nonTempFile = base_path('tests'.DIRECTORY_SEPARATOR.'test_outside_'.uniqid().'.txt');
    file_put_contents($nonTempFile, 'important content outside temp');

    try {
        expect(TransmissionMailer::isTemporaryPath($nonTempFile))->toBeFalse();

        $deleted = TransmissionMailer::cleanupTemporaryAttachment($nonTempFile);
        expect($deleted)->toBeFalse()
            ->and(file_exists($nonTempFile))->toBeTrue();
    } finally {
        @unlink($nonTempFile);
    }
});

it('ensures temporary attachments are cleaned up in finally block even when mail sending fails', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'aureus-fail-');
    file_put_contents($tempFile, 'temporary mail attachment');
    expect(file_exists($tempFile))->toBeTrue();

    Mail::shouldReceive('to->send')
        ->once()
        ->andThrow(new RuntimeException('SMTP connection timed out'));

    $transmission = new OutboundTransmission;
    $transmission->id = 99999;
    $transmission->recipient_email = 'customer@example.com';

    $mailer = new TransmissionMailer(app(DocumentService::class));

    $reflection = new ReflectionClass($mailer);
    $dispatchMethod = $reflection->getMethod('dispatch');
    $dispatchMethod->setAccessible(true);

    $result = $dispatchMethod->invoke(
        $mailer,
        $transmission,
        'https://example.com/claim/123',
        'Invoice subject',
        'Invoice headline',
        'Sender Name',
        $tempFile,
        'invoice.pdf'
    );

    expect($result)->toBeFalse()
        ->and(file_exists($tempFile))->toBeFalse();
});
