@echo off
setlocal enableextensions enabledelayedexpansion
title Aureus ERP - Artisan Server

REM ============================================================
REM  Aureus ERP - built-in PHP server (Laragon-independent fallback)
REM  Serves the app at http://127.0.0.1:8000 with `php artisan serve`.
REM  Does NOT run composer/npm install or migrations.
REM ============================================================

cd /d "%~dp0"

echo ============================================================
echo   Aureus ERP - Built-in PHP Server
echo   Directory: %CD%
echo ============================================================
echo.

if not exist "vendor\autoload.php" (
    echo [ERROR] vendor\autoload.php not found - PHP dependencies are missing.
    echo         One-time fix:   composer install
    goto :fail
)

if not exist ".env" (
    echo [ERROR] .env not found.
    echo         One-time fix:   copy .env.example .env  ^&^&  php artisan key:generate
    goto :fail
)

set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP (
    for /d %%D in ("C:\laragon\bin\php\php-*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
)
if not defined PHP (
    echo [ERROR] Could not find php.exe on PATH or under C:\laragon\bin\php.
    goto :fail
)
echo Using PHP: !PHP!
echo.

echo Clearing stale Laravel caches...
"!PHP!" artisan optimize:clear
echo.

REM Rebuild the speed caches (Filament components and icons, routes, events,
REM views). Config is deliberately NOT cached: a cached config makes tests
REM ignore phpunit.xml and run against the dev database.
echo Building speed caches...
"!PHP!" artisan filament:optimize
"!PHP!" artisan route:cache
"!PHP!" artisan event:cache
"!PHP!" artisan view:cache
echo.

if not exist "public\build\manifest.json" (
    echo [WARN] public\build\manifest.json missing - the UI may be unstyled.
    echo        One-time fix:   npm install  ^&^&  npm run build
    echo.
)

echo Starting the PHP development server:
echo     "!PHP!" artisan serve --host=127.0.0.1 --port=8000
echo Opening: http://127.0.0.1:8000/admin/login
echo (Press Ctrl+C to stop the server.)
echo.
start "" "http://127.0.0.1:8000/admin/login"
"!PHP!" artisan serve --host=127.0.0.1 --port=8000

echo.
pause
exit /b 0

:fail
echo.
echo Launch aborted. Fix the item marked [ERROR] above, then run again.
echo.
pause
exit /b 1
