@echo off
setlocal
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel%==0 (
  start "" http://127.0.0.1:8765
  py -3 server.py
  goto :eof
)
where python >nul 2>nul
if %errorlevel%==0 (
  start "" http://127.0.0.1:8765
  python server.py
  goto :eof
)
echo.
echo Python 3 is niet gevonden.
echo Installeer Python 3 en vink "Add Python to PATH" aan.
pause
