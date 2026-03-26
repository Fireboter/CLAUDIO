# start-telegram-bot.ps1 — Launch the Telegram bot in a new Windows Terminal tab
# Usage: pwsh scripts/start-telegram-bot.ps1

$claudioRoot = Split-Path $PSScriptRoot -Parent
$startCmd = "Set-Location '$claudioRoot'; python scripts/telegram-bot.py"
$encodedCmd = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Telegram Bot" -- pwsh -NoExit -EncodedCommand $encodedCmd
