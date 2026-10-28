param([Parameter(Mandatory=$true)][string]$Release, [Parameter(Mandatory=$true)][string]$SharedRoot)
$ErrorActionPreference='Stop'
$shared=(Resolve-Path -LiteralPath $SharedRoot).Path
$releasePath=(Resolve-Path -LiteralPath $Release).Path
$allowed=[System.IO.Path]::GetFullPath((Join-Path $shared '.campus-releases'))+[System.IO.Path]::DirectorySeparatorChar
if (-not $releasePath.StartsWith($allowed,[System.StringComparison]::OrdinalIgnoreCase) -or (Split-Path $releasePath -Leaf) -notmatch '^[a-f0-9]{40}$') { throw 'Invalid release path.' }
foreach ($folder in @('bootstrap/cache','public')) { New-Item -ItemType Directory -Path (Join-Path $releasePath $folder) -Force | Out-Null }
foreach ($pair in @(@('storage','storage'),@('public/storage','storage/app/public'))) {
    $link=Join-Path $releasePath $pair[0]; $target=Join-Path $shared $pair[1]
    if (Test-Path -LiteralPath $link) { throw 'The release unexpectedly contains a shared-data path.' }
    New-Item -ItemType Junction -Path $link -Target $target | Out-Null
}
