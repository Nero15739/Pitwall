@echo off
REM Pit Wall PC setup: double-click, or pass options, e.g.  setup.bat -Site https://your-domain -Key pw_...   /   setup.bat -Stop
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1" %*
pause
