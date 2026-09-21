@echo off
REM Detached wave-2 diagnostics: bench/run_wave2.py -> bench/poolsize_run.log
cd /d C:\newwebser
python bench\run_wave2.py > bench\poolsize_run.log 2>&1
echo PS_EXIT=%ERRORLEVEL% >> bench\poolsize_run.log
