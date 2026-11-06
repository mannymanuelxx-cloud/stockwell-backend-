$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'database-backup-common.ps1')

$settings = Get-StockwellDatabaseSettings
$dump = Resolve-StockwellMySqlClient 'mysqldump.exe'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$projectName = Split-Path -Leaf $projectRoot
$backupDirectory = Join-Path (Join-Path $env:LOCALAPPDATA 'Stockwell\backups') $projectName
New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
$backupPath = Join-Path $backupDirectory ('stockwell-backup-' + (Get-Date -Format 'yyyyMMdd-HHmmssfff') + '.sql')
$defaultsFile = New-StockwellMySqlDefaultsFile $settings

try {
    & $dump "--defaults-extra-file=$defaultsFile" '--single-transaction' '--routines' '--triggers' '--events' '--default-character-set=utf8mb4' "--result-file=$backupPath" $settings.DB_NAME
    if ($LASTEXITCODE -ne 0) { throw 'MySQL backup command failed.' }
    if (-not (Test-Path -LiteralPath $backupPath -PathType Leaf) -or (Get-Item -LiteralPath $backupPath).Length -eq 0) {
        throw 'MySQL created an empty or missing backup file.'
    }

    $cutoff = (Get-Date).AddDays(-14)
    Get-ChildItem -LiteralPath $backupDirectory -Filter 'stockwell-backup-*.sql' -File |
        Where-Object { $_.LastWriteTime -lt $cutoff } |
        Remove-Item -Force
    Write-Output "Database backup created: $backupPath"
} catch {
    if (Test-Path -LiteralPath $backupPath -PathType Leaf) {
        Remove-Item -LiteralPath $backupPath -Force
    }
    throw
} finally {
    if (Test-Path -LiteralPath $defaultsFile -PathType Leaf) {
        Remove-Item -LiteralPath $defaultsFile -Force -ErrorAction Stop
    }
}
