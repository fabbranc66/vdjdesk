@echo off
setlocal
chcp 65001 >nul
title Ripara NumPy per VDJDesk

echo.
echo Ripristino NumPy compatibile con Numba...
echo.

where py >nul 2>&1
if not errorlevel 1 (
    py -3 -m pip install --force-reinstall "numpy>=1.22,<2.5"
    py -3 -c "import numpy; print('numpy', numpy.__version__); import numba; print('numba', numba.__version__)"
    goto end
)

where python >nul 2>&1
if not errorlevel 1 (
    python -m pip install --force-reinstall "numpy>=1.22,<2.5"
    python -c "import numpy; print('numpy', numpy.__version__); import numba; print('numba', numba.__version__)"
    goto end
)

echo ERRORE: Python non trovato.

:end
echo.
pause
endlocal
