@echo off
setlocal
chcp 65001 >nul
title VDJDesk - Installa resampy

echo.
echo Installazione dipendenza mancante: resampy
echo.

where py >nul 2>&1
if not errorlevel 1 (
    py -3 -m pip install --upgrade resampy
    if errorlevel 1 goto error
    py -3 -c "import resampy; print('resampy', resampy.__version__); print('OK')"
    goto end
)

where python >nul 2>&1
if not errorlevel 1 (
    python -m pip install --upgrade resampy
    if errorlevel 1 goto error
    python -c "import resampy; print('resampy', resampy.__version__); print('OK')"
    goto end
)

echo ERRORE: Python non trovato.
goto end

:error
echo ERRORE durante installazione resampy.

:end
echo.
pause
endlocal
