# queue-task.ps1 — Queen writes a task to a project's pending/ queue
# Usage: pwsh scripts/queue-task.ps1 -Project ClaudeTrader -Title "Add MA indicator" -Type feature -Priority high
# The task JSON is written to .claudio/tasks/<Project>/pending/<id>.json
# The agent picks it up automatically on next poll or startup.

param(
  [Parameter(Mandatory=$true)]
  [ValidateSet('ClaudeTrader','WebsMami','ClaudeSEO')]
  [string]$Project,

  [Parameter(Mandatory=$true)]
  [string]$Title,

  [string]$Description = '',

  [ValidateSet('feature','bugfix','review','research','deploy','maintenance')]
  [string]$Type = 'feature',

  [ValidateSet('high','normal','low')]
  [string]$Priority = 'normal',

  [string]$BranchPrefix = 'feature',

  [switch]$NoTelegram
)

$claudioRoot = Split-Path $PSScriptRoot -Parent
$pendingDir  = Join-Path $claudioRoot ".claudio\tasks\$Project\pending"

if (-not (Test-Path $pendingDir)) {
  New-Item -ItemType Directory -Path $pendingDir -Force | Out-Null
}

# Task ID: timestamp-based for uniqueness and natural sort order
$taskId = "task-$(Get-Date -AsUTC -Format 'yyyyMMdd-HHmmss')"

$task = [ordered]@{
  id              = $taskId
  project         = $Project
  type            = $Type
  title           = $Title
  description     = $Description
  priority        = $Priority
  depends_on      = @()
  created_at      = ([System.DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'))
  created_by      = 'queen'
  branch_prefix   = $BranchPrefix
  telegram_notify = (-not $NoTelegram.IsPresent)
}

$taskPath = Join-Path $pendingDir "$taskId.json"
$task | ConvertTo-Json | Set-Content $taskPath -Encoding UTF8

Write-Host ""
Write-Host "Task queued successfully:"
Write-Host "  ID:       $taskId"
Write-Host "  Project:  $Project"
Write-Host "  Title:    $Title"
Write-Host "  Type:     $Type | Priority: $Priority"
Write-Host "  File:     $taskPath"
Write-Host ""
Write-Host "Start the agent if not running: pwsh scripts/spawn-agent.ps1 -ProjectName $Project"
