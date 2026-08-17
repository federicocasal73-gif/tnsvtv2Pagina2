@echo off
REM Script para subir assets al server vía SSH y SCP
set SSH="C:\Program Files\Git\usr\bin\ssh.exe"
set SCP="C:\Program Files\Git\usr\bin\scp.exe"
set HOST=u310596868@tnsvt.com
set PORT=65002
set REMOTE_DIR=/home/u310596868/domains/tnsvt.com/public_html/public/assets/styles

echo === Creating remote directory ===
%SSH% -o StrictHostKeyChecking=no -p %PORT% %HOST% "mkdir -p %REMOTE_DIR% && chmod 755 %REMOTE_DIR%"

echo === Uploading CSS files ===
forfiles /P "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\assets\styles" /M *.css /C "cmd /c \"%SCP% -P %PORT% -o StrictHostKeyChecking=no @path %HOST%:%REMOTE_DIR%/@file\""

echo === Verify remote upload ===
%SSH% -o StrictHostKeyChecking=no -p %PORT% %HOST% "ls -la %REMOTE_DIR%"
