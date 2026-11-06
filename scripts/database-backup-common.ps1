function Get-StockwellDatabaseSettings
{
    $envPath = Join-Path (Split-Path -Parent $PSScriptRoot) '.env'
    if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
        throw 'Private backend/.env was not found. Configure the database settings before backing up.'
    }

    $settings = @{}
    foreach ($line in Get-Content -LiteralPath $envPath) {
        if ($line -match '^\s*([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$' -and -not $line.TrimStart().StartsWith('#')) {
            $key = $matches[1]
            $value = $matches[2]
            if ($value.Length -ge 2 -and
                (($value.StartsWith('"') -and $value.EndsWith('"')) -or
                 ($value.StartsWith("'") -and $value.EndsWith("'")))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            $settings[$key] = $value
        }
    }

    foreach ($key in @('DB_HOST', 'DB_USERNAME', 'DB_NAME')) {
        if (-not $settings.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($settings[$key])) {
            throw "Required database setting $key is missing from backend/.env."
        }
    }
    if ($settings.DB_NAME -notmatch '^[A-Za-z0-9_$-]{1,64}$') {
        throw 'DB_NAME contains unsupported characters.'
    }
    if (-not $settings.ContainsKey('DB_PASSWORD')) { $settings.DB_PASSWORD = '' }
    if (-not $settings.ContainsKey('DB_PORT') -or [string]::IsNullOrWhiteSpace($settings.DB_PORT)) { $settings.DB_PORT = '3306' }
    if ($settings.DB_PORT -notmatch '^\d{1,5}$' -or [int]$settings.DB_PORT -lt 1 -or [int]$settings.DB_PORT -gt 65535) {
        throw 'DB_PORT must be a valid TCP port.'
    }
    return $settings
}

function Resolve-StockwellMySqlClient([string]$Executable)
{
    $command = Get-Command $Executable -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }

    $roots = @()
    if (-not [string]::IsNullOrWhiteSpace($env:LARAGON_ROOT)) {
        $roots += Join-Path $env:LARAGON_ROOT 'bin\mysql'
    }
    $roots += 'C:\laragon\bin\mysql'
    foreach ($root in $roots) {
        if (-not (Test-Path -LiteralPath $root -PathType Container)) { continue }
        foreach ($version in (Get-ChildItem -LiteralPath $root -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending)) {
            $candidate = Join-Path $version.FullName "bin\$Executable"
            if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
        }
    }
    throw "$Executable was not found in PATH or a Laragon MySQL installation."
}

function ConvertTo-MySqlOptionValue([string]$Value)
{
    $escaped = $Value.Replace('\', '\\').Replace('"', '\"').Replace("`r", '\r').Replace("`n", '\n')
    return '"' + $escaped + '"'
}

function New-StockwellMySqlDefaultsFile($Settings)
{
    $path = Join-Path ([System.IO.Path]::GetTempPath()) ('stockwell-db-' + [guid]::NewGuid().ToString('N') + '.cnf')
    $contents = "[client]`r`nuser=$(ConvertTo-MySqlOptionValue $Settings.DB_USERNAME)`r`npassword=$(ConvertTo-MySqlOptionValue $Settings.DB_PASSWORD)`r`nhost=$(ConvertTo-MySqlOptionValue $Settings.DB_HOST)`r`nport=$($Settings.DB_PORT)`r`n"
    [System.IO.File]::WriteAllText($path, $contents, (New-Object System.Text.UTF8Encoding($false)))
    return $path
}

function Quote-StockwellProcessArgument([string]$Value)
{
    return '"' + $Value.Replace('"', '\"') + '"'
}
