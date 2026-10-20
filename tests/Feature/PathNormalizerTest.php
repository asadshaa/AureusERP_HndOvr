<?php

use Webkul\PluginManager\Support\PathNormalizer;

it('normalizes forward-slash paths correctly', function () {
    $base = '/var/www/aureus';
    $full = '/var/www/aureus/plugins/webkul/accounting/src/../database/migrations/2026_09_17_000001_create_table.php';

    $relative = PathNormalizer::toRelativePath($full, $base);

    expect($relative)->toBe('plugins/webkul/accounting/database/migrations/2026_09_17_000001_create_table.php');
});

it('normalizes backslash-containing paths correctly', function () {
    $base = 'C:\laragon\www\aureus';
    $full = 'C:\laragon\www\aureus\plugins\webkul\accounting\src\..\database\migrations\2026_09_17_000001_create_table.php';

    $relative = PathNormalizer::toRelativePath($full, $base);

    expect($relative)->toBe('plugins/webkul/accounting/database/migrations/2026_09_17_000001_create_table.php');
});

it('normalizes mixed separator paths correctly', function () {
    $base = 'C:/laragon/www/aureus';
    $full = 'C:\laragon/www\aureus/plugins\webkul/accounting\src/../database\migrations/2026_09_17_000001_create_table.php';

    $relative = PathNormalizer::toRelativePath($full, $base);

    expect($relative)->toBe('plugins/webkul/accounting/database/migrations/2026_09_17_000001_create_table.php');
});

it('strictly rejects paths outside the application base directory', function () {
    $base = '/var/www/aureus';
    $outside = '/var/www/other_app/database/migrations/2026_09_17_000001_create_table.php';

    expect(fn () => PathNormalizer::toRelativePath($outside, $base))
        ->toThrow(InvalidArgumentException::class, 'traverses outside base directory');
});

it('strictly rejects directory traversal attempting to escape the base directory', function () {
    $base = 'C:\laragon\www\aureus';
    $traversal = 'C:\laragon\www\aureus\plugins\webkul\..\..\..\..\windows\system32\cmd.exe';

    expect(fn () => PathNormalizer::toRelativePath($traversal, $base))
        ->toThrow(InvalidArgumentException::class);
});

it('supports Windows drive letters case-insensitively', function () {
    $base = 'c:\laragon\www\aureus';
    $full = 'C:\laragon\www\aureus\plugins\webkul\accounting\database\migrations\test.php';

    $relative = PathNormalizer::toRelativePath($full, $base);

    expect($relative)->toBe('plugins/webkul/accounting/database/migrations/test.php');
});

it('supports UNC network paths safely', function () {
    $base = '\\\\network-share\\erp\\aureus';
    $full = '\\\\network-share\\erp\\aureus\\plugins\\webkul\\accounting\\src\\..\\database\\migrations\\unc_test.php';

    $relative = PathNormalizer::toRelativePath($full, $base);

    expect($relative)->toBe('plugins/webkul/accounting/database/migrations/unc_test.php');
});

it('normalizes internal package paths without touching URLs or non-path strings', function () {
    $path = 'plugins/webkul/accounting/src/../database/migrations/test.php';
    expect(PathNormalizer::normalizePath($path))->toBe('plugins/webkul/accounting/database/migrations/test.php');
});
