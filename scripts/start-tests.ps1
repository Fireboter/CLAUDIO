# start-tests.ps1 — Run Claudio tests in a new Windows Terminal tab
# Usage: pwsh scripts/start-tests.ps1 [-Project ClaudeTrader]
param([string]$Project = '')

$claudioRoot = Split-Path $PSScriptRoot -Parent
$runArgs     = if ($Project) { "--project $Project" } else { "--all" }
$startCmd    = "Set-Location '$claudioRoot'; python scripts/run-tests.py $runArgs"
$encodedCmd  = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Tests" -- pwsh -NoExit -EncodedCommand $encodedCmd
