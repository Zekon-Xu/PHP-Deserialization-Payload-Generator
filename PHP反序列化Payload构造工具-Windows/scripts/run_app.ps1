param(
    [int]$StartPort = 8765,
    [int]$EndPort = 8799
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $ProjectRoot

$PhpExe = Join-Path $ProjectRoot "runtime\php\php.exe"
$PhpGgc = Join-Path $ProjectRoot "vendor\phpggc\phpggc"
$Router = Join-Path $ProjectRoot "app\backend\router.php"
$Shortcut = Join-Path $ProjectRoot "Open-PHPGGC-Workbench.url"
$ShutdownFile = Join-Path $ProjectRoot "runtime\shutdown.request"
$RestartFile = Join-Path $ProjectRoot "runtime\restart.request"

function Fail($Message) {
    Write-Host ""
    Write-Host "[ERROR] $Message" -ForegroundColor Red
    Write-Host ""
    Read-Host "Press Enter to exit"
    exit 1
}

function Test-PortBusy([int]$Port) {
    try {
        $listener = Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
        if ($listener) { return $true }
    } catch {
        try {
            $client = New-Object Net.Sockets.TcpClient
            $async = $client.BeginConnect("127.0.0.1", $Port, $null, $null)
            $busy = $async.AsyncWaitHandle.WaitOne(150, $false)
            $client.Close()
            return $busy
        } catch {
            return $false
        }
    }
    return $false
}

function Get-FreePort([int]$From, [int]$To) {
    foreach ($candidate in $From..$To) {
        if (-not (Test-PortBusy $candidate)) {
            return $candidate
        }
    }
    return $null
}

function Write-WorkbenchShortcut([string]$Url) {
@"
[InternetShortcut]
URL=$Url
"@ | Set-Content -LiteralPath $Shortcut -Encoding ASCII
}

function Start-WorkbenchServer([int]$Port) {
    $Url = "http://127.0.0.1:$Port/"
    Write-WorkbenchShortcut $Url

    Write-Host ""
    Write-Host "============================================================" -ForegroundColor DarkCyan
    Write-Host "  PHPGGC local workbench is starting" -ForegroundColor Green
    Write-Host "============================================================" -ForegroundColor DarkCyan
    Write-Host "  URL: " -NoNewline
    Write-Host $Url -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  Shortcut written:" -ForegroundColor Gray
    Write-Host "  $Shortcut" -ForegroundColor Gray
    Write-Host ""
    Write-Host "  Keep this window open while using the web app." -ForegroundColor Yellow
    Write-Host "  Press Ctrl+C in this window to stop the local service." -ForegroundColor Yellow
    Write-Host "============================================================" -ForegroundColor DarkCyan
    Write-Host ""

    Start-Process $Url
    $phpArgs = "-S 127.0.0.1:$Port -t `"$ProjectRoot`" `"$Router`""
    return Start-Process -FilePath $PhpExe -ArgumentList $phpArgs -WorkingDirectory $ProjectRoot -NoNewWindow -PassThru
}

if (-not (Test-Path -LiteralPath $PhpExe)) {
    Fail "Missing bundled PHP runtime: runtime\php\php.exe"
}

if (-not (Test-Path -LiteralPath $PhpGgc)) {
    Fail "Missing bundled PHPGGC: vendor\phpggc\phpggc"
}

if (-not (Test-Path -LiteralPath $Router)) {
    Fail "Missing local router: app\backend\router.php"
}

Remove-Item -LiteralPath $ShutdownFile -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath $RestartFile -Force -ErrorAction SilentlyContinue

$phpProcess = $null
try {
    while ($true) {
        $Port = Get-FreePort $StartPort $EndPort
        if (-not $Port) {
            Fail "No free local port found from $StartPort to $EndPort."
        }

        $phpProcess = Start-WorkbenchServer $Port
        $restartRequested = $false

        while (-not $phpProcess.HasExited) {
            if (Test-Path -LiteralPath $RestartFile) {
                $restartRequested = $true
                Write-Host ""
                Write-Host "Restart requested from web page. Restarting local service..." -ForegroundColor Yellow
                Stop-Process -Id $phpProcess.Id -Force -ErrorAction SilentlyContinue
                break
            }

            if (Test-Path -LiteralPath $ShutdownFile) {
                Write-Host ""
                Write-Host "Shutdown requested from web page. Stopping local service..." -ForegroundColor Yellow
                Stop-Process -Id $phpProcess.Id -Force -ErrorAction SilentlyContinue
                break
            }

            Start-Sleep -Milliseconds 500
            try { $phpProcess.Refresh() } catch { break }
        }

        Remove-Item -LiteralPath $Shortcut -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $ShutdownFile -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $RestartFile -Force -ErrorAction SilentlyContinue

        if ($restartRequested) {
            Start-Sleep -Milliseconds 800
            continue
        }

        break
    }
} finally {
    if ($phpProcess -and -not $phpProcess.HasExited) {
        Stop-Process -Id $phpProcess.Id -Force -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $Shortcut -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $ShutdownFile -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $RestartFile -Force -ErrorAction SilentlyContinue
}
