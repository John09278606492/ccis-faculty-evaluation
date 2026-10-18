@echo off
setlocal
REM Double-click to update, build and open the app on this PC for testing.
REM Needs PHP, Composer and Node.js on PATH, and MySQL running (start it in Laragon first).

cd /d "%~dp0"
title CCIS - test locally
set "ARTISAN=php -d opcache.enable_cli=0 artisan"

echo.
echo [1/6] Installing PHP packages...
call composer install --no-interaction
if errorlevel 1 goto fail

echo.
echo [2/6] Installing Node packages...
call npm install --no-audit --no-fund
if errorlevel 1 goto fail

echo.
echo [3/6] Building the code runners and page assets...
call npm run build
if errorlevel 1 goto fail

echo.
echo [4/6] Updating the database...
%ARTISAN% migrate --force
if errorlevel 1 goto fail

echo.
echo [5/6] Updating role permissions...
%ARTISAN% db:seed --class=RolesSeeder --force
if errorlevel 1 goto fail
%ARTISAN% optimize:clear >nul

echo.
echo [6/6] Starting the app at http://127.0.0.1:8000
echo       Keep this window open while testing. Close it to stop the app.
echo.
start "" cmd /c "timeout /t 4 >nul & start http://127.0.0.1:8000"
set PHP_CLI_SERVER_WORKERS=4
%ARTISAN% serve --host=127.0.0.1 --port=8000
goto end

:fail
echo.
echo Something failed above. Check that MySQL is running in Laragon, then run this file again.
pause

:end
endlocal
