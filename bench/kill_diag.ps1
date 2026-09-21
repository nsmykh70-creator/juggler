Get-CimInstance Win32_Process -Filter "Name='python.exe'" |
    Where-Object { $_.CommandLine -like '*run_diag*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force; Write-Output ("killed " + $_.ProcessId) }
