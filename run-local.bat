@echo off
setlocal enableextensions enabledelayedexpansion
title Aureus ERP - Local Launcher

REM ============================================================
REM  Aureus ERP - one-click local launcher
REM  Prefers the Laragon virtual host, falls back to `artisan serve`.
REM  Does NOT run composer/npm install or migrations. It only detects
REM  missing pieces and tells you the one-time command to run.
REM ============================================================

REM 1) Move into the repository directory (folder of this script)
cd /d "%~dp0"

echo ============================================================
echo   Aureus ERP - Local Launcher
echo   Directory: %CD%
echo ============================================================
echo.

REM 2) Verify PHP dependencies are installed
if not exist "vendor\autoload.php" (
    echo [ERROR] vendor\autoload.php not found - PHP dependencies are missing.
    echo         One-time fix:   composer install
    goto :fail
)

REM 3) Verify environment file
if not exist ".env" (
    echo [ERROR] .env not found.
    echo         One-time fix:   copy .env.example .env  ^&^&  php artisan key:generate
    goto :fail
)

REM Locate a PHP binary: PATH first, then Laragon's bundled PHP.
set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP (
    for /d %%D in ("C:\laragon\bin\php\php-*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
)
if not defined PHP (
    echo [ERROR] Could not find php.exe on PATH or under C:\laragon\bin\php.
    echo         Open this from the Laragon Terminal, or add PHP to PATH.
    goto :fail
)
echo Using PHP: !PHP!
echo.

REM 4) Clear stale Laravel caches (safe, non-destructive)
echo [1/5] Clearing stale Laravel caches...
"!PHP!" artisan optimize:clear
if errorlevel 1 (
    echo [ERROR] Cache clear failed - see the message above.
    goto :fail
)
REM Rebuild the speed caches (config deliberately not cached: tests would hit the dev DB).
"!PHP!" artisan filament:optimize
"!PHP!" artisan route:cache
"!PHP!" artisan event:cache
"!PHP!" artisan view:cache
echo.

REM 5) Check the database is reachable WITHOUT modifying it
echo [2/5] Checking database connectivity (read-only)...
"!PHP!" artisan db:show >nul 2>nul
if errorlevel 1 (
    echo [WARN] Could not reach the database.
    echo        Start MySQL in Laragon. The panel may open but DB pages will error.
) else (
    echo        Database reachable.
)
echo.

REM 6) Check the frontend build exists
echo [3/5] Checking frontend build assets...
if not exist "public\build\manifest.json" (
    echo [WARN] public\build\manifest.json missing - the UI may be unstyled.
    echo        One-time fix:   npm install  ^&^&  npm run build
) else (
    echo        Frontend assets present.
)
echo.

REM 7) Prefer the Laragon URL when it actually answers 200
echo [4/5] Probing the Laragon URL...
set "CODE=000"
curl.exe -s -o nul -w "%%{http_code}" http://aureuserp-master.test/admin/login > "%TEMP%\erp_http.txt" 2>nul
if exist "%TEMP%\erp_http.txt" set /p CODE=<"%TEMP%\erp_http.txt"
del "%TEMP%\erp_http.txt" >nul 2>nul

if "!CODE!"=="200" (
    echo        Laragon is serving the app ^(HTTP 200^).
    echo.
    echo [5/5] Opening http://aureuserp-master.test/admin/login
    start "" "http://aureuserp-master.test/admin/login"
    echo.
    echo ============================================================
    echo   Aureus ERP is live via Laragon:
    echo       http://aureuserp-master.test/admin/login
    echo ============================================================
    echo   You can close this window.
    goto :done
)

echo        Laragon URL did not answer 200 ^(got HTTP !CODE!^).
echo        Make sure Laragon (Apache + MySQL) is started for the .test URL.
echo.
echo [5/5] Falling back to the built-in PHP server...
echo        URL:  http://127.0.0.1:8000/admin/login
echo        (Press Ctrl+C in this window to stop the server.)
echo.
start "" "http://127.0.0.1:8000/admin/login"
"!PHP!" artisan serve --host=127.0.0.1 --port=8000
goto :done

:fail
echo.
echo ------------------------------------------------------------
echo  Launch aborted. Fix the item marked [ERROR] above, then
echo  double-click run-local.bat again.
echo ------------------------------------------------------------
echo.
pause
exit /b 1

:done
echo.
pause
exit /b 0
