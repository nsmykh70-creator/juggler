@echo off
REM Rebuild juggler:bench from the working tree, log to bench/rebuild.log.
REM Run detached:  powershell -NoProfile -Command "(Start-Process -FilePath 'cmd.exe' -ArgumentList '/c','C:\newwebser\bench\rebuild.cmd' -WindowStyle Hidden -PassThru).Id"
cd /d C:\newwebser
docker build -t juggler:bench -f Dockerfile . > bench\rebuild.log 2>&1
echo BUILD_EXIT=%ERRORLEVEL% >> bench\rebuild.log
