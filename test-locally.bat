@echo off
setlocal
REM Double-click to update, build and open the app on this PC for testing.
REM Needs PHP 8.3+ (found automatically) and MySQL running (start it in Laragon first).

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
set "STATE="%PHP_EXE%" scripts\local-test-state.php"
REM Unpacking big packages into this long folder path can take over Composer's 5-minute limit.
set COMPOSER_PROCESS_TIMEOUT=0
REM Without Git's unzip.exe on PATH, Composer unpacks with PHP's zip extension, which is much faster here.
set "PATH=%PATH:D:\laragon\bin\git\usr\bin;=%"
set "PATH=%PATH:C:\laragon\bin\git\usr\bin;=%"
set "PATH=%PATH:C:\Program Files\Git\usr\bin;=%"

REM Each setup step is skipped when nothing it depends on changed since it last succeeded.
echo.
%STATE% check composer && (echo [1/6] PHP packages are up to date.) || (
    echo [1/6] Installing PHP packages... the first time can take several minutes.
    call composer install --no-interaction --no-progress || goto fail
    %STATE% save composer
)

REM The page assets and code runners come ready-built with the project, so Node.js is only needed if they are missing.
if exist "public\build\manifest.json" if exist "public\code-runner\php-worker.js" (
    echo [2/6] Page assets and code runners are ready.
    goto assets_ready
)
echo [2/6] Page assets are missing, building them with Node.js...
call npm install --no-audit --no-fund || goto fail
echo       Building the code runners and page assets...
call npm run build || goto fail
:assets_ready
%ARTISAN% package:discover >nul || goto fail
echo [3/6] Packages registered.

echo [4/6] Updating the database...
%ARTISAN% migrate --force || goto fail

%STATE% check roles && (echo [5/6] Role permissions are up to date.) || (
    echo [5/6] Updating role permissions...
    %ARTISAN% db:seed --class=RolesSeeder --force || goto fail
    %STATE% save roles
)
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
