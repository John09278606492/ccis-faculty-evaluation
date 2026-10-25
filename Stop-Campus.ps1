$ErrorActionPreference = 'Stop'
$statusFile = Join-Path $PSScriptRoot 'storage/app/campus-server.json'
if (-not (Test-Path -LiteralPath $statusFile)) { Write-Host 'Campus server is not running.'; exit 0 }
$state = Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json
if ($state.state -in @('stopped','error')) { Write-Host $state.message; exit 0 }
Set-Content -LiteralPath (Join-Path $PSScriptRoot 'storage/framework/campus-stop.request') -Value 'stop'
Write-Host 'Waiting for the managed campus services to stop safely...'
$deadline = (Get-Date).AddSeconds(90)
while ((Get-Date) -lt $deadline) {
    $state = Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json
    if ($state.state -in @('stopped','error')) { Write-Host $state.message; exit 0 }
    Start-Sleep -Milliseconds 500
}
throw 'Shutdown is incomplete. Check the campus logs; no unrelated services were stopped.'
