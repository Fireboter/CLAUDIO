# spawn-agent.ps1 — Open a new Windows Terminal tab for a project agent
# The terminal starts Claude Code in the project directory, which auto-loads:
#   - The project's CLAUDE.md (hierarchical, picked up by Claude automatically)
#   - The project's .mcp.json (picked up by Claude Code automatically)
#
# Usage: pwsh scripts/spawn-agent.ps1 -ProjectName ClaudeTrader
# First-time: Claude Code will prompt to trust the directory — confirm once.
# Subsequent runs: trust is remembered in ~/.claude/settings.json permanently.

param(
  [Parameter(Mandatory=$true)]
  [ValidateSet('ClaudeTrader','WebsMami','ClaudeSEO')]
  [string]$ProjectName
)

$claudioRoot = Split-Path $PSScriptRoot -Parent

$projectPaths = @{
  'ClaudeTrader' = Join-Path $claudioRoot 'Projects\ClaudeTrader'
  'WebsMami'     = Join-Path $claudioRoot 'Projects\WebsMami'
  'ClaudeSEO'    = Join-Path $claudioRoot 'Work (Rechtecheck)\ClaudeSEO'
}

$projectPath = $projectPaths[$ProjectName]

if (-not (Test-Path $projectPath)) {
  Write-Error "Project directory not found: $projectPath"
  exit 1
}

# Guard: do not double-spawn an already active agent
$registryPath = Join-Path $claudioRoot '.claudio\registry.json'
if (Test-Path $registryPath) {
  $reg = Get-Content $registryPath -Raw | ConvertFrom-Json
  if ($reg.agents.$ProjectName.status -eq 'active') {
    Write-Host "[$ProjectName] Agent already active — skipping spawn. Terminal should already be open."
    exit 0
  }
}

Write-Host "Spawning agent for $ProjectName at $projectPath"
Write-Host "Windows Terminal will open a new tab. Claude Code will start in the project directory."
Write-Host "If this is the first time opening this directory, approve the trust prompt once."

# Open new Windows Terminal tab, cd to project, launch claude
# Claude Code auto-loads CLAUDE.md and .mcp.json from the project directory
wt.exe new-tab `
  --title "Claudio: $ProjectName" `
  -- pwsh -NoExit -Command "Set-Location '$projectPath'; Write-Host 'Claudio Agent: $ProjectName — ready'; claude"
