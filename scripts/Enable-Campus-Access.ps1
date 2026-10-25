param([ValidateRange(1024,65480)][int]$Port = 8020, [string[]]$AllowedNetworks = @('LocalSubnet'))
$ErrorActionPreference = 'Stop'
try {
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Windows requires administrator rights to add this scoped firewall rule. Right-click Enable-Campus-Access.cmd and choose Run as administrator.'
    }
    $nodeExe = (Get-Command node -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
    $statusFile = Join-Path (Split-Path $PSScriptRoot -Parent) 'storage/app/campus-server.json'
    if (Test-Path -LiteralPath $statusFile) {
        $state = Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json
        if ($state.state -eq 'running' -and $state.port -eq $Port) {
            $runningNode = Get-Process -Id $state.pid -ErrorAction SilentlyContinue
            if ($runningNode -and (Split-Path $runningNode.Path -Leaf) -eq 'node.exe') { $nodeExe = $runningNode.Path }
        }
    }
    $ruleName = "CCIS-Classroom-Campus-$Port"
    $existing = Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        Set-NetFirewallRule -Name $ruleName -Enabled True -Direction Inbound -Action Allow -Profile Any
        $existing | Get-NetFirewallPortFilter | Set-NetFirewallPortFilter -Protocol TCP -LocalPort $Port
        $existing | Get-NetFirewallAddressFilter | Set-NetFirewallAddressFilter -RemoteAddress $AllowedNetworks
        $existing | Get-NetFirewallApplicationFilter | Set-NetFirewallApplicationFilter -Program $nodeExe
    } else {
        New-NetFirewallRule -Name $ruleName -DisplayName "CCIS campus web access (TCP $Port)" -Group 'CCIS Classroom' -Enabled True -Direction Inbound -Action Allow -Profile Any -Protocol TCP -LocalPort $Port -Program $nodeExe -RemoteAddress $AllowedNetworks | Out-Null
    }
    Write-Host "Campus web access enabled on TCP $Port for: $($AllowedNetworks -join ', ')" -ForegroundColor Green
    Write-Host 'MySQL and internal worker ports were not opened.'
    Write-Host 'For other campus VLANs, campus IT must specify their exact networks and enable routing.'
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    if ($Host.Name -eq 'ConsoleHost') { Read-Host 'Press Enter to close' | Out-Null }
}
