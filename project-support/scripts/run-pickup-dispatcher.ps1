$ErrorActionPreference = 'Stop'
$configuration = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'dispatcher-config.json') -Raw | ConvertFrom-Json
$started = [DateTime]::UtcNow.ToString('o')
$code = 1
$maintenanceCode = $null
$outputLines = @()
$maintenanceLines = @()
try {
    if ($configuration.mode -eq 'live') {
        $entry = Join-Path $configuration.appRoot 'project-support\scripts\dispatch-request-notices.php'
    } elseif ($configuration.mode -eq 'paused') {
        $code = 0
        $outputLines = @('Dispatcher paused by operator.')
        $entry = $null
    } else {
        throw 'Unknown dispatcher mode; refusing to send.'
    }
    if ($entry) {
        $outputLines = @(& $configuration.phpExe $entry 2>&1 | ForEach-Object { $_.ToString() })
        $code = $LASTEXITCODE
    }
} catch {
    $outputLines += $_.Exception.Message
    $code = 1
}
# Retention failures cannot change the result of an already committed dispatch.
# Backups are never touched by this scheduled invocation.
try {
    $maintenance = Join-Path $configuration.appRoot 'project-support\scripts\maintain-request-storage.py'
    $logDirectory = Split-Path -Parent $configuration.logFile
    $maintenanceLines = @(& $configuration.pythonExe -B $maintenance --apply --logs-only --log-root $logDirectory 2>&1 | ForEach-Object { $_.ToString() })
    $maintenanceCode = $LASTEXITCODE
    $maintenanceLines = @($maintenanceLines | Where-Object { $_ -ne 'Applied 0 eligible maintenance actions.' })
} catch {
    $maintenanceCode = 1
    $maintenanceLines += $_.Exception.Message
}
$record = [ordered]@{
    startedUtc = $started
    finishedUtc = [DateTime]::UtcNow.ToString('o')
    mode = $configuration.mode
    exitCode = $code
    output = $outputLines
    maintenanceExitCode = $maintenanceCode
    maintenanceOutput = $maintenanceLines
}
$record | ConvertTo-Json -Depth 5 -Compress | Add-Content -LiteralPath $configuration.logFile -Encoding UTF8
exit $code
