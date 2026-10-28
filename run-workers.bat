@echo off
setlocal enableextensions
title Aureus ERP - Background workers

REM ============================================================
REM  Aureus ERP - background workers (one click)
REM  Opens two windows that must stay open while Aureus is used:
REM    1) Scheduler : careers-site pull every 5 min, interview
REM                   reminders, posting reminders, attendance jobs
REM    2) Queue     : emails, CV analysis, notifications
REM  Close a window (or press Ctrl+C in it) to stop that worker.
REM ============================================================

cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo PHP was not found on PATH. Start Laragon or add PHP to PATH, then run this again.
    pause
    exit /b 1
)

start "Aureus scheduler" cmd /k "cd /d "%~dp0" && php artisan schedule:work"
start "Aureus queue" cmd /k "cd /d "%~dp0" && php artisan queue:work --tries=3 --timeout=120"

echo Started the scheduler and the queue worker in two new windows.
echo Keep both windows open while Aureus is in use.
timeout /t 5 >nul
