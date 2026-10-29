<?php

namespace Webkul\Employee\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Webkul\Employee\Support\Pwa;

/**
 * The web app manifest of the "Aureus" home-screen app. Public (browsers
 * fetch manifests without cookies) and holds no user data.
 */
class PwaManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(Pwa::manifest(), 200, [
                'Content-Type'  => 'application/manifest+json',
                'Cache-Control' => 'public, max-age=3600',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
