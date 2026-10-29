{{-- "Aureus" home-screen app: manifest, theme colour, iOS tags and the service worker (PanelsRenderHook::HEAD_END). --}}
@php($pwaTheme = \Webkul\Employee\Support\Pwa::themeColor())
<link rel="manifest" href="{{ route('employees.pwa.manifest') }}">
<meta name="theme-color" content="{{ $pwaTheme }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ \Webkul\Employee\Support\Pwa::NAME }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset(\Webkul\Employee\Support\Pwa::ICON_DIRECTORY.'/apple-touch-icon.png') }}">
<script>
    (function () {
        // Kept for the "Install app" button on the Check in page (the event fires once, early).
        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            window.__aureusInstallPrompt = event;
            window.dispatchEvent(new CustomEvent('aureus-installable'));
        });
        window.addEventListener('appinstalled', function () {
            window.__aureusInstallPrompt = null;
            window.dispatchEvent(new CustomEvent('aureus-installed'));
        });
        if ('serviceWorker' in navigator && window.isSecureContext) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register(@js(asset('sw.js'))).catch(function () {});
            });
        }
    })();
</script>
