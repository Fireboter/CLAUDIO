# telegram-notify.ps1 — Send a one-way message to the configured Telegram chat
# Usage: pwsh scripts/telegram-notify.ps1 "*ClaudeTrader* Done: task-001 ✓"
# Reads credentials from .env at repo root (TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID)

param(
  [Parameter(Mandatory=$true)]
  [string]$Message
)

$claudioRoot = Split-Path $PSScriptRoot -Parent
$envPath = Join-Path $claudioRoot ".env"

if (-not (Test-Path $envPath)) {
  Write-Error ".env not found at $envPath — copy .env.example to .env and fill in credentials"
  exit 1
}

# Parse .env — no external module required
Get-Content $envPath | ForEach-Object {
  if ($_ -match '^([^#\s][^=]*)=(.*)$') {
    [System.Environment]::SetEnvironmentVariable($matches[1].Trim(), $matches[2].Trim(), 'Process')
  }
}

$token  = $env:TELEGRAM_BOT_TOKEN
$chatId = $env:TELEGRAM_CHAT_ID

if (-not $token -or -not $chatId) {
  Write-Error "TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID not set in .env"
  exit 1
}

try {
  $body = @{
    chat_id    = $chatId
    text       = $Message
    parse_mode = "Markdown"
  }
  $response = Invoke-RestMethod `
    -Uri "https://api.telegram.org/bot$token/sendMessage" `
    -Method Post `
    -Body ($body | ConvertTo-Json) `
    -ContentType "application/json"
  Write-Host "Telegram sent: $Message"
} catch {
  Write-Warning "Telegram send failed: $($_.Exception.Message)"
  # Do not exit 1 — notification failure must never block agent work
}
