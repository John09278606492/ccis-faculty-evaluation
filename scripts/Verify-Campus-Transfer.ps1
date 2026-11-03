$ErrorActionPreference = 'Stop'
try {
    $projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
    Set-Location -LiteralPath $projectRoot
    $phpExe = Join-Path $projectRoot 'runtime/php/php.exe'
    if (Test-Path -LiteralPath $phpExe) {
        $env:CCIS_PHP_RUNTIME_DIR = Join-Path $projectRoot 'runtime/php'
        $env:PHPRC = $env:CCIS_PHP_RUNTIME_DIR
        $env:PHP_INI_SCAN_DIR = Join-Path $env:CCIS_PHP_RUNTIME_DIR 'conf.d'
    } else { $phpExe = (Get-Command php -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source }
    & $phpExe (Join-Path $PSScriptRoot 'verify-campus-transfer.php')
    if ($LASTEXITCODE -ne 0) { throw 'Transfer verification failed. Do not start this copy; transfer the complete ZIP again.' }
    $uploads = Join-Path $projectRoot 'storage/app/public'
    $publicLink = Join-Path $projectRoot 'public/storage'
    if (-not (Test-Path -LiteralPath $publicLink)) {
        New-Item -ItemType Junction -Path $publicLink -Target $uploads | Out-Null
    }
    Write-Host 'Ready. Double-click Start-Campus.cmd. Existing accounts and records are retained.' -ForegroundColor Green
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
