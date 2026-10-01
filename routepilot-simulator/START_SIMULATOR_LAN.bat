@echo off
setlocal EnableExtensions EnableDelayedExpansion
title RoutePilot Simulator - LAN

cd /d "%~dp0"

set "ROUTEPILOT_SIM_HOST=0.0.0.0"
set "ROUTEPILOT_SIM_PORT=8765"
set "TOKEN_FILE=%~dp0portal-token.txt"

rem Zoek Python.
set "PYTHON_CMD="
where py >nul 2>nul
if %errorlevel%==0 set "PYTHON_CMD=py -3"
if not defined PYTHON_CMD (
  where python >nul 2>nul
  if %errorlevel%==0 set "PYTHON_CMD=python"
)

if not defined PYTHON_CMD (
  echo.
  echo ============================================================
  echo  RoutePilot Simulator kan niet starten
  echo ============================================================
  echo.
  echo Python 3 is niet gevonden.
  echo Installeer Python 3 en vink "Add Python to PATH" aan.
  echo.
  pause
  exit /b 1
)

rem Maak een token bij de eerste start en bewaar het voor volgende keren.
if not exist "%TOKEN_FILE%" (
  for /f "usebackq delims=" %%T in (`powershell -NoProfile -Command "$b=New-Object byte[] 24; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); [Convert]::ToBase64String($b).Replace('+','-').Replace('/','_').TrimEnd('=')"`) do (
    >"%TOKEN_FILE%" echo %%T
  )
)

set /p "ROUTEPILOT_PORTAL_TOKEN="<"%TOKEN_FILE%"

if not defined ROUTEPILOT_PORTAL_TOKEN (
  echo Token kon niet worden aangemaakt.
  pause
  exit /b 1
)

rem Pak het IPv4-adres van de adapter met een default gateway.
set "LAN_IP="
for /f "usebackq delims=" %%I in (`powershell -NoProfile -Command "$ip=(Get-NetIPConfiguration | Where-Object {$_.IPv4DefaultGateway -ne $null -and $_.IPv4Address -ne $null} | ForEach-Object {$_.IPv4Address.IPAddress} | Where-Object {$_ -notlike '169.254.*' -and $_ -ne '127.0.0.1'} | Select-Object -First 1); if($ip){$ip}"`) do (
  set "LAN_IP=%%I"
)

if not defined LAN_IP (
  for /f "tokens=2 delims=:" %%I in ('ipconfig ^| findstr /i "IPv4"') do (
    if not defined LAN_IP (
      set "LAN_IP=%%I"
      set "LAN_IP=!LAN_IP: =!"
    )
  )
)

cls
echo ============================================================
echo              ROUTEPILOT SIMULATOR - LAN
echo ============================================================
echo.
echo Portal op deze computer:
echo   http://127.0.0.1:%ROUTEPILOT_SIM_PORT%
echo.
if defined LAN_IP (
  echo In RoutePilot op je telefoon invullen:
  echo.
  echo   Portal URL:
  echo   http://%LAN_IP%:%ROUTEPILOT_SIM_PORT%
  echo.
  echo   Portal token:
  echo   %ROUTEPILOT_PORTAL_TOKEN%
) else (
  echo LET OP: lokaal IP-adres kon niet automatisch worden gevonden.
  echo Kijk met 'ipconfig' naar het IPv4-adres van je actieve Wi-Fi/LAN-adapter.
  echo Gebruik daarna: http://JOUW-IP:%ROUTEPILOT_SIM_PORT%
  echo.
  echo Portal token:
  echo   %ROUTEPILOT_PORTAL_TOKEN%
)
echo.
echo Telefoon en computer moeten op hetzelfde netwerk zitten.
echo Als Windows Firewall iets vraagt: sta Python toe op prive-netwerken.
echo.
echo Het token blijft bewaard in:
echo   %TOKEN_FILE%
echo.
echo Druk Ctrl+C om de simulator te stoppen.
echo ============================================================
echo.

start "" "http://127.0.0.1:%ROUTEPILOT_SIM_PORT%"
%PYTHON_CMD% server.py

echo.
echo RoutePilot Simulator is gestopt.
pause
