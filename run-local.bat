@echo off
setlocal enableextensions enabledelayedexpansion
title Aureus ERP - Local Launcher

REM ============================================================
REM  Aureus ERP - one-click local launcher
REM  Prefers Apache on http://127.0.0.1:8080, falls back to `artisan serve` on :8000.
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
REM Rebuild the speed caches. Config is cached too: phpunit.xml points tests at a
REM separate (absent) config cache, so they still use the testing database.
REM After editing .env, run this script again (or: php artisan config:cache).
"!PHP!" artisan config:cache
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

REM 7) Prefer Apache (Laragon vhost aureus-erp.conf, http://127.0.0.1:8080) when it answers 200.
REM    Same host as the fallback below, so both share one session cookie; the .test vhost is gone.
set "APP_LOGIN=http://127.0.0.1:8080/admin/login"
echo [4/5] Probing Apache at !APP_LOGIN! ...
call :probe "!APP_LOGIN!"

if "!CODE!"=="200" (
    echo        Apache is serving the app ^(HTTP 200^).
    echo.
    echo [5/5] Opening !APP_LOGIN!
    start "" "!APP_LOGIN!"
    echo.
    echo ============================================================
    echo   Aureus ERP is live via Apache:
    echo       !APP_LOGIN!
    echo ============================================================
    echo   You can close this window.
    goto :done
)

echo        Apache did not answer 200 ^(got HTTP !CODE!^).
echo        Start Laragon ^(Apache + MySQL^) to use http://127.0.0.1:8080.
echo.
REM Fallback: start the built-in server FIRST, wait until it answers, THEN open the browser
REM (opening it first showed a "can't connect" page that needed a reload and a second login).
set "APP_LOGIN=http://127.0.0.1:8000/admin/login"
echo [5/5] Falling back to the built-in PHP server...
echo        URL:  !APP_LOGIN!
echo        (Close the "Aureus ERP - artisan serve" window to stop the server.)
echo.
start "Aureus ERP - artisan serve" "!PHP!" artisan serve --host=127.0.0.1 --port=8000
set /a TRIES=0
:wait_serve
set /a TRIES+=1
call :probe "!APP_LOGIN!"
if "!CODE!"=="200" goto :serve_ready
if !TRIES! GEQ 30 (
    echo [WARN] The server did not answer within 30 seconds - opening the browser anyway.
    goto :serve_ready
)
timeout /t 1 /nobreak >nul
goto :wait_serve

:serve_ready
start "" "!APP_LOGIN!"
echo        Server is up; browser opened.
goto :done

REM Sets CODE to the HTTP status of %~1 (000 when nothing answers).
:probe
set "CODE=000"
curl.exe -s -o nul -w "%%{http_code}" "%~1" > "%TEMP%\erp_http.txt" 2>nul
if exist "%TEMP%\erp_http.txt" set /p CODE=<"%TEMP%\erp_http.txt"
del "%TEMP%\erp_http.txt" >nul 2>nul
exit /b 0

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
