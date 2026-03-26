# agent-checkin.ps1 — Agent writes its current status to .claudio/registry.json
# Called by project agents to register heartbeats, task start, task complete, and idle/offline states
#
# Usage examples:
#   pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status active -CurrentTask task-20260326-001
#   pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status idle
#   pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status active -CompleteTask task-20260326-001
#
# recent_completions element shape: plain task ID string (e.g. "task-20260326-001")
# max kept: read from .claudio/agents/<Project>/config.json (max_recent_completions field)

param(
  [Parameter(Mandatory=$true)]
  [ValidateSet('ClaudeTrader','WebsMami','ClaudeSEO')]
  [string]$Project,

  [Parameter(Mandatory=$true)]
  [ValidateSet('active','idle','offline')]
  [string]$Status,

  [string]$CurrentTask    = $null,
  [string]$CurrentBranch  = $null,
  [string]$CompleteTask   = $null   # task ID just completed — prepended to recent_completions
)

$claudioRoot  = Split-Path $PSScriptRoot -Parent
$registryPath = Join-Path $claudioRoot ".claudio\registry.json"

# Load max_recent_completions from agent config (default 5 if not present)
$configPath = Join-Path $claudioRoot ".claudio\agents\$Project\config.json"
$maxRecent = 5
if (Test-Path $configPath) {
  $config = Get-Content $configPath -Raw | ConvertFrom-Json
  if ($config.max_recent_completions) {
    $maxRecent = [int]$config.max_recent_completions
  }
}

# Load or initialise registry
if (Test-Path $registryPath) {
  $reg = Get-Content $registryPath -Raw | ConvertFrom-Json
} else {
  # Bootstrap empty registry
  $reg = [PSCustomObject]@{
    schema_version = 1
    updated_at = $null
    agents = [PSCustomObject]@{
      ClaudeTrader = [PSCustomObject]@{ status='offline'; last_heartbeat=$null; current_task=$null; current_branch=$null; tasks_completed_today=0; recent_completions=@() }
      WebsMami     = [PSCustomObject]@{ status='offline'; last_heartbeat=$null; current_task=$null; current_branch=$null; tasks_completed_today=0; recent_completions=@() }
      ClaudeSEO    = [PSCustomObject]@{ status='offline'; last_heartbeat=$null; current_task=$null; current_branch=$null; tasks_completed_today=0; recent_completions=@() }
    }
  }
}

$now    = ([System.DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'))
$agent  = $reg.agents.$Project

$agent.status         = $Status
$agent.last_heartbeat = $now

if ($PSBoundParameters.ContainsKey('CurrentTask')) {
  $agent.current_task = $CurrentTask
}
if ($PSBoundParameters.ContainsKey('CurrentBranch')) {
  $agent.current_branch = $CurrentBranch
}

# Handle task completion: prepend task ID to recent_completions, keep last $maxRecent
if ($PSBoundParameters.ContainsKey('CompleteTask') -and $CompleteTask) {
  $keepCount = $maxRecent - 1
  $list = @($CompleteTask) + @($agent.recent_completions | Select-Object -First $keepCount)
  $agent.recent_completions    = $list
  $agent.tasks_completed_today = [int]$agent.tasks_completed_today + 1
  $agent.current_task          = $null
}

$reg.updated_at = $now
$reg | ConvertTo-Json -Depth 5 | Set-Content $registryPath -Encoding UTF8

Write-Host "[$Project] status=$Status heartbeat=$now"

# Write tasks-summary.json for all agents (consumed by the dashboard)
$taskSummaryPath = Join-Path $claudioRoot ".claudio\tasks-summary.json"
$summary = [PSCustomObject]@{ updated_at = $now; agents = [PSCustomObject]@{} }
foreach ($agentName in @('ClaudeTrader', 'WebsMami', 'ClaudeSEO')) {
  $tasksBase  = Join-Path $claudioRoot ".claudio\tasks\$agentName"
  $agentEntry = [PSCustomObject]@{
    counts = [PSCustomObject]@{}
    tasks  = [PSCustomObject]@{}
  }
  foreach ($s in @('pending', 'active', 'done', 'failed')) {
    $dir      = Join-Path $tasksBase $s
    $taskList = @()
    if (Test-Path $dir) {
      Get-ChildItem $dir -Filter '*.json' |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 20 |
        ForEach-Object {
          try { $taskList += (Get-Content $_.FullName -Raw | ConvertFrom-Json) } catch {}
        }
    }
    $agentEntry.counts | Add-Member -NotePropertyName $s -NotePropertyValue $taskList.Count -Force
    $agentEntry.tasks  | Add-Member -NotePropertyName $s -NotePropertyValue $taskList       -Force
  }
  $summary.agents | Add-Member -NotePropertyName $agentName -NotePropertyValue $agentEntry -Force
}
$summary | ConvertTo-Json -Depth 8 | Set-Content $taskSummaryPath -Encoding UTF8
