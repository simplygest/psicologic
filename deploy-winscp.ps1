param(
    [string[]] $Files = @(),
    [switch] $Staged,
    [switch] $Changed,
    [int] $Retries = 3,
    [int] $DelaySeconds = 2,
    [string] $WinScpPath = "",
    [string] $FtpsInfoPath = "ftps.info",
    [string] $LogDir = "deploy-logs",
    [switch] $DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Read-FtpsInfo {
    param([string] $Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "No se encuentra $Path."
    }

    $info = @{}
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -notmatch "^\s*([^:]+):\s*(.*)$") {
            continue
        }
        $key = $matches[1].Trim().ToLowerInvariant()
        $value = $matches[2].Trim()
        if ($key -like "host*") { $info.Host = $value }
        elseif ($key -like "ruta*") { $info.Root = $value }
        elseif ($key -like "subcarpeta*") { $info.AppPath = $value }
        elseif ($key -like "usuario*") { $info.User = $value }
        elseif ($key -like "contrase*") { $info.Password = $value }
    }

    foreach ($required in @("Host", "Root", "User", "Password")) {
        if (-not $info.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($info[$required])) {
            throw "Falta el campo $required en $Path."
        }
    }
    if (-not $info.ContainsKey("AppPath")) {
        $info.AppPath = ""
    }
    return $info
}

function Find-WinScp {
    param([string] $PreferredPath)

    if ($PreferredPath -and (Test-Path -LiteralPath $PreferredPath)) {
        return (Resolve-Path -LiteralPath $PreferredPath).Path
    }

    $cmd = Get-Command "winscp.com" -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    $candidates = @(
        "$env:ProgramFiles\WinSCP\WinSCP.com",
        "${env:ProgramFiles(x86)}\WinSCP\WinSCP.com"
    )
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
    }

    throw "No encuentro WinSCP.com. Instala WinSCP o pasa -WinScpPath `"C:\Ruta\WinSCP.com`"."
}

function Get-GitFiles {
    param([switch] $OnlyStaged, [switch] $OnlyChanged)

    if ($OnlyStaged) {
        $output = git diff --name-only --cached --diff-filter=ACMRT
    } elseif ($OnlyChanged) {
        $output = git diff --name-only --diff-filter=ACMRT
    } else {
        $output = @()
    }

    return @($output | Where-Object { $_ -and (Test-Path -LiteralPath $_ -PathType Leaf) })
}

function Convert-ToRemotePath {
    param([string] $RelativePath, [hashtable] $Info)

    $root = "/" + ($Info.Root.Trim("/") )
    $appPath = ""
    if ($Info.ContainsKey("AppPath") -and $null -ne $Info.AppPath) {
        $appPath = [string] $Info.AppPath
    }
    $app = $appPath.Trim("/")
    $relative = ($RelativePath -replace "\\", "/").TrimStart("/")
    $base = if ($app) { "$root/$app" } else { $root }
    return "$base/$relative" -replace "//+", "/"
}

function Get-RemoteDirs {
    param([string] $RemoteFile, [string] $BaseRemoteDir = "")

    $parts = $RemoteFile.Trim("/").Split("/")
    $dirs = @()
    $current = ""
    $base = ("/" + $BaseRemoteDir.Trim("/")) -replace "//+", "/"
    for ($i = 0; $i -lt $parts.Length - 1; $i++) {
        $current += "/" + $parts[$i]
        if ($base -eq "/" -or $current.Length -gt $base.Length) {
            $dirs += $current
        }
    }
    return $dirs
}

function New-WinScpScript {
    param(
        [hashtable] $Info,
        [string] $LocalPath,
        [string] $RemotePath
    )

    $encodedUser = [uri]::EscapeDataString($Info.User)
    $encodedPassword = [uri]::EscapeDataString($Info.Password)
    $openUrl = "ftpes://$encodedUser`:$encodedPassword@$($Info.Host)/"
    $localForWinScp = (Resolve-Path -LiteralPath $LocalPath).Path
    $baseRemoteDir = ("/" + $Info.Root.Trim("/") + "/" + ([string] $Info.AppPath).Trim("/")) -replace "//+", "/"

    $commands = @(
        "option batch abort",
        "option confirm off",
        "open `"$openUrl`" -passive=on"
    )

    foreach ($dir in Get-RemoteDirs -RemoteFile $RemotePath -BaseRemoteDir $baseRemoteDir) {
        $commands += "option batch continue"
        $commands += "mkdir `"$dir`""
        $commands += "option batch abort"
    }

    $commands += "put -nopermissions -preservetime `"$localForWinScp`" `"$RemotePath`""
    $commands += "exit"

    $scriptPath = Join-Path ([System.IO.Path]::GetTempPath()) ("winscp-deploy-" + [guid]::NewGuid().ToString("N") + ".txt")
    Set-Content -LiteralPath $scriptPath -Value $commands -Encoding ASCII
    return $scriptPath
}

$info = Read-FtpsInfo -Path $FtpsInfoPath
$winscp = if ($DryRun) { "" } else { Find-WinScp -PreferredPath $WinScpPath }

$selectedFiles = @()
$selectedFiles += $Files
$selectedFiles += Get-GitFiles -OnlyStaged:$Staged -OnlyChanged:$Changed
$selectedFiles = @($selectedFiles |
    Where-Object { $_ } |
    ForEach-Object { $_ -replace "\\", "/" } |
    Where-Object {
        $_ -notlike ".git/*" -and
        $_ -notlike ".codex-remote-attachments/*" -and
        $_ -ne "ftps.info" -and
        (Test-Path -LiteralPath $_ -PathType Leaf)
    } |
    Select-Object -Unique)

if (-not $selectedFiles.Count) {
    throw "No hay archivos para subir. Usa -Files, -Staged o -Changed."
}

if (-not (Test-Path -LiteralPath $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir | Out-Null
}

if ($winscp) {
    Write-Host "WinSCP: $winscp"
} else {
    Write-Host "WinSCP: no comprobado (DryRun)"
}
Write-Host "Destino: $($info.Host)$($info.Root)$($info.AppPath)"
Write-Host "Archivos: $($selectedFiles.Count)"

foreach ($file in $selectedFiles) {
    $remotePath = Convert-ToRemotePath -RelativePath $file -Info $info
    Write-Host ""
    Write-Host "Subiendo $file -> $remotePath"

    if ($DryRun) {
        continue
    }

    $uploaded = $false
    for ($attempt = 1; $attempt -le [Math]::Max(1, $Retries); $attempt++) {
        $scriptPath = $null
        try {
            $scriptPath = New-WinScpScript -Info $info -LocalPath $file -RemotePath $remotePath
            $safeName = ($file -replace "[^a-zA-Z0-9._-]", "_")
            $logPath = Join-Path $LogDir ("winscp-$safeName-attempt$attempt.log")
            & $winscp /ini=nul /log="$logPath" /script="$scriptPath"
            if ($LASTEXITCODE -eq 0) {
                Write-Host "OK $file"
                $uploaded = $true
                break
            }
            Write-Warning "Falló $file en intento $attempt. Revisa $logPath"
        } finally {
            if ($scriptPath -and (Test-Path -LiteralPath $scriptPath)) {
                Remove-Item -LiteralPath $scriptPath -Force
            }
        }
        Start-Sleep -Seconds $DelaySeconds
    }

    if (-not $uploaded) {
        throw "No se pudo subir $file tras $Retries intento(s)."
    }

    Start-Sleep -Seconds $DelaySeconds
}

Write-Host ""
Write-Host "Subida completada."
