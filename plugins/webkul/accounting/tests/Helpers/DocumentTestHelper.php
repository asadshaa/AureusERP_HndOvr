<?php

use Illuminate\Http\UploadedFile;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;

if (! function_exists('documentTestUser')) {
    function documentTestUser(?Company $company = null, array $permissions = []): User
    {
        $company ??= Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
        $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user->refresh();
    }
}

if (! function_exists('fakeUploadedFileWithRealContent')) {
    /**
     * UploadedFile::fake()->create($name, $kilobytes) fakes the reported
     * size but writes an EMPTY physical temp file -- fine for tests that
     * only check metadata (mime/size validation), useless for anything
     * that actually reads bytes back (checksum, tamper detection, version
     * history). This writes genuine content to a real temp file instead.
     *
     * DocumentService::validateFile() trusts real byte-sniffed detection
     * (Symfony's getMimeType(), backed by PHP's fileinfo extension) over
     * whatever mime type the caller merely labels the upload with -- so a
     * caller-supplied $mimeType of 'application/pdf' must actually START
     * WITH the real PDF magic bytes, or fileinfo will correctly call it
     * text/plain and DocumentService will just as correctly reject it.
     */
    function fakeUploadedFileWithRealContent(string $name, string $mimeType, ?string $contents = null): UploadedFile
    {
        $contents ??= 'Fake but real bytes for '.$name.' -- '.bin2hex(random_bytes(16));

        if ($mimeType === 'application/pdf' && ! str_starts_with($contents, '%PDF-')) {
            $contents = "%PDF-1.4\n".$contents;
        }

        $path = tempnam(sys_get_temp_dir(), 'doc-test-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $mimeType, null, true);
    }
}
