@echo off
rem ============================================================
rem  Pythia A/B: one-command stand + all three tests + report
rem
rem  Usage:
rem    bench\pythia_ab_all.cmd          full run (~25-35 min)
rem    bench\pythia_ab_all.cmd --fast   smoke run (~4 min)
rem
rem  Requires (built once): target\release\juggler.exe,
rem  target\release\h3load.exe, docker image juggler:bench,
rem  linux binary in target\x86_64-unknown-linux-gnu\release
rem  (optional: refreshed automatically if present).
rem  Artifacts: bench\ab1_ab_all.json, bench\ab_sat512.json,
rem  bench\ab3_prewarm.json -> bench\report_pythia_ab.html
rem ============================================================
setlocal
cd /d %~dp0..

set PY=C:\Users\Qvazar\AppData\Local\Programs\Python\Python312\python.exe
if not exist "%PY%" set PY=python

echo [pythia_ab_all] orchestrator: preflight - upstreams - test1 - test2 - test3 - report
"%PY%" bench\pythia_ab_all.py %*
set EXITCODE=%ERRORLEVEL%

if %EXITCODE% EQU 0 (
  echo [pythia_ab_all] DONE. Report: bench\report_pythia_ab.html
) else (
  echo [pythia_ab_all] FAILED with exit code %EXITCODE% - see logs above and bench\origin_*.log, bench\srv_bench\boot_ab_all.log
)
exit /b %EXITCODE%
