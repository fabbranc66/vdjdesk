@echo off
setlocal
chcp 65001 >nul
set "P=%LOCALAPPDATA%\VirtualDJ\VDJDesk_AutoVocalCue.log"

if not exist "%P%" (
    echo Log analisi non ancora creato.
    pause
    exit /b 1
)

powershell -NoProfile -Command "Get-Content -LiteralPath '%P%' -Encoding UTF8 -Tail 100"
echo.
pause
endlocal
