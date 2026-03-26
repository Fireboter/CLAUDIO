# ensure-telegram-bot.ps1
# Starts telegram-bot.py and telegram-daemon.py as hidden background processes
# if not already running. Safe to call multiple times — will not spawn duplicates.
# Used by the SessionStart hook so the bot and injection daemon are always available.

$claudioRoot   = Split-Path $PSScriptRoot -Parent
$botPidFile    = Join-Path $claudioRoot '.claudio\telegram-bot.pid'
$daemonPidFile = Join-Path $claudioRoot '.claudio\telegram-daemon.pid'
$botScript     = Join-Path $claudioRoot 'scripts\telegram-bot.py'
$daemonScript  = Join-Path $claudioRoot 'scripts\telegram-daemon.py'

function Is-ScriptRunning($pidFile, $scriptName) {
    if (-not (Test-Path $pidFile)) { return $false }
    $storedPid = [int](Get-Content $pidFile -ErrorAction SilentlyContinue)
    if (-not $storedPid) { return $false }
    $proc = Get-Process -Id $storedPid -ErrorAction SilentlyContinue
    if (-not $proc) { return $false }
    try {
        $cmdLine = (Get-CimInstance Win32_Process -Filter "ProcessId=$storedPid").CommandLine
        return ($cmdLine -like "*$scriptName*")
    } catch { return $false }
}

function Start-HiddenPython($script, $pidFile, $label) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName         = 'python'
    $psi.Arguments        = "`"$script`""
    $psi.WorkingDirectory = $claudioRoot
    $psi.WindowStyle      = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $psi.CreateNoWindow   = $true
    $psi.UseShellExecute  = $false
    $proc = [System.Diagnostics.Process]::Start($psi)
    $proc.Id | Set-Content $pidFile
    Write-Host "[Claudio] $label started (pid $($proc.Id))"
}

# --- Telegram bot ---
if (Is-ScriptRunning $botPidFile 'telegram-bot.py') {
    Write-Host "[Claudio] Telegram bot already running (pid $(Get-Content $botPidFile))"
} else {
    Start-HiddenPython $botScript $botPidFile 'Telegram bot'
}

# --- Injection daemon ---
if (Is-ScriptRunning $daemonPidFile 'telegram-daemon.py') {
    Write-Host "[Claudio] Injection daemon already running (pid $(Get-Content $daemonPidFile))"
} else {
    Start-HiddenPython $daemonScript $daemonPidFile 'Injection daemon'
}
