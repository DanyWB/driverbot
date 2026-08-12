[CmdletBinding()]
param(
    [string]$RedisHome = $env:REDIS_HOME,
    [int]$Port = 6379
)

$ErrorActionPreference = "Stop"
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtimeDir = Join-Path $projectRoot ".runtime\redis"

if (-not $RedisHome) {
    $candidates = @(
        "C:\OSPanel\modules\Redis-7.2",
        "C:\OSPanel\modules\Redis-7.0"
    )
    $RedisHome = $candidates |
        Where-Object { Test-Path (Join-Path $_ "redis-server.exe") } |
        Select-Object -First 1
}

if (-not $RedisHome) {
    throw "Redis was not found. Set REDIS_HOME to a directory containing redis-server.exe."
}

$server = Join-Path $RedisHome "redis-server.exe"
$client = Join-Path $RedisHome "redis-cli.exe"
if (-not (Test-Path $server) -or -not (Test-Path $client)) {
    throw "Redis server or client is missing in $RedisHome."
}

function Test-RedisConnection {
    try {
        $pong = & $client -h 127.0.0.1 -p $Port ping 2>$null
        return $LASTEXITCODE -eq 0 -and $pong -eq "PONG"
    }
    catch {
        return $false
    }
}

if (Test-RedisConnection) {
    Write-Output "Redis is already running on 127.0.0.1:$Port."
    exit 0
}

New-Item -ItemType Directory -Force -Path $runtimeDir | Out-Null
$runtimeUnix = $runtimeDir.Replace("\", "/")
$logFile = (Join-Path $runtimeDir "redis.log").Replace("\", "/")
$arguments = @(
    "--port", "$Port",
    "--bind", "127.0.0.1",
    "--protected-mode", "yes",
    "--appendonly", "no",
    "--dir", $runtimeUnix,
    "--dbfilename", "dump.rdb",
    "--logfile", $logFile
)

$process = Start-Process `
    -FilePath $server `
    -ArgumentList $arguments `
    -WorkingDirectory $runtimeDir `
    -WindowStyle Hidden `
    -PassThru

for ($attempt = 0; $attempt -lt 20; $attempt++) {
    Start-Sleep -Milliseconds 250
    if (Test-RedisConnection) {
        Write-Output "Redis started on 127.0.0.1:$Port (PID $($process.Id))."
        exit 0
    }
}

throw "Redis process started but did not answer PING. See $logFile."
