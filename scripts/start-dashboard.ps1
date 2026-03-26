# start-dashboard.ps1 — Launch the Claudio dashboard in a new Windows Terminal tab
# Usage: pwsh scripts/start-dashboard.ps1

$claudioRoot = Split-Path $PSScriptRoot -Parent
$startCmd    = "Set-Location '$claudioRoot'; python -m http.server 8765 --bind 127.0.0.1"
$encodedCmd  = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Dashboard" -- pwsh -NoExit -EncodedCommand $encodedCmd
Start-Sleep -Seconds 1
Start-Process "http://localhost:8765/scripts/dashboard/"
