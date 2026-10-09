@echo off
REM Pit Wall setup: double-click, or pass options, e.g.  setup.bat -Tunnel   /   setup.bat -Stop
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1" %*
pause
