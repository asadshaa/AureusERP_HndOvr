<?php

namespace Webkul\Employee\Support;

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Throwable;
use Webkul\Employee\Filament\Pages\CheckIn;
use Webkul\Support\Settings\BrandSettings;

/**
 * The installable home-screen web app ("Aureus"): its manifest and theme
 * colour. The app opens on the phone Check in page; the service worker
 * (public/sw.js) only caches static assets and the offline page.
 */
final class Pwa
{
    public const NAME = 'Aureus';

    public const BACKGROUND = '#ffffff';

    /** Static icons, generated once from public/images/pwa/icon.svg. */
    public const ICON_DIRECTORY = 'images/pwa';

    /** The panel's primary colour (brand setting first, else the panel's primary 600) as #rrggbb. */
    public static function themeColor(): string
    {
        try {
            $brand = settings(BrandSettings::class)->primary_color ?? null;
            if (is_string($brand) && $brand !== '') {
                return Color::convertToHex($brand);
            }
        } catch (Throwable) {
            // Settings table missing (fresh install): fall back to the panel colour.
        }

        try {
            $primary = Filament::getPanel('admin')->getColors()['primary'] ?? null;
            $shade = is_array($primary) ? ($primary[600] ?? null) : $primary;
            if (is_string($shade) && $shade !== '') {
                return Color::convertToHex($shade);
            }
        } catch (Throwable) {
            //
        }

        return Color::convertToHex(Color::Blue[600]);
    }

    public static function startPath(): string
    {
        try {
            return parse_url(CheckIn::getUrl(panel: 'admin'), PHP_URL_PATH) ?: '/admin/check-in';
        } catch (Throwable) {
            return '/admin/check-in';
        }
    }

    /** Site-relative path of a public file (keeps working when the app lives in a sub-folder). */
    public static function assetPath(string $file): string
    {
        return parse_url(asset($file), PHP_URL_PATH) ?: '/'.ltrim($file, '/');
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $theme = self::themeColor();
        $start = self::startPath();
        $icon = fn (string $file): string => self::assetPath(self::ICON_DIRECTORY.'/'.$file);

        return [
            'id'               => $start,
            'name'             => self::NAME,
            'short_name'       => self::NAME,
            'description'      => 'Aureus ERP: check in and out from your phone.',
            'lang'             => 'en',
            'dir'              => 'ltr',
            'start_url'        => $start.'?source=pwa',
            'scope'            => rtrim(self::assetPath(''), '/').'/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => self::BACKGROUND,
            'theme_color'      => $theme,
            'categories'       => ['business', 'productivity'],
            'icons'            => [
                ['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => $icon('icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
            'shortcuts'        => [
                ['name' => 'Check in / out', 'short_name' => 'Check in', 'url' => $start.'?source=pwa-shortcut', 'icons' => [['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png']]],
            ],
        ];
    }
}
