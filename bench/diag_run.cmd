@echo off
REM Detached c=10k diagnostics runner (connect-permits round):
REM   python bench\run_diag.py --connect-round -> bench/diag_run.log
REM Usage: powershell -NoProfile -Command "(Start-Process -FilePath 'cmd.exe' -ArgumentList '/c','C:\newwebser\bench\diag_run.cmd' -WindowStyle Hidden -PassThru).Id"
cd /d C:\newwebser
python bench\run_diag.py --connect-round > bench\diag_run.log 2>&1
echo DIAG_EXIT=%ERRORLEVEL% >> bench\diag_run.log
