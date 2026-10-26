@echo off
REM Harvest crash locations and sector splits from the replay that's open in iRacing.
REM Launch the replay from the iRacing app first (or right after starting this: it waits).
REM Options pass through, e.g.  harvest.bat --pending   /   harvest.bat --all   /   harvest.bat --force
cd /d "%~dp0.."
node tools\harvest.ts %*
pause
