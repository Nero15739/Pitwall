@echo off
REM Harvest crash locations and sector splits from the replay that's open in iRacing.
REM Options pass through, e.g.  harvest.bat --pending   /   harvest.bat --open 89139124   /   harvest.bat --force
cd /d "%~dp0.."
node tools\harvest.ts %*
pause
