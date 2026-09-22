@echo off
REM Rebuild juggler:bench with the absolute-form fix (security pass), then
REM restart bench-juggler with both compose files (cap=256, admin 19090).
cd /d C:\newwebser
docker build -t juggler:bench -f Dockerfile . > bench\rebuild_sec.log 2>&1
echo BUILD_EXIT=%ERRORLEVEL% >> bench\rebuild_sec.log
docker compose -f bench\docker-compose.bench.yml -f bench\compose.ab.yml up -d juggler >> bench\rebuild_sec.log 2>&1
echo UP_EXIT=%ERRORLEVEL% >> bench\rebuild_sec.log
