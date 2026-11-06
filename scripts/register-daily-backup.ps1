$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'database-backup-common.ps1')
$backupScript = Join-Path $PSScriptRoot 'backup-database.ps1'
if (-not (Test-Path -LiteralPath $backupScript -PathType Leaf)) {
    throw 'The database backup script was not found.'
}
Get-StockwellDatabaseSettings | Out-Null
Resolve-StockwellMySqlClient 'mysqldump.exe' | Out-Null

$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$projectName = Split-Path -Leaf $projectRoot
$taskName = "Stockwell $projectName Daily Database Backup"
$scriptArgument = '"' + $backupScript + '"'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File $scriptArgument"
$trigger = New-ScheduledTaskTrigger -Daily -At '2:00AM'
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Description 'Creates a MySQL backup and keeps the most recent 14 days of backups.' -Force | Out-Null
Write-Output "Registered '$taskName' to run daily at 2:00 AM while this Windows account is signed in."
