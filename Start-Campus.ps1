param([ValidateRange(1024,65480)][int]$Port = 8020, [switch]$NoBrowser)
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$phpExe = (Get-Command php -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
$nodeExe = (Get-Command node -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
$statusFile = Join-Path $PSScriptRoot 'storage/app/campus-server.json'
$interfaces = @(Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address })
if (-not $interfaces.Count) { $interfaces = @(Get-NetIPConfiguration | Where-Object { $_.IPv4Address }) }
$addresses = @($interfaces | ForEach-Object { $_.IPv4Address.IPAddress } | Where-Object { $_ -notmatch '^(127\.|169\.254\.)' } | Select-Object -Unique)
$browserUrl = "http://127.0.0.1:$Port"
if ($addresses.Count) { $browserUrl = "http://$($addresses[0]):$Port" }
$listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
if ($listener) {
    $existing = $null
    if (Test-Path -LiteralPath $statusFile) { $existing = Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json }
    if (-not $existing -or $existing.state -ne 'running' -or $existing.port -ne $Port -or $existing.pid -notin @($listener.OwningProcess)) {
        throw "Port $Port belongs to another server. Choose another port; no existing process was stopped."
    }
} else {
    if (Test-Path -LiteralPath $statusFile) { Remove-Item -LiteralPath $statusFile }
    $previousUrl = $env:CAMPUS_PUBLIC_URL
    try {
        $env:CAMPUS_PUBLIC_URL = $browserUrl
        $arguments = '"{0}" "{1}" "{2}" {3}' -f (Join-Path $PSScriptRoot 'scripts/campus-server.cjs'), $PSScriptRoot, $phpExe, $Port
        $server = Start-Process -FilePath $nodeExe -ArgumentList $arguments -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput 'storage/logs/campus.log' -RedirectStandardError 'storage/logs/campus-error.log'
    } finally { $env:CAMPUS_PUBLIC_URL = $previousUrl }
    Write-Host 'Starting campus web services...'
    $deadline = (Get-Date).AddSeconds(120)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        if (Test-Path -LiteralPath $statusFile) {
            $state = Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json
            if ($state.state -eq 'error') { throw $state.message }
            if ($state.state -eq 'running') { $ready = $true; break }
        }
        if ($server.HasExited) { throw 'Campus services stopped. Check storage/logs/campus-error.log.' }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Campus startup is still incomplete. Check storage/logs/campus-error.log before retrying.' }
}
$response = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/login" -UseBasicParsing -TimeoutSec 15
if ($response.StatusCode -ne 200) { throw 'The campus login page is unavailable.' }
Write-Host ''
Write-Host 'CAMPUS SERVER READY' -ForegroundColor Green
if (Test-Path -LiteralPath $statusFile) {
    $health = Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json
    if ($health.health -eq 'degraded') { Write-Warning 'Some background services need attention. Available pages remain online; review storage/app/campus-server.json and service logs.' }
}
Write-Host 'Share a link below with students on the campus network:'
foreach ($address in $addresses) { Write-Host "  http://${address}:$Port/login" -ForegroundColor Cyan }
if (-not $addresses.Count) { Write-Warning 'No connected LAN adapter with a default gateway was found. Local browser access still works.' }
Write-Host "Local access: http://127.0.0.1:$Port/login"
Write-Host 'First-time network access: run Enable-Campus-Access.cmd as administrator.'
Write-Host 'Stop services using Stop-Campus.cmd. Closing this window leaves the server running.'
Write-Host 'A reserved server IP and campus DNS name keep the shared address stable.'
if (-not $NoBrowser) { Start-Process -FilePath "$browserUrl/login" }
