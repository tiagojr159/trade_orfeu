param([string]$Php = 'C:/xampp/php/php.exe')
$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
try {
    $logs = Join-Path $root 'storage/logs'
    New-Item -ItemType Directory -Force -Path $logs | Out-Null
    $file = Join-Path $logs 'scheduler.log'
    if ((Test-Path -LiteralPath $file) -and (Get-Item -LiteralPath $file).Length -gt 1MB) {
        Move-Item -Force -LiteralPath $file -Destination (Join-Path $logs 'scheduler.1.log')
    }
    & $Php bin/orfeu.php tick --once *>> $file
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
