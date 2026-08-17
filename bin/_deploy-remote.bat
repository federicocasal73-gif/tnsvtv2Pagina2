@echo off
setlocal enabledelayedexpansion

REM Script temporal para deploy vía SSH
"C:\Windows\System32\OpenSSH\ssh.exe" -p 65002 -o StrictHostKeyChecking=no u310596868@tnsvt.com bash -s < "%~dp0_deploy-remote.sh"
if %ERRORLEVEL% NEQ 0 echo ERROR en conexión SSH
pause
