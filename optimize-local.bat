@echo off
setlocal

cd /d "%~dp0"

echo [1/4] Optimizing Composer autoload files...
call composer dump-autoload --optimize --classmap-authoritative
if errorlevel 1 goto :failed

echo [2/4] Clearing stale Laravel caches...
call php artisan optimize:clear
if errorlevel 1 goto :failed

echo [3/4] Building fresh Laravel caches...
call php artisan optimize
if errorlevel 1 goto :failed

echo [4/4] Building production frontend assets...
call npm.cmd run build
if errorlevel 1 goto :failed

echo.
echo Local optimization completed successfully.
exit /b 0

:failed
set "OPTIMIZE_EXIT_CODE=%errorlevel%"
echo.
echo Local optimization stopped because a command failed.
exit /b %OPTIMIZE_EXIT_CODE%
