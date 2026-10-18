@echo off
setlocal
REM Double-click to update, build and open the app on this PC for testing.
REM Needs PHP 8.3+ (found automatically), Composer and Node.js, and MySQL running (start it in Laragon first).

cd /d "%~dp0"
title CCIS - test locally

REM The app needs PHP 8.3 or newer, which may not be the "php" on PATH.
REM Use CCIS_PHP if set, else the first suitable one in C:\php, Laragon, then PATH.
set "PHP_EXE="
if defined CCIS_PHP call :try_php "%CCIS_PHP%"
call :try_php "C:\php\php.exe"
for %%R in (C D) do for /d %%D in ("%%R:\laragon\bin\php\php-8.*") do call :try_php "%%D\php.exe"
for /f "delims=" %%P in ('where php 2^>nul') do call :try_php "%%P"
if not defined PHP_EXE (
    echo.
    echo PHP 8.3 or newer was not found. In Laragon, open Menu ^> PHP ^> Version and pick 8.3 or newer,
    echo or download PHP 8.3 to C:\php, then run this file again.
    goto fail_quiet
)
for %%F in ("%PHP_EXE%") do set "PATH=%%~dpF;%PATH%"
echo Using PHP:
"%PHP_EXE%" -r "echo '  ', PHP_VERSION, '  ', PHP_BINARY, PHP_EOL;"
set "ARTISAN="%PHP_EXE%" -d opcache.enable_cli=0 artisan"

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
:fail_quiet
pause
goto end

REM Keep the first PHP 8.3 or newer that is found.
:try_php
if defined PHP_EXE goto :eof
if not exist "%~1" goto :eof
"%~1" -r "exit(PHP_VERSION_ID >= 80300 ? 0 : 1);" >nul 2>&1 && set "PHP_EXE=%~1"
goto :eof

:end
endlocal
