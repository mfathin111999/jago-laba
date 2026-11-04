@echo off
rem Klik dua kali untuk menjalankan Jago Laba (Laravel) di komputer ini. Opsi tambahan: run-local.cmd -Seed
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\development\run-local.ps1" %*
pause
