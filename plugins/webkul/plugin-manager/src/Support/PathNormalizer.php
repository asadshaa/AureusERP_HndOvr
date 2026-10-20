<?php

namespace Webkul\PluginManager\Support;

use InvalidArgumentException;

class PathNormalizer
{
    /**
     * Normalize a path by resolving separators and '.' / '..' segments.
     */
    public static function normalizePath(string $path): string
    {
        if (trim($path) === '') {
            return '';
        }

        $isUnc = str_starts_with($path, '\\\\') || str_starts_with($path, '//');
        $normalized = str_replace('\\', '/', $path);

        $prefix = '';
        if ($isUnc) {
            $prefix = '//';
            $normalized = substr($normalized, 2);
        } elseif (preg_match('/^[a-zA-Z]:\//', $normalized)) {
            $prefix = substr($normalized, 0, 3);
            $normalized = substr($normalized, 3);
        } elseif (str_starts_with($normalized, '/')) {
            $prefix = '/';
            $normalized = substr($normalized, 1);
        }

        $parts = explode('/', $normalized);
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (! empty($safeParts)) {
                    array_pop($safeParts);
                } else {
                    throw new InvalidArgumentException("Path traverses above the root boundary: {$path}");
                }
            } else {
                $safeParts[] = $part;
            }
        }

        return $prefix.implode('/', $safeParts);
    }

    /**
     * Resolve a path relative to a base directory, preventing directory traversal outside the base.
     *
     * @return string The normalized path relative to base directory (using forward slashes).
     *
     * @throws InvalidArgumentException If the path resolves outside the base directory.
     */
    public static function toRelativePath(string $fullPath, ?string $baseDir = null): string
    {
        $base = $baseDir ?? base_path();

        $normalizedBase = static::normalizePath($base);
        $normalizedFull = static::normalizePath($fullPath);

        $isWindows = PHP_OS_FAMILY === 'Windows' || preg_match('/^[a-zA-Z]:/', $normalizedBase);

        $compareBase = rtrim($isWindows ? strtolower($normalizedBase) : $normalizedBase, '/').'/';
        $compareFull = $isWindows ? strtolower($normalizedFull) : $normalizedFull;

        if ($compareFull === rtrim($compareBase, '/')) {
            return '';
        }

        if (! str_starts_with($compareFull, $compareBase)) {
            throw new InvalidArgumentException("Path [{$fullPath}] traverses outside base directory [{$base}].");
        }

        return substr($normalizedFull, strlen(rtrim($normalizedBase, '/')) + 1);
    }
}
