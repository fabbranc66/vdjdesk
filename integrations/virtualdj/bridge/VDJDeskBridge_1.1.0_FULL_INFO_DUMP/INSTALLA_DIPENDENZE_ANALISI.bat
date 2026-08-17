@echo off
setlocal
chcp 65001 >nul
title VDJDesk 0.4.0 - Dipendenze DROP / BREAK

echo.
echo ============================================================
echo   VDJDesk 0.4.0 - Motore MIR DROP / BREAK
echo ============================================================
echo.
echo Installa solo le librerie necessarie:
echo   - NumPy
echo   - SciPy
echo   - librosa
echo   - soundfile
echo.
echo PyTorch / Transformers NON sono piu' necessari.
echo.

set "PY="

where py >nul 2>&1
if not errorlevel 1 (
    set "PY=py -3"
    goto install
)

where python >nul 2>&1
if not errorlevel 1 (
    set "PY=python"
    goto install
)

echo ERRORE: Python non trovato.
goto end

:install
%PY% -m pip install --upgrade pip
if errorlevel 1 goto error

%PY% -m pip install --upgrade "numpy>=1.24,<2.5" scipy librosa soundfile
if errorlevel 1 goto error

echo.
echo Verifica:
%PY% -c "import numpy, scipy, librosa; print('numpy',numpy.__version__); print('scipy',scipy.__version__); print('librosa',librosa.__version__)"

echo.
echo Verifica FFmpeg / FFprobe...
where ffmpeg
if errorlevel 1 echo ERRORE: ffmpeg non trovato nel PATH.
where ffprobe
if errorlevel 1 echo ERRORE: ffprobe non trovato nel PATH.

echo.
echo INSTALLAZIONE COMPLETATA.
goto end

:error
echo.
echo ERRORE durante installazione delle librerie.

:end
echo.
pause
endlocal
