@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-Campus.ps1"
if errorlevel 1 (
    echo.
    echo Campus startup failed. Review the error above and storage/logs/campus-error.log.
)
echo.
pause
