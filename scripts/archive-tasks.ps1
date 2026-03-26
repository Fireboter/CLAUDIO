# archive-tasks.ps1 — Move done/ task files older than N days to archive/tasks/
# Run by Queen once a week to keep done/ dirs lean.
# Usage: pwsh scripts/archive-tasks.ps1
# Usage: pwsh scripts/archive-tasks.ps1 -DaysOld 60

param([int]$DaysOld = 30)

$claudioRoot = Split-Path $PSScriptRoot -Parent
$projects    = @('ClaudeTrader','WebsMami','ClaudeSEO')
$cutoff      = (Get-Date).AddDays(-$DaysOld)
$archived    = 0

foreach ($project in $projects) {
  $doneDir    = Join-Path $claudioRoot ".claudio\tasks\$project\done"
  $archiveDir = Join-Path $claudioRoot ".claudio\archive\tasks\$project"

  if (-not (Test-Path $doneDir)) { continue }

  New-Item -ItemType Directory -Path $archiveDir -Force | Out-Null

  Get-ChildItem (Join-Path $doneDir "*.json") |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    ForEach-Object {
      $dest = Join-Path $archiveDir $_.Name
      Move-Item $_.FullName $dest
      Write-Host "Archived: $($_.Name)"
      $archived++
    }
}

Write-Host ""
if ($archived -eq 0) {
  Write-Host "No tasks older than $DaysOld days found. Nothing to archive."
} else {
  Write-Host "Archived $archived task file(s) older than $DaysOld days to .claudio/archive/tasks/"
}
