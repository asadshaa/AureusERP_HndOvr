<?php

use Google\Service\Drive as DriveService;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Resource\Files as FilesResource;
use Webkul\Accounting\Services\Drive\GoogleDriveClient;

function createTestDriveFile(string $id, string $name, string $mimeType = 'application/pdf', int $size = 1234, ?string $modifiedTime = '2026-10-16T10:00:00Z'): DriveFile
{
    $file = new DriveFile;
    $file->setId($id);
    $file->setName($name);
    $file->setMimeType($mimeType);
    $file->setSize($size);
    $file->setModifiedTime($modifiedTime);

    return $file;
}

function createTestFileList(array $files, ?string $nextPageToken = null): FileList
{
    $list = new FileList;
    $list->setFiles($files);
    $list->setNextPageToken($nextPageToken);

    return $list;
}

it('retrieves files from a single page when no next page token exists', function () {
    $service = Mockery::mock(DriveService::class);
    $filesResource = Mockery::mock(FilesResource::class);
    $service->files = $filesResource;

    $file1 = createTestDriveFile('file-1', 'invoice-1.pdf');
    $file2 = createTestDriveFile('file-2', 'bill-2.pdf');

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(function ($params) {
            return $params['q'] === "'folder-123' in parents and mimeType!='application/vnd.google-apps.folder' and trashed=false"
                && str_contains($params['fields'], 'nextPageToken')
                && ! isset($params['pageToken']);
        }))
        ->andReturn(createTestFileList([$file1, $file2], null));

    $client = new GoogleDriveClient($service);
    $files = $client->listFiles('folder-123');

    expect($files)->toHaveCount(2)
        ->and($files[0]['id'])->toBe('file-1')
        ->and($files[0]['name'])->toBe('invoice-1.pdf')
        ->and($files[1]['id'])->toBe('file-2')
        ->and($files[1]['name'])->toBe('bill-2.pdf');
});

it('paginates across multiple pages until no next page token remains', function () {
    $service = Mockery::mock(DriveService::class);
    $filesResource = Mockery::mock(FilesResource::class);
    $service->files = $filesResource;

    $file1 = createTestDriveFile('file-1', 'doc-1.pdf');
    $file2 = createTestDriveFile('file-2', 'doc-2.pdf');
    $file3 = createTestDriveFile('file-3', 'doc-3.pdf');

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => ! isset($params['pageToken'])))
        ->andReturn(createTestFileList([$file1], 'page-2-token'));

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => isset($params['pageToken']) && $params['pageToken'] === 'page-2-token'))
        ->andReturn(createTestFileList([$file2], 'page-3-token'));

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => isset($params['pageToken']) && $params['pageToken'] === 'page-3-token'))
        ->andReturn(createTestFileList([$file3], null));

    $client = new GoogleDriveClient($service);
    $files = $client->listFiles('folder-abc');

    expect($files)->toHaveCount(3)
        ->and(array_column($files, 'id'))->toBe(['file-1', 'file-2', 'file-3']);
});

it('handles an empty first page with a next page token', function () {
    $service = Mockery::mock(DriveService::class);
    $filesResource = Mockery::mock(FilesResource::class);
    $service->files = $filesResource;

    $file1 = createTestDriveFile('file-real', 'actual.pdf');

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => ! isset($params['pageToken'])))
        ->andReturn(createTestFileList([], 'token-next'));

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => isset($params['pageToken']) && $params['pageToken'] === 'token-next'))
        ->andReturn(createTestFileList([$file1], null));

    $client = new GoogleDriveClient($service);
    $files = $client->listFiles('folder-empty-first');

    expect($files)->toHaveCount(1)
        ->and($files[0]['id'])->toBe('file-real');
});

it('propagates API exceptions if a failure occurs during a later page', function () {
    $service = Mockery::mock(DriveService::class);
    $filesResource = Mockery::mock(FilesResource::class);
    $service->files = $filesResource;

    $file1 = createTestDriveFile('file-1', 'doc-1.pdf');

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => ! isset($params['pageToken'])))
        ->andReturn(createTestFileList([$file1], 'failing-token'));

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => isset($params['pageToken']) && $params['pageToken'] === 'failing-token'))
        ->andThrow(new RuntimeException('Drive API connection reset'));

    $client = new GoogleDriveClient($service);

    expect(fn () => $client->listFiles('folder-error'))
        ->toThrow(RuntimeException::class, 'Drive API connection reset');
});

it('deduplicates file IDs if the API returns duplicates across pages', function () {
    $service = Mockery::mock(DriveService::class);
    $filesResource = Mockery::mock(FilesResource::class);
    $service->files = $filesResource;

    $fileA = createTestDriveFile('file-a', 'alpha.pdf');
    $fileB = createTestDriveFile('file-b', 'beta.pdf');
    $fileBDuplicate = createTestDriveFile('file-b', 'beta-duplicate.pdf');
    $fileC = createTestDriveFile('file-c', 'gamma.pdf');

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => ! isset($params['pageToken'])))
        ->andReturn(createTestFileList([$fileA, $fileB], 'page-2'));

    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => isset($params['pageToken']) && $params['pageToken'] === 'page-2'))
        ->andReturn(createTestFileList([$fileBDuplicate, $fileC], null));

    $client = new GoogleDriveClient($service);
    $files = $client->listFiles('folder-dedup');

    expect($files)->toHaveCount(3)
        ->and(array_column($files, 'id'))->toBe(['file-a', 'file-b', 'file-c']);
});

it('avoids infinite loops if the API returns a repeated next page token', function () {
    $service = Mockery::mock(DriveService::class);
    $filesResource = Mockery::mock(FilesResource::class);
    $service->files = $filesResource;

    $file1 = createTestDriveFile('file-1', 'first.pdf');
    $file2 = createTestDriveFile('file-2', 'second.pdf');

    // Page 1 returns token 'repeat-loop'
    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => ! isset($params['pageToken'])))
        ->andReturn(createTestFileList([$file1], 'repeat-loop'));

    // Page 2 returns the SAME token 'repeat-loop' (bad API behavior)
    $filesResource->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(fn ($params) => isset($params['pageToken']) && $params['pageToken'] === 'repeat-loop'))
        ->andReturn(createTestFileList([$file2], 'repeat-loop'));

    $client = new GoogleDriveClient($service);
    $files = $client->listFiles('folder-loop');

    // Should break out and not loop indefinitely
    expect($files)->toHaveCount(2)
        ->and(array_column($files, 'id'))->toBe(['file-1', 'file-2']);
});
