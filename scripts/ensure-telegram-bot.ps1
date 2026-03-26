# ensure-telegram-bot.ps1
# Starts telegram-bot.py as a hidden background process if not already running.
# Safe to call multiple times — will not spawn duplicates.
# Used by the SessionStart hook so the bot is always available.

$claudioRoot  = Split-Path $PSScriptRoot -Parent
$pidFile      = Join-Path $claudioRoot '.claudio\telegram-bot.pid'
$botScript    = Join-Path $claudioRoot 'scripts\telegram-bot.py'

function Is-BotRunning {
    if (-not (Test-Path $pidFile)) { return $false }
    $storedPid = [int](Get-Content $pidFile -ErrorAction SilentlyContinue)
    if (-not $storedPid) { return $false }
    $proc = Get-Process -Id $storedPid -ErrorAction SilentlyContinue
    if (-not $proc) { return $false }
    # Confirm it's actually the bot (not a recycled PID)
    try {
        $cmdLine = (Get-CimInstance Win32_Process -Filter "ProcessId=$storedPid").CommandLine
        return ($cmdLine -like '*telegram-bot.py*')
    } catch { return $false }
}

if (Is-BotRunning) {
    Write-Host "[Claudio] Telegram bot already running (pid $(Get-Content $pidFile))"
    exit 0
}

# Start bot as hidden background process
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName         = 'python'
$psi.Arguments        = "`"$botScript`""
$psi.WorkingDirectory = $claudioRoot
$psi.WindowStyle      = [System.Diagnostics.ProcessWindowStyle]::Hidden
$psi.CreateNoWindow   = $true
$psi.UseShellExecute  = $false

$proc = [System.Diagnostics.Process]::Start($psi)
$proc.Id | Set-Content $pidFile

Write-Host "[Claudio] Telegram bot started (pid $($proc.Id))"
