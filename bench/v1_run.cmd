@echo off
REM Detached v1.0 matrix runner: bench/run_v1_matrix.py -> bench/v1_run.log
cd /d C:\newwebser
python bench\run_v1_matrix.py > bench\v1_run.log 2>&1
echo V1_EXIT=%ERRORLEVEL% >> bench\v1_run.log
