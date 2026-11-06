param(
    [Parameter(Mandatory = $true)]
    [string]$BackupPath
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'database-backup-common.ps1')

$backup = (Resolve-Path -LiteralPath $BackupPath).Path
if ([System.IO.Path]::GetExtension($backup) -ne '.sql') {
    throw 'Choose a .sql backup file.'
}
$settings = Get-StockwellDatabaseSettings
$confirmation = Read-Host "This replaces database '$($settings.DB_NAME)'. Type RESTORE $($settings.DB_NAME) to continue"
if ($confirmation -cne "RESTORE $($settings.DB_NAME)") {
    throw 'Restore cancelled because the confirmation did not match.'
}

$backupScript = Join-Path $PSScriptRoot 'backup-database.ps1'
& $backupScript
if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) { throw 'Pre-restore backup failed; restore was stopped.' }

$mysql = Resolve-StockwellMySqlClient 'mysql.exe'
$defaultsFile = New-StockwellMySqlDefaultsFile $settings
$process = $null
$inputFile = $null
try {
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $mysql
    $startInfo.Arguments = (Quote-StockwellProcessArgument "--defaults-extra-file=$defaultsFile") + ' --default-character-set=utf8mb4 ' + $settings.DB_NAME
    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardInput = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    if (-not $process.Start()) { throw 'Could not start the MySQL restore command.' }

    $inputFile = [System.IO.File]::OpenRead($backup)
    $inputFile.CopyTo($process.StandardInput.BaseStream)
    $process.StandardInput.Close()
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) { throw 'MySQL restore command failed. The pre-restore database backup is available under %LOCALAPPDATA%\Stockwell\backups.' }
    Write-Output "Database restored from: $backup"
} finally {
    if ($inputFile) { $inputFile.Dispose() }
    if ($process) {
        if (-not $process.HasExited) { $process.Kill(); $process.WaitForExit() }
        $process.Dispose()
    }
    if (Test-Path -LiteralPath $defaultsFile -PathType Leaf) {
        Remove-Item -LiteralPath $defaultsFile -Force -ErrorAction Stop
    }
}
