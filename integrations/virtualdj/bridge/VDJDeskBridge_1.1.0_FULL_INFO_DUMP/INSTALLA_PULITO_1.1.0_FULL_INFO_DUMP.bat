@echo off
setlocal
chcp 65001 >nul
title VDJDesk Bridge 1.1.0 - Installazione integrata

set "ROOT=%~dp0"
set "DLL=%ROOT%x64\Release\VDJDeskBridgeManual.dll"
set "ANALYZER=%ROOT%Analyzer\VDJDesk_AutoVocalCue.py"

set "VDJ=%LOCALAPPDATA%\VirtualDJ"
set "PLUGINS=%VDJ%\Plugins64\SoundEffect"
set "TARGETDLL=%PLUGINS%\VDJDeskBridgeManual.dll"
set "TARGETROOT=%PLUGINS%\VDJDeskBridge"
set "TARGETAN=%TARGETROOT%\Analyzer"

echo.
echo ============================================================
echo   VDJDesk Bridge 1.1.0 - INTEGRATED
echo ============================================================
echo.
echo Chiudi completamente VirtualDJ.
echo.
pause

if not exist "%DLL%" (
    echo ERRORE: DLL non compilata:
    echo %DLL%
    echo.
    echo Compila Release ^| x64.
    pause
    exit /b 1
)

if not exist "%ANALYZER%" (
    echo ERRORE: analizzatore non trovato:
    echo %ANALYZER%
    pause
    exit /b 1
)

if exist "%TARGETDLL%" del /F /Q "%TARGETDLL%"
if exist "%TARGETROOT%" rmdir /S /Q "%TARGETROOT%"

if not exist "%PLUGINS%" mkdir "%PLUGINS%"
if not exist "%TARGETAN%" mkdir "%TARGETAN%"

copy /Y "%DLL%" "%TARGETDLL%" >nul
copy /Y "%ANALYZER%" "%TARGETAN%\VDJDesk_AutoVocalCue.py" >nul

if errorlevel 1 (
    echo ERRORE durante installazione.
    pause
    exit /b 1
)

echo.
echo Installato:
echo   %TARGETDLL%
echo   %TARGETAN%\VDJDesk_AutoVocalCue.py
echo.
echo Tutto e' nella cartella plugin VirtualDJ.
echo.
echo Funzionamento:
echo   plugin OFF = tutto fermo
echo   plugin ON  = bridge + analizzatore avviati
echo   carichi brano = trova stem, analizza, crea cue
echo.
pause
endlocal
