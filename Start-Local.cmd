@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-Local.ps1"
if errorlevel 1 (
    echo.
    echo Local startup failed. Review the error above.
    pause
    exit /b 1
)
