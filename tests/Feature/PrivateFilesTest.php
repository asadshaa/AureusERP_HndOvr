<?php

/**
 * Sensitive uploads (chatter attachments, work permits, request receipts)
 * live on the private disk and are only reachable through short-lived signed
 * links; files left on the public disk by older code are moved over.
 */

use Illuminate\Support\Facades\Storage;
use Webkul\Chatter\Models\Attachment;

it('stores chatter attachments on the private disk and links them with an expiring signed URL', function () {
    $path = 'chats-attachments/test-'.uniqid().'.pdf';
    Storage::disk(Attachment::DISK)->put($path, 'secret');

    try {
        $url = (new Attachment(['file_path' => $path]))->url;

        expect(Attachment::DISK)->toBe('local')
            ->and($url)->toContain('signature=')
            ->and($url)->toContain('expires=');

        // The signed link serves the file; the same path without a signature is refused.
        test()->get($url)->assertOk();
        test()->get(strtok($url, '?'))->assertForbidden();
    } finally {
        Storage::disk(Attachment::DISK)->delete($path);
    }
});

it('moves files from the public disk to the private disk, keeping their paths, and is safe to re-run', function () {
    Storage::fake('public');
    Storage::fake('local');
    Storage::disk('public')->put('employees/work-permit/permit.pdf', 'permit');
    Storage::disk('public')->put('messages-attachments/receipt.png', 'receipt');
    Storage::disk('public')->put('avatars/me.png', 'avatar');

    test()->artisan('security:move-private-files')->assertSuccessful();

    expect(Storage::disk('local')->get('employees/work-permit/permit.pdf'))->toBe('permit')
        ->and(Storage::disk('local')->exists('messages-attachments/receipt.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('employees/work-permit/permit.pdf'))->toBeFalse()
        ->and(Storage::disk('public')->exists('avatars/me.png'))->toBeTrue();

    test()->artisan('security:move-private-files')->assertSuccessful()->expectsOutputToContain('Moved 0 file(s)');
});
