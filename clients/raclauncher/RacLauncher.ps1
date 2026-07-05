# RacLauncher Beta 0.1.12 - JEVZGames / RacAccount
# Cambios:
# - Flujo tipo Steam: si no hay sesion, muestra solo login.
# - Si hay sesion guardada, entra directo a biblioteca.
# - Login y biblioteca son pantallas separadas.
# - Descarga en segundo plano con barra de progreso.
# - Botn volver/cerrar sesion.
# - Biblioteca muestra solo juegos en posesion/licenciados.
# - Estado visual de juego ejecutandose.
# - Ventana de logros por juego y prueba de desbloqueo.

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName Microsoft.VisualBasic

$ErrorActionPreference = "Stop"

$AppName = "RacLauncher"
$BaseUrl = "https://racacount.jevzgames.com"
$AppVersion = "0.1.12-beta"

$AppData = Join-Path $env:APPDATA $AppName
$GamesDir = Join-Path $AppData "games"
$DownloadsDir = Join-Path $AppData "downloads"
$UpdatesDir = Join-Path $AppData "updates"
$SessionFile = Join-Path $AppData "session.json"
$AccountsFile = Join-Path $AppData "accounts.json"
$LibraryCacheFile = Join-Path $AppData "library-cache.json"
$ConfigFile = Join-Path $AppData "config.json"
$LauncherDir = if ($PSScriptRoot) { $PSScriptRoot } else { (Get-Location).Path }

New-Item -ItemType Directory -Force -Path $AppData, $GamesDir, $DownloadsDir, $UpdatesDir | Out-Null

function Save-Config {
    @{
        base_url = $BaseUrl
        app_name = $AppName
        version = $AppVersion
    } | ConvertTo-Json | Set-Content -Path $ConfigFile -Encoding UTF8
}

function Load-Session {
    if (Test-Path $SessionFile) {
        try { return Get-Content $SessionFile -Raw | ConvertFrom-Json } catch { return $null }
    }
    return $null
}

function Load-Accounts {
    if (Test-Path $AccountsFile) {
        try {
            $data = Get-Content $AccountsFile -Raw | ConvertFrom-Json
            if ($data -and $data.accounts) { return @($data.accounts) }
        } catch {}
    }
    return @()
}

function Save-Accounts($accounts) {
    try {
        @{
            accounts = @($accounts)
            saved_at = (Get-Date).ToString("s")
        } | ConvertTo-Json -Depth 10 | Set-Content -Path $AccountsFile -Encoding UTF8
    } catch {
        Log "No se pudo guardar accounts.json: $($_.Exception.Message)"
    }
}

function Get-AccountKey($accountOrUser) {
    if (-not $accountOrUser) { return "" }
    $user = if ($accountOrUser.user) { $accountOrUser.user } else { $accountOrUser }
    if ($user.id) { return "id:$($user.id)" }
    if ($user.username) { return "username:$([string]$user.username)" }
    if ($user.email) { return "email:$([string]$user.email)" }
    return ""
}

function Remember-Account($token, $user) {
    if ([string]::IsNullOrWhiteSpace([string]$token)) { return }

    $key = Get-AccountKey $user
    $accounts = @()
    foreach ($account in (Load-Accounts)) {
        $accountKey = Get-AccountKey $account
        $sameUser = (-not [string]::IsNullOrWhiteSpace($key)) -and $accountKey -eq $key
        $sameToken = $account.client_token -and ([string]$account.client_token) -eq ([string]$token)
        if ($sameUser -or $sameToken) { continue }
        $accounts += $account
    }

    $accounts += [pscustomobject]@{
        client_token = [string]$token
        token_type = "Bearer"
        user = $user
        saved_at = (Get-Date).ToString("s")
    }
    Save-Accounts $accounts
}

function Save-Session($token, $user) {
    @{
        client_token = $token
        token_type = "Bearer"
        user = $user
        saved_at = (Get-Date).ToString("s")
    } | ConvertTo-Json -Depth 6 | Set-Content -Path $SessionFile -Encoding UTF8
    Remember-Account $token $user
}

function Clear-Session {
    if (Test-Path $SessionFile) { Remove-Item $SessionFile -Force }
}

function Clear-LibraryCache {
    if (Test-Path $LibraryCacheFile) { Remove-Item $LibraryCacheFile -Force }
}

function Load-LibraryCache {
    if (Test-Path $LibraryCacheFile) {
        try { return Get-Content $LibraryCacheFile -Raw | ConvertFrom-Json } catch { return $null }
    }
    return $null
}

function Save-LibraryCache($data) {
    try {
        @{
            base_url = $BaseUrl
            launcher_version = $AppVersion
            cached_at = (Get-Date).ToString("s")
            data = $data
        } | ConvertTo-Json -Depth 40 | Set-Content -Path $LibraryCacheFile -Encoding UTF8
    } catch {
        Log "No se pudo guardar library-cache.json: $($_.Exception.Message)"
    }
}

function To-Bool($value) {
    if ($null -eq $value) { return $false }
    if ($value -is [bool]) { return [bool]$value }
    $text = ([string]$value).Trim().ToLowerInvariant()
    return @("1", "true", "yes", "on") -contains $text
}

function Api-Get($path) {
    $url = if ($path.StartsWith("http")) { $path } else { "$BaseUrl$path" }
    return Invoke-RestMethod -Method Get -Uri $url -Headers @{ Accept = "application/json" } -TimeoutSec 15
}

function Api-Post($path, $body = @{}, $token = $null) {
    $url = if ($path.StartsWith("http")) { $path } else { "$BaseUrl$path" }
    $headers = @{ Accept = "application/json"; "Content-Type" = "application/json; charset=utf-8" }
    if ($token) { $headers["Authorization"] = "Bearer $token" }

    $json = $body | ConvertTo-Json -Depth 10
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    return Invoke-RestMethod -Method Post -Uri $url -Headers $headers -Body $bytes -TimeoutSec 20
}

function Get-Token {
    $session = Load-Session
    if ($session -and $session.client_token) { return [string]$session.client_token }
    return $null
}

function Get-Session-Username {
    $session = Load-Session
    if ($session -and $session.user -and $session.user.username) {
        return [string]$session.user.username
    }
    return ""
}

function Get-UserDisplayName($user) {
    if ($user -and $user.display_name) { return [string]$user.display_name }
    if ($user -and $user.username) { return [string]$user.username }
    return ""
}

function Format-Presence($presence) {
    if (-not $presence -or -not $presence.status) { return "Online" }
    $status = ([string]$presence.status).ToLowerInvariant()
    if ($status -eq "in_game") {
        $gameName = if ($presence.game_name) { [string]$presence.game_name } elseif ($presence.game_slug) { [string]$presence.game_slug } else { "juego" }
        return "Jugando $gameName"
    }
    if ($status -eq "offline") { return "Offline" }
    return "Online"
}

function Refresh-ClientMe($token) {
    if (-not $token) { return }

    try {
        $res = Api-Post "/api/client/me/" @{} $token
        if ($res.success -and $res.data -and $res.data.user) {
            Save-Session $token $res.data.user
            $name = Get-UserDisplayName $res.data.user
            $presenceText = Format-Presence $res.data.presence
            if ($script:LoggedLabel -and -not [string]::IsNullOrWhiteSpace($name)) {
                $script:LoggedLabel.Text = "Cuenta: $name | $presenceText"
            }
        }
    } catch {
        Log "No se pudo refrescar /api/client/me/: $($_.Exception.Message)"
    }
}

function Log($text) {
    if ($script:LogBox) {
        $time = (Get-Date).ToString("HH:mm:ss")
        $script:LogBox.AppendText("[$time] $text`r`n")
    }
}

function Set-Status($text) {
    if ($script:StatusLabel) { $script:StatusLabel.Text = $text }
    if ($script:LoginStatusLabel) { $script:LoginStatusLabel.Text = $text }
    Log $text
    [System.Windows.Forms.Application]::DoEvents()
}

function Set-Busy($busy) {
    $script:IsBusy = $busy

    foreach ($btn in @($script:LogoutBtn, $script:SwitchAccountBtn, $script:RefreshBtn, $script:InstallBtn, $script:PlayBtn, $script:OpenFolderBtn, $script:MessagesBtn, $script:AchievementsBtn, $script:RedeemBtn, $script:GroupsBtn, $script:FamilyBtn, $script:CloudSyncBtn)) {
        if ($btn) { $btn.Enabled = -not $busy }
    }

    if ($script:CancelBtn) {
        $script:CancelBtn.Enabled = $busy
    }

    if (-not $busy) {
        Update-ActionButtons
    }
}

function Show-Login {
    $script:LoginPanel.Visible = $true
    $script:LibraryPanel.Visible = $false
    $script:UserBox.Focus()
}

function Show-Library {
    $script:LoginPanel.Visible = $false
    $script:LibraryPanel.Visible = $true

    $username = Get-Session-Username
    if ([string]::IsNullOrWhiteSpace($username)) {
        $script:LoggedLabel.Text = "Sesion iniciada"
    } else {
        $script:LoggedLabel.Text = "Cuenta: $username"
    }

    Refresh-Library
}

function Validate-Session {
    $token = Get-Token
    if (-not $token) { return $false }

    try {
        $res = Api-Post "/api/client/library/" @{} $token
        if ($res.success) { return $true }
    } catch {}

    return $false
}

function Get-InstallBuild($game) {
    if ($null -ne $game.install_build) { return $game.install_build }
    return $null
}

function Get-GameSlug($game) {
    if ($game.slug) { return [string]$game.slug }
    if ($game.game_slug) { return [string]$game.game_slug }
    return ""
}

function Get-GameName($game) {
    if ($game.name) { return [string]$game.name }
    if ($game.game_name) { return [string]$game.game_name }
    return (Get-GameSlug $game)
}

function Get-GameStatus($game) {
    if ($game.status) { return [string]$game.status }
    return ""
}

function Get-GameInstallDir($slug) {
    return Join-Path $GamesDir $slug
}

function Read-InstalledVersion($slug) {
    $file = Join-Path (Get-GameInstallDir $slug) "installed.json"
    if (Test-Path $file) {
        try {
            $data = Get-Content $file -Raw | ConvertFrom-Json
            return [string]$data.version
        } catch { return "" }
    }
    return ""
}

function Write-InstalledVersion($slug, $version, $exePath, $game = $null, $build = $null) {
    $dir = Get-GameInstallDir $slug
    New-Item -ItemType Directory -Force -Path $dir | Out-Null

    $metadata = @{
        slug = $slug
        version = $version
        executable_path = $exePath
        install_dir = $dir
        installed_at = (Get-Date).ToString("s")
    }

    if ($game) {
        $metadata["name"] = Get-GameName $game
        $metadata["offline_allowed"] = To-Bool $game.offline_allowed
        $metadata["offline_available"] = To-Bool $game.offline_available
        $metadata["offline_entitlement"] = $game.offline_entitlement
    }

    if ($build) {
        if ($build.id) { $metadata["build_id"] = [int]$build.id }
        if ($build.channel) { $metadata["channel"] = [string]$build.channel }
        if ($build.delivery_type) { $metadata["delivery_type"] = [string]$build.delivery_type }
        if ($build.platform) { $metadata["platform"] = [string]$build.platform }
        if ($build.platform_app_id) { $metadata["platform_app_id"] = [string]$build.platform_app_id }
        if ($build.launch_url) { $metadata["launch_url"] = [string]$build.launch_url }
        if ($build.checksum) { $metadata["checksum"] = [string]$build.checksum }
        if ($build.size_bytes) { $metadata["size_bytes"] = [int64]$build.size_bytes }
        if ($build.download_url) { $metadata["download_url"] = [string]$build.download_url }
    }

    $metadata | ConvertTo-Json -Depth 20 | Set-Content -Path (Join-Path $dir "installed.json") -Encoding UTF8
}

function Get-BuildDownloadUrl($build) {
    if ($build.download_url) { return [string]$build.download_url }
    if ($build.file_url) { return [string]$build.file_url }
    if ($build.url) { return [string]$build.url }
    return ""
}

function Get-BuildDeliveryType($build) {
    if ($build -and $build.delivery_type) { return ([string]$build.delivery_type).ToLowerInvariant() }
    return "zip"
}

function Is-ExternalBuild($build) {
    return (Get-BuildDeliveryType $build) -eq "external_platform"
}

function Is-ZipBuild($build) {
    return (Get-BuildDeliveryType $build) -eq "zip" -and -not [string]::IsNullOrWhiteSpace((Get-BuildDownloadUrl $build))
}

function Get-BuildLaunchUrl($build) {
    if ($build.launch_url) { return [string]$build.launch_url }
    if ($build.platform_url) { return [string]$build.platform_url }
    if ($build.platform -and ([string]$build.platform).ToLowerInvariant() -eq "steam" -and $build.platform_app_id) {
        return "steam://run/$($build.platform_app_id)"
    }
    return ""
}

function Versions-Differ($installedVersion, $buildVersion) {
    if ([string]::IsNullOrWhiteSpace([string]$installedVersion)) { return $false }
    if ([string]::IsNullOrWhiteSpace([string]$buildVersion)) { return $false }
    return ([string]$installedVersion).Trim() -ne ([string]$buildVersion).Trim()
}

function Get-BuildVersion($build) {
    if ($build.version) { return [string]$build.version }
    if ($build.build_version) { return [string]$build.build_version }
    return "unknown"
}

function Get-BuildExePath($build) {
    if ($build.executable_path) { return [string]$build.executable_path }
    if ($build.exe_path) { return [string]$build.exe_path }
    if ($build.launch_path) { return [string]$build.launch_path }
    return ""
}

function Find-FirstExe($dir) {
    $exe = Get-ChildItem -Path $dir -Filter *.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($exe) { return $exe.FullName }
    return ""
}

function Format-Bytes($bytes) {
    if ($bytes -ge 1GB) { return "{0:N2} GB" -f ($bytes / 1GB) }
    if ($bytes -ge 1MB) { return "{0:N2} MB" -f ($bytes / 1MB) }
    if ($bytes -ge 1KB) { return "{0:N2} KB" -f ($bytes / 1KB) }
    return "$bytes B"
}

function Get-FileSha256($path) {
    if (-not (Test-Path $path)) { return "" }
    try { return (Get-FileHash -Path $path -Algorithm SHA256).Hash.ToLowerInvariant() } catch { return "" }
}

function Test-DownloadedZip($zipPath, $build) {
    if (-not (Test-Path $zipPath)) {
        throw "El ZIP descargado no existe."
    }

    $file = Get-Item $zipPath
    if ($file.Length -le 0) {
        throw "El ZIP descargado esta vacio."
    }

    if ($build -and $build.size_bytes) {
        $expectedSize = [int64]$build.size_bytes
        if ($expectedSize -gt 0 -and $file.Length -ne $expectedSize) {
            throw "Tamano invalido. Esperado $expectedSize bytes, recibido $($file.Length) bytes."
        }
    }

    if ($build -and $build.checksum) {
        $expected = ([string]$build.checksum).Trim().ToLowerInvariant()
        if ($expected.Length -eq 64) {
            $actual = Get-FileSha256 $zipPath
            if ($actual -ne $expected) {
                throw "SHA-256 invalido. Esperado $expected, recibido $actual."
            }
        }
    }

    try {
        $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
        $zip.Dispose()
    } catch {
        throw "El archivo descargado no es un ZIP valido: $($_.Exception.Message)"
    }
}

function Remove-DownloadedZip($zipPath) {
    try {
        if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    } catch {
        Log "No se pudo borrar ZIP temporal: $($_.Exception.Message)"
    }
}

function Resolve-CloudPath($hint, $slug) {
    $path = [string]$hint
    if ([string]::IsNullOrWhiteSpace($path)) { return "" }
    $path = $path.Replace("{slug}", $slug)
    $path = [Environment]::ExpandEnvironmentVariables($path)
    if (-not [System.IO.Path]::IsPathRooted($path)) {
        $path = Join-Path (Get-GameInstallDir $slug) $path
    }
    return $path
}

function Parse-CloudDateUtc($value) {
    if ($null -eq $value -or [string]::IsNullOrWhiteSpace([string]$value)) { return $null }
    try { return ([datetime]::Parse([string]$value)).ToUniversalTime() } catch { return $null }
}

function Should-ApplyCloudPull($localPath, $cloudSave, $conflictPolicy) {
    if (-not (Test-Path $localPath)) { return $true }

    $policy = if ($conflictPolicy) { ([string]$conflictPolicy).ToLowerInvariant() } else { "newest" }
    if ($policy -eq "client_wins" -or $policy -eq "manual") { return $false }
    if ($policy -eq "server_wins") { return $true }

    $remoteAt = Parse-CloudDateUtc $cloudSave.updated_at
    if (-not $remoteAt -and $cloudSave.save -and $cloudSave.save.mtime_utc) {
        $remoteAt = Parse-CloudDateUtc $cloudSave.save.mtime_utc
    }
    if (-not $remoteAt -and $cloudSave.metadata -and $cloudSave.metadata.local_mtime_utc) {
        $remoteAt = Parse-CloudDateUtc $cloudSave.metadata.local_mtime_utc
    }
    if (-not $remoteAt) { return $true }

    $localAt = (Get-Item $localPath).LastWriteTimeUtc
    return $remoteAt -ge $localAt
}


function Send-PresenceOnline {
    if ($script:OfflineMode) { return }
    $token = Get-Token
    if ($token) {
        try {
            Api-Post "/api/client/presence/" @{ status = "online" } $token | Out-Null
        } catch {}
    }
}

function Send-PresenceInGame($slug) {
    if ($script:OfflineMode) { return }
    $token = Get-Token
    if ($token -and -not [string]::IsNullOrWhiteSpace($slug)) {
        try {
            Api-Post "/api/client/presence/" @{ status = "in_game"; game_slug = $slug } $token | Out-Null
        } catch {}
    }
}

function Sync-CloudForGame($game, $direction, [bool]$automatic = $true) {
    if ($script:OfflineMode -or -not $game) { return }
    $token = Get-Token
    if (-not $token) { return }

    $slug = Get-GameSlug $game
    if ([string]::IsNullOrWhiteSpace($slug)) { return }

    try {
        $res = Api-Post "/api/client/cloud/configs/" @{ game_slug = $slug } $token
        if (-not $res.success -or -not $res.data.configs) { return }

        foreach ($cfg in $res.data.configs) {
            $mode = if ($cfg.sync_mode) { ([string]$cfg.sync_mode).ToLowerInvariant() } else { "api_slot" }
            $configKey = if ($cfg.config_key) { [string]$cfg.config_key } else { "default" }
            $autoSync = $true
            if ($null -ne $cfg.auto_sync) { $autoSync = To-Bool $cfg.auto_sync }

            if ($automatic -and -not $autoSync) {
                Log "Cloud auto desactivado para $slug/$configKey"
                continue
            }

            if ($mode -ne "file_path") {
                Log "Cloud $slug/$configKey usa modo $mode; lo sincroniza el juego por API."
                continue
            }

            $localPath = Resolve-CloudPath $cfg.local_path_hint $slug
            if ([string]::IsNullOrWhiteSpace($localPath)) { continue }

            if ($direction -eq "pull") {
                $pull = Api-Post "/api/client/cloud/pull/" @{ game_slug = $slug; config_key = $configKey; slot = 1 } $token
                if ($pull.success -and $pull.data.cloud_save -and $pull.data.cloud_save.found -and $pull.data.cloud_save.save -and $pull.data.cloud_save.save.content_base64) {
                    if ($automatic -and -not (Should-ApplyCloudPull $localPath $pull.data.cloud_save $cfg.conflict_policy)) {
                        Log "Cloud pull omitido por politica $($cfg.conflict_policy): $slug/$configKey"
                        continue
                    }
                    $bytes = [Convert]::FromBase64String([string]$pull.data.cloud_save.save.content_base64)
                    $dir = Split-Path $localPath -Parent
                    if (-not [string]::IsNullOrWhiteSpace($dir)) {
                        New-Item -ItemType Directory -Force -Path $dir | Out-Null
                    }
                    [System.IO.File]::WriteAllBytes($localPath, $bytes)
                    Log "Cloud pull OK: $slug/$configKey -> $localPath"
                }
            } elseif ($direction -eq "push") {
                if (-not (Test-Path $localPath)) {
                    Log "Cloud push omitido: no existe $localPath"
                    continue
                }

                $file = Get-Item $localPath
                $content = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes($localPath))
                $push = Api-Post "/api/client/cloud/push/" @{
                    game_slug = $slug
                    config_key = $configKey
                    slot = 1
                    content_base64 = $content
                    local_path = $localPath
                    mtime_utc = $file.LastWriteTimeUtc.ToString("o")
                    metadata = @{
                        source = "raclauncher"
                        sync_mode = "file_path"
                        local_path = $localPath
                        local_mtime_utc = $file.LastWriteTimeUtc.ToString("o")
                    }
                } $token

                if ($push.success) {
                    Log "Cloud push OK: $slug/$configKey"
                }
            }
        }
    } catch {
        Log "Cloud sync $direction fallido para ${slug}: $($_.Exception.Message)"
    }
}

function Check-RunningGames {
    if (-not $script:RunningGames) { return }

    $changed = $false
    $stillRunningSlug = $null
    $keys = @($script:RunningGames.Keys)

    foreach ($slug in $keys) {
        try {
            $entry = $script:RunningGames[$slug]
            $pidValue = [int]$entry.pid
            $proc = Get-Process -Id $pidValue -ErrorAction SilentlyContinue

            if ($null -eq $proc -or $proc.HasExited) {
                if ($entry.game) {
                    Sync-CloudForGame $entry.game "push"
                }
                $script:RunningGames.Remove($slug)
                $changed = $true
                Set-Status "$($entry.name) cerrado."
            } else {
                $stillRunningSlug = $slug
            }
        } catch {
            try { $script:RunningGames.Remove($slug) } catch {}
            $changed = $true
        }
    }

    if ($stillRunningSlug) {
        Send-PresenceInGame $stillRunningSlug
    } else {
        Send-PresenceOnline
    }

    if ($changed) {
        Refresh-Library
    }
}

function Install-Zip($zipPath, $installDir, $slug, $version, $exeRel, $name) {
    Set-Status "Verificando $name..."
    $script:ProgressBar.Style = "Marquee"

    try {
        Test-DownloadedZip $zipPath $script:CurrentBuild
        Set-Status "Extrayendo $name..."

        if (Test-Path $installDir) {
            Remove-Item $installDir -Recurse -Force
        }

        New-Item -ItemType Directory -Force -Path $installDir | Out-Null
        [System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $installDir)

        if ([string]::IsNullOrWhiteSpace($exeRel)) {
            $found = Find-FirstExe $installDir
            if ($found) {
                $exeRel = $found.Substring($installDir.Length).TrimStart("\","/")
            }
        }

        Write-InstalledVersion $slug $version $exeRel $script:CurrentGame $script:CurrentBuild
        Remove-DownloadedZip $zipPath

        $script:ProgressBar.Style = "Blocks"
        $script:ProgressBar.Value = 100

        Set-Status "$name instalado correctamente."
        Refresh-Library
    } catch {
        $script:ProgressBar.Style = "Blocks"
        $script:ProgressBar.Value = 0
        [System.Windows.Forms.MessageBox]::Show("No se pudo instalar/extractar el juego.`r`n$($_.Exception.Message)", "Error instalacion", "OK", "Error") | Out-Null
    } finally {
        Set-Busy $false
        if ($script:DownloadClient) {
            $script:DownloadClient.Dispose()
            $script:DownloadClient = $null
        }
    }
}

function Download-And-Install($game) {
    if ($script:OfflineMode) {
        [System.Windows.Forms.MessageBox]::Show("No se pueden descargar o instalar builds nuevas sin conexion.", "Offline", "OK", "Information") | Out-Null
        return
    }

    if ($script:IsBusy) {
        [System.Windows.Forms.MessageBox]::Show("Ya hay una operacion en curso.", "RacLauncher", "OK", "Information") | Out-Null
        return
    }

    $slug = Get-GameSlug $game
    $name = Get-GameName $game
    $build = Get-InstallBuild $game

    if (-not $build) {
        [System.Windows.Forms.MessageBox]::Show("Este juego no tiene build instalable todavia.", "Sin build", "OK", "Warning") | Out-Null
        return
    }

    if (Is-ExternalBuild $build) {
        [System.Windows.Forms.MessageBox]::Show("Este juego se abre desde $($build.platform). Usa Jugar para abrir la plataforma.", "Plataforma externa", "OK", "Information") | Out-Null
        return
    }

    $downloadUrl = Get-BuildDownloadUrl $build
    if ([string]::IsNullOrWhiteSpace($downloadUrl)) {
        [System.Windows.Forms.MessageBox]::Show("La build no trae download_url.", "Error", "OK", "Error") | Out-Null
        return
    }

    $version = Get-BuildVersion $build
    $exeRel = Get-BuildExePath $build
    $zipPath = Join-Path $DownloadsDir "$slug-$version.zip"
    $installDir = Get-GameInstallDir $slug

    try {
        if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    } catch {}

    Set-Busy $true
    $script:ProgressBar.Style = "Blocks"
    $script:ProgressBar.Value = 0
    Set-Status "Preparando descarga de $name..."

    $wc = New-Object System.Net.WebClient
    $script:DownloadClient = $wc

    $wc.add_DownloadProgressChanged({
        param($sender, $e)

        try {
            $percent = [Math]::Max(0, [Math]::Min(100, $e.ProgressPercentage))
            $script:ProgressBar.Value = $percent

            $received = Format-Bytes $e.BytesReceived
            $total = if ($e.TotalBytesToReceive -gt 0) { Format-Bytes $e.TotalBytesToReceive } else { "?" }

            $script:StatusLabel.Text = "Descargando $script:CurrentDownloadName... $percent% ($received / $total)"
        } catch {}
    })

    $wc.add_DownloadFileCompleted({
        param($sender, $e)

        if ($e.Cancelled) {
            Set-Status "Descarga cancelada."
            $script:ProgressBar.Value = 0
            Set-Busy $false
            return
        }

        if ($e.Error) {
            Set-Status "Error en descarga."
            $script:ProgressBar.Value = 0
            Set-Busy $false
            [System.Windows.Forms.MessageBox]::Show("No se pudo descargar la build.`r`n$($e.Error.Message)", "Error descarga", "OK", "Error") | Out-Null
            return
        }

        Install-Zip $script:CurrentZipPath $script:CurrentInstallDir $script:CurrentSlug $script:CurrentVersion $script:CurrentExeRel $script:CurrentDownloadName
    })

    $script:CurrentDownloadName = $name
    $script:CurrentZipPath = $zipPath
    $script:CurrentInstallDir = $installDir
    $script:CurrentSlug = $slug
    $script:CurrentVersion = $version
    $script:CurrentExeRel = $exeRel
    $script:CurrentGame = $game
    $script:CurrentBuild = $build

    try {
        Set-Status "Descargando $name..."
        $wc.DownloadFileAsync([Uri]$downloadUrl, $zipPath)
    } catch {
        Set-Busy $false
        $script:ProgressBar.Value = 0
        [System.Windows.Forms.MessageBox]::Show("No se pudo iniciar la descarga.`r`n$($_.Exception.Message)", "Error descarga", "OK", "Error") | Out-Null
    }
}

function Cancel-Download {
    if ($script:DownloadClient) {
        try {
            $script:DownloadClient.CancelAsync()
            Set-Status "Cancelando descarga..."
        } catch {}
    }
}



function Launch-Game($game) {
    $slug = Get-GameSlug $game
    $name = Get-GameName $game
    $build = Get-InstallBuild $game

    if ((-not $script:OfflineMode) -and $build -and (Is-ExternalBuild $build)) {
        $launchUrl = Get-BuildLaunchUrl $build
        if ([string]::IsNullOrWhiteSpace($launchUrl)) {
            [System.Windows.Forms.MessageBox]::Show("Esta version externa no trae URL de lanzamiento.", "Plataforma externa", "OK", "Error") | Out-Null
            return
        }

        Send-PresenceInGame $slug
        Set-Status "Abriendo $name en $($build.platform)..."
        Start-Process $launchUrl
        return
    }

    $installDir = Get-GameInstallDir $slug
    $installedFile = Join-Path $installDir "installed.json"

    if ($script:RunningGames.ContainsKey($slug)) {
        [System.Windows.Forms.MessageBox]::Show("Este juego ya esta ejecutandose.", "Ya esta abierto", "OK", "Information") | Out-Null
        return
    }

    if (-not (Test-Path $installedFile)) {
        [System.Windows.Forms.MessageBox]::Show("Este juego no esta instalado.", "No instalado", "OK", "Warning") | Out-Null
        return
    }

    $installed = Get-Content $installedFile -Raw | ConvertFrom-Json
    if ($script:OfflineMode) {
        $offlineOk = (To-Bool $game.offline_available) -or (To-Bool $installed.offline_available)
        if (-not $offlineOk) {
            [System.Windows.Forms.MessageBox]::Show("Este juego no permite ejecucion offline o no tiene licencia offline cacheada.", "Offline", "OK", "Warning") | Out-Null
            return
        }
    }

    $exeRel = [string]$installed.executable_path
    $exeFull = if ([System.IO.Path]::IsPathRooted($exeRel)) { $exeRel } else { Join-Path $installDir $exeRel }

    if (-not (Test-Path $exeFull)) {
        $found = Find-FirstExe $installDir
        if ($found) {
            $exeFull = $found
        } else {
            [System.Windows.Forms.MessageBox]::Show("No encontre el EXE del juego.", "Error", "OK", "Error") | Out-Null
            return
        }
    }

    Send-PresenceInGame $slug
    Sync-CloudForGame $game "pull"

    Set-Status "Ejecutando $name..."
    $token = Get-Token
    $launchArgs = @(
        "--jevzgames-api=$BaseUrl",
        "--jevzgames-game=$slug"
    )
    if ($token) { $launchArgs += "--jevzgames-token=$token" }
    $proc = Start-Process -FilePath $exeFull -WorkingDirectory (Split-Path $exeFull -Parent) -ArgumentList $launchArgs -PassThru

    $script:RunningGames[$slug] = @{
        pid = $proc.Id
        name = $name
        game = $game
        started_at = (Get-Date)
    }

    Refresh-Library
}

function Selected-Game {
    if ($script:GamesList.SelectedIndex -lt 0) {
        [System.Windows.Forms.MessageBox]::Show("Selecciona un juego primero.", "RacLauncher", "OK", "Information") | Out-Null
        return $null
    }
    return $script:Games[$script:GamesList.SelectedIndex]
}

function Get-OwnedGamesFromData($data) {
    $items = @()
    if ($data -and $data.owned_games) {
        foreach ($g in $data.owned_games) { $items += $g }
        return $items
    }

    if ($data -and $data.linked_games) {
        foreach ($g in $data.linked_games) { $items += $g }
    }

    if ($data -and $data.catalog) {
        foreach ($g in $data.catalog) {
            $owned = (To-Bool $g.has_license) -or (To-Bool $g.is_linked) -or (To-Bool $g.owned)
            if ($owned) {
                $already = $false
                foreach ($x in $items) {
                    if ((Get-GameSlug $x) -eq (Get-GameSlug $g)) { $already = $true }
                }
                if (-not $already) { $items += $g }
            }
        }
    }

    return $items
}

function Is-GameInstalled($slug) {
    return Test-Path (Join-Path (Get-GameInstallDir $slug) "installed.json")
}

function Update-ActionButtons {
    if ($script:IsBusy) { return }

    $game = $null
    if ($script:GamesList -and $script:GamesList.SelectedIndex -ge 0 -and $script:GamesList.SelectedIndex -lt $script:Games.Count) {
        $game = $script:Games[$script:GamesList.SelectedIndex]
    }

    $hasGame = $null -ne $game
    $slug = if ($hasGame) { Get-GameSlug $game } else { "" }
    $installed = $hasGame -and (Is-GameInstalled $slug)
    $installedMeta = if ($hasGame) { Load-InstalledMetadata $slug } else { $null }
    $build = if ($hasGame) { Get-InstallBuild $game } else { $null }
    $hasZipBuild = $hasGame -and (Is-ZipBuild $build)
    $hasExternalBuild = $hasGame -and (Is-ExternalBuild $build) -and -not [string]::IsNullOrWhiteSpace((Get-BuildLaunchUrl $build))
    $offlinePlayable = $installed -and ((To-Bool $game.offline_available) -or ($installedMeta -and (To-Bool $installedMeta.offline_available)))

    if ($script:InstallBtn) { $script:InstallBtn.Enabled = $hasGame -and (-not $script:OfflineMode) -and $hasZipBuild }
    if ($script:PlayBtn) { $script:PlayBtn.Enabled = $hasGame -and ((($installed -and ((-not $script:OfflineMode) -or $offlinePlayable)) -or ((-not $script:OfflineMode) -and $hasExternalBuild))) }
    if ($script:AchievementsBtn) { $script:AchievementsBtn.Enabled = $hasGame -and (-not $script:OfflineMode) }
    if ($script:RedeemBtn) { $script:RedeemBtn.Enabled = -not $script:OfflineMode }
    if ($script:GroupsBtn) { $script:GroupsBtn.Enabled = -not $script:OfflineMode }
    if ($script:FamilyBtn) { $script:FamilyBtn.Enabled = -not $script:OfflineMode }
    if ($script:CloudSyncBtn) { $script:CloudSyncBtn.Enabled = $hasGame -and (-not $script:OfflineMode) }
}

function Load-InstalledMetadata($slug) {
    $file = Join-Path (Get-GameInstallDir $slug) "installed.json"
    if (Test-Path $file) {
        try { return Get-Content $file -Raw | ConvertFrom-Json } catch { return $null }
    }
    return $null
}

function Render-Library($data, [bool]$offline) {
    $script:OfflineMode = $offline
    $script:Games = @()
    $script:GamesList.Items.Clear()

    foreach ($g in (Get-OwnedGamesFromData $data)) {
        $script:Games += $g
    }

    if ($script:Games.Count -eq 0) {
        $script:GamesList.Items.Add("No tienes juegos en tu biblioteca todavia.") | Out-Null
        if ($offline) {
            Set-Status "Modo offline: no hay biblioteca cacheada usable."
        } else {
            Set-Status "Biblioteca vacia. Obten una licencia desde la pagina web."
        }
        Update-ActionButtons
        return
    }

    foreach ($g in $script:Games) {
        $slug = Get-GameSlug $g
        $name = Get-GameName $g
        $status = Get-GameStatus $g
        $build = Get-InstallBuild $g
        $installedVersion = Read-InstalledVersion $slug
        $buildVersion = if ($build) { Get-BuildVersion $build } else { "" }

        $runTag = if ($script:RunningGames.ContainsKey($slug)) { "EN EJECUCION" } else { "Cerrado" }
        if ($build -and (Is-ExternalBuild $build)) {
            $platformName = if ($build.platform) { [string]$build.platform } else { "plataforma" }
            $tag = "Plataforma $platformName"
        } elseif ($installedVersion -and (Versions-Differ $installedVersion $buildVersion)) {
            $tag = "Update $installedVersion -> $buildVersion"
        } elseif ($installedVersion) {
            $tag = "Instalado $installedVersion"
        } elseif ($build -and (Is-ZipBuild $build)) {
            $tag = if ($offline) { "No instalado" } else { "Disponible para instalar" }
        } elseif ($build) {
            $tag = "Sin descarga local"
        } else {
            $tag = "Sin build"
        }

        $offlineTag = ""
        if ($offline) {
            $offlineTag = if ((To-Bool $g.offline_available) -and $installedVersion) { " | Offline OK" } else { " | Offline no" }
        }

        $line = "$name  |  $slug"
        if ($status) { $line += "  |  $status" }
        $line += "  |  $tag  |  $runTag$offlineTag"

        $script:GamesList.Items.Add($line) | Out-Null
    }

    if ($offline) {
        Set-Status "Modo offline: usando library-cache.json. Instalar y actualizar esta deshabilitado."
    } else {
        Set-Status "Biblioteca cargada."
    }

    Update-ActionButtons
}

function Start-AutoUpdates {
    if ($script:OfflineMode -or $script:IsBusy) { return }
    if (-not $script:Games -or $script:Games.Count -eq 0) { return }

    foreach ($game in $script:Games) {
        $slug = Get-GameSlug $game
        if ([string]::IsNullOrWhiteSpace($slug) -or -not (Is-GameInstalled $slug)) { continue }

        $build = Get-InstallBuild $game
        if (-not (Is-ZipBuild $build)) { continue }

        $installedVersion = Read-InstalledVersion $slug
        $buildVersion = Get-BuildVersion $build
        if (Versions-Differ $installedVersion $buildVersion) {
            Set-Status "Actualizacion automatica disponible para $(Get-GameName $game): $installedVersion -> $buildVersion"
            Download-And-Install $game
            return
        }
    }
}

function Refresh-Library {
    if ($script:IsBusy) { return }

    $token = Get-Token
    if (-not $token) {
        Show-Login
        return
    }

    try {
        Set-Status "Cargando biblioteca..."
        $res = Api-Post "/api/client/library/" @{} $token
        if (-not $res.success) { throw $res.message }

        Save-LibraryCache $res.data
        Render-Library $res.data $false

        try { Api-Post "/api/client/presence/" @{ status = "online" } $token | Out-Null } catch {}
        Refresh-ClientMe $token
        Start-AutoUpdates
        Check-LauncherUpdate
    } catch {
        $cache = Load-LibraryCache
        if ($cache -and $cache.data) {
            Render-Library $cache.data $true
        } else {
            Set-Status "No se pudo conectar y no hay cache offline. Revisa internet o inicia sesion otra vez."
        }
    }
}

function Do-Login {
    if ($script:IsBusy) { return }

    $identity = $script:UserBox.Text.Trim()
    $password = $script:PassBox.Text

    if ([string]::IsNullOrWhiteSpace($identity) -or [string]::IsNullOrWhiteSpace($password)) {
        [System.Windows.Forms.MessageBox]::Show("Pon usuario/email y contrasena.", "Login", "OK", "Warning") | Out-Null
        return
    }

    try {
        $script:LoginBtn.Enabled = $false
        Set-Status "Iniciando sesion..."

        $res = Api-Post "/api/client/login/" @{
            identity = $identity
            password = $password
            client_name = "RacLauncher $AppVersion"
        }

        if (-not $res.success) { throw $res.message }

        Save-Session ([string]$res.data.client_token) $res.data.user
        $script:PassBox.Text = ""
        Set-Status "Login OK."
        Show-Library
    } catch {
        Set-Status "Login fall."
        [System.Windows.Forms.MessageBox]::Show("No se pudo iniciar sesion.`r`n$($_.Exception.Message)", "Login error", "OK", "Error") | Out-Null
    } finally {
        $script:LoginBtn.Enabled = $true
    }
}

function Do-Logout {
    if ($script:IsBusy) { return }

    $token = Get-Token
    if ($token) {
        try { Api-Post "/api/client/logout/" @{} $token | Out-Null } catch {}
    }

    Clear-Session
    Clear-LibraryCache
    $script:GamesList.Items.Clear()
    $script:Games = @()
    if ($script:AchievementsForm -and -not $script:AchievementsForm.IsDisposed) { $script:AchievementsForm.Close() }
    $script:Achievements = @()
    $script:AchievementsCurrentGame = $null
    $script:UserBox.Text = ""
    $script:PassBox.Text = ""
    Set-Status "Sesion cerrada."
    Show-Login
}

function Reset-ClientStateForAccountChange {
    Clear-LibraryCache
    if ($script:GamesList) { $script:GamesList.Items.Clear() }
    $script:Games = @()
    if ($script:AchievementsForm -and -not $script:AchievementsForm.IsDisposed) { $script:AchievementsForm.Close() }
    $script:Achievements = @()
    $script:AchievementsCurrentGame = $null
}

function Format-SavedAccountLabel($account) {
    $name = Get-UserDisplayName $account.user
    if ([string]::IsNullOrWhiteSpace($name)) { $name = "Cuenta guardada" }
    $savedAt = if ($account.saved_at) { [string]$account.saved_at } else { "" }
    if ([string]::IsNullOrWhiteSpace($savedAt)) { return $name }
    return "$name  |  $savedAt"
}

function Open-AccountSwitcher {
    if ($script:IsBusy) { return }
    if ($script:RunningGames -and $script:RunningGames.Count -gt 0) {
        [System.Windows.Forms.MessageBox]::Show("Cierra los juegos abiertos antes de cambiar de cuenta.", "Cambiar cuenta", "OK", "Information") | Out-Null
        return
    }

    $script:AccountSwitcherAccounts = @(Load-Accounts)

    $dialog = New-Object System.Windows.Forms.Form
    $dialog.Text = "Cambiar cuenta"
    $dialog.Size = New-Object System.Drawing.Size(480, 330)
    $dialog.StartPosition = "CenterParent"
    $dialog.BackColor = [System.Drawing.Color]::FromArgb(30,30,36)
    $dialog.FormBorderStyle = "FixedDialog"
    $dialog.MaximizeBox = $false
    $dialog.MinimizeBox = $false

    $label = New-Object System.Windows.Forms.Label
    $label.Text = "Cuentas guardadas"
    $label.ForeColor = [System.Drawing.Color]::White
    $label.Font = New-Object System.Drawing.Font("Segoe UI", 11, [System.Drawing.FontStyle]::Bold)
    $label.Location = New-Object System.Drawing.Point(18, 16)
    $label.Size = New-Object System.Drawing.Size(430, 24)
    $dialog.Controls.Add($label)

    $list = New-Object System.Windows.Forms.ListBox
    $list.Location = New-Object System.Drawing.Point(18, 50)
    $list.Size = New-Object System.Drawing.Size(430, 150)
    $list.BackColor = [System.Drawing.Color]::FromArgb(42,42,50)
    $list.ForeColor = [System.Drawing.Color]::White
    foreach ($account in $script:AccountSwitcherAccounts) {
        $list.Items.Add((Format-SavedAccountLabel $account)) | Out-Null
    }
    $dialog.Controls.Add($list)

    $useBtn = New-Object System.Windows.Forms.Button
    $useBtn.Text = "Usar"
    $useBtn.Location = New-Object System.Drawing.Point(18, 220)
    $useBtn.Size = New-Object System.Drawing.Size(90, 32)
    $useBtn.Add_Click({
        if ($list.SelectedIndex -lt 0 -or $list.SelectedIndex -ge $script:AccountSwitcherAccounts.Count) {
            [System.Windows.Forms.MessageBox]::Show("Selecciona una cuenta.", "Cambiar cuenta", "OK", "Information") | Out-Null
            return
        }

        $account = $script:AccountSwitcherAccounts[$list.SelectedIndex]
        if (-not $account.client_token) {
            [System.Windows.Forms.MessageBox]::Show("La cuenta guardada no tiene token de cliente.", "Cambiar cuenta", "OK", "Warning") | Out-Null
            return
        }

        Reset-ClientStateForAccountChange
        Save-Session ([string]$account.client_token) $account.user
        $dialog.Close()
        Set-Status "Cuenta cambiada."
        Show-Library
    })
    $dialog.Controls.Add($useBtn)

    $addBtn = New-Object System.Windows.Forms.Button
    $addBtn.Text = "Agregar otra"
    $addBtn.Location = New-Object System.Drawing.Point(118, 220)
    $addBtn.Size = New-Object System.Drawing.Size(110, 32)
    $addBtn.Add_Click({
        Clear-Session
        Reset-ClientStateForAccountChange
        $dialog.Close()
        $script:UserBox.Text = ""
        $script:PassBox.Text = ""
        Set-Status "Inicia sesion con otra cuenta."
        Show-Login
    })
    $dialog.Controls.Add($addBtn)

    $removeBtn = New-Object System.Windows.Forms.Button
    $removeBtn.Text = "Borrar"
    $removeBtn.Location = New-Object System.Drawing.Point(238, 220)
    $removeBtn.Size = New-Object System.Drawing.Size(90, 32)
    $removeBtn.Add_Click({
        if ($list.SelectedIndex -lt 0 -or $list.SelectedIndex -ge $script:AccountSwitcherAccounts.Count) {
            [System.Windows.Forms.MessageBox]::Show("Selecciona una cuenta.", "Cambiar cuenta", "OK", "Information") | Out-Null
            return
        }

        $removed = $script:AccountSwitcherAccounts[$list.SelectedIndex]
        $remaining = @()
        for ($i = 0; $i -lt $script:AccountSwitcherAccounts.Count; $i++) {
            if ($i -ne $list.SelectedIndex) { $remaining += $script:AccountSwitcherAccounts[$i] }
        }
        Save-Accounts $remaining

        $session = Load-Session
        if ($session -and $session.client_token -and $removed.client_token -and ([string]$session.client_token) -eq ([string]$removed.client_token)) {
            Clear-Session
            Reset-ClientStateForAccountChange
            $dialog.Close()
            Set-Status "Cuenta guardada eliminada."
            Show-Login
            return
        }

        $script:AccountSwitcherAccounts = @(Load-Accounts)
        $list.Items.Clear()
        foreach ($account in $script:AccountSwitcherAccounts) {
            $list.Items.Add((Format-SavedAccountLabel $account)) | Out-Null
        }
    })
    $dialog.Controls.Add($removeBtn)

    $closeBtn = New-Object System.Windows.Forms.Button
    $closeBtn.Text = "Cerrar"
    $closeBtn.Location = New-Object System.Drawing.Point(358, 220)
    $closeBtn.Size = New-Object System.Drawing.Size(90, 32)
    $closeBtn.Add_Click({ $dialog.Close() })
    $dialog.Controls.Add($closeBtn)

    [void]$dialog.ShowDialog($form)
}

function Do-Install {
    $game = Selected-Game
    if ($game) { Download-And-Install $game }
}

function Do-Play {
    if ($script:IsBusy) { return }

    $game = Selected-Game
    if ($game) { Launch-Game $game }
}

function Format-AchievementLine($achievement) {
    $state = if (To-Bool $achievement.unlocked) { "Desbloqueado" } else { "Bloqueado" }
    $points = if ($achievement.points) { [int]$achievement.points } else { 0 }
    $progress = if ($achievement.progress_percent) { [double]$achievement.progress_percent } else { 0 }
    $title = if ($achievement.title) { [string]$achievement.title } else { "Logro" }
    return "$state | +$points pts | $progress% | $title"
}

function Refresh-AchievementsWindow {
    if ($script:OfflineMode) { return }
    if (-not $script:AchievementsList -or -not $script:AchievementsCurrentGame) { return }

    $token = Get-Token
    if (-not $token) { return }

    $slug = Get-GameSlug $script:AchievementsCurrentGame
    if ([string]::IsNullOrWhiteSpace($slug)) { return }

    try {
        $script:AchievementsStatusLabel.Text = "Cargando logros..."
        $res = Api-Post "/api/client/achievements/list/" @{ game_slug = $slug } $token
        if (-not $res.success) { throw $res.message }

        $script:Achievements = @()
        $script:AchievementsList.Items.Clear()

        if ($res.data.achievements) {
            foreach ($achievement in $res.data.achievements) {
                $script:Achievements += $achievement
                $script:AchievementsList.Items.Add((Format-AchievementLine $achievement)) | Out-Null
            }
        }

        if ($script:Achievements.Count -eq 0) {
            $script:AchievementsList.Items.Add("Este juego no tiene logros configurados.") | Out-Null
        }

        $script:AchievementsStatusLabel.Text = "Logros actualizados."
    } catch {
        $script:AchievementsStatusLabel.Text = "No se pudieron cargar los logros."
        [System.Windows.Forms.MessageBox]::Show("No se pudieron cargar los logros.`r`n$($_.Exception.Message)", "Logros", "OK", "Error") | Out-Null
    }
}

function Unlock-AchievementFromWindow {
    if ($script:OfflineMode) { return }
    if (-not $script:AchievementsCodeBox -or -not $script:AchievementsCurrentGame) { return }

    $code = $script:AchievementsCodeBox.Text.Trim()
    if ([string]::IsNullOrWhiteSpace($code)) {
        [System.Windows.Forms.MessageBox]::Show("Escribe el codigo interno del logro para probar el desbloqueo.", "Logros", "OK", "Information") | Out-Null
        return
    }

    $token = Get-Token
    if (-not $token) { return }

    $slug = Get-GameSlug $script:AchievementsCurrentGame
    try {
        $script:AchievementsStatusLabel.Text = "Desbloqueando logro..."
        $res = Api-Post "/api/client/achievements/unlock/" @{
            game_slug = $slug
            achievement_code = $code
            progress_data = @{ source = "raclauncher" }
        } $token
        if (-not $res.success) { throw $res.message }

        if ($res.data.just_unlocked) {
            [System.Windows.Forms.MessageBox]::Show("Logro desbloqueado: $($res.data.achievement.title)", "Logros", "OK", "Information") | Out-Null
        } else {
            [System.Windows.Forms.MessageBox]::Show("El logro ya estaba desbloqueado o no cambio de estado.", "Logros", "OK", "Information") | Out-Null
        }

        $script:AchievementsCodeBox.Text = ""
        Refresh-AchievementsWindow
    } catch {
        $script:AchievementsStatusLabel.Text = "No se pudo desbloquear."
        [System.Windows.Forms.MessageBox]::Show("No se pudo desbloquear el logro.`r`n$($_.Exception.Message)", "Logros", "OK", "Error") | Out-Null
    }
}

function Open-AchievementsWindow {
    if ($script:OfflineMode) {
        [System.Windows.Forms.MessageBox]::Show("Los logros requieren conexion. En modo offline solo puedes abrir juegos instalados.", "Offline", "OK", "Information") | Out-Null
        return
    }

    $game = Selected-Game
    if (-not $game) { return }

    if ($script:AchievementsForm -and -not $script:AchievementsForm.IsDisposed) {
        $script:AchievementsCurrentGame = $game
        $script:AchievementsForm.Text = "RacLauncher - Logros - $(Get-GameName $game)"
        $script:AchievementsForm.Focus()
        Refresh-AchievementsWindow
        return
    }

    $achievements = New-Object System.Windows.Forms.Form
    $achievements.Text = "RacLauncher - Logros - $(Get-GameName $game)"
    $achievements.Size = New-Object System.Drawing.Size(760, 520)
    $achievements.StartPosition = "CenterParent"
    $achievements.BackColor = [System.Drawing.Color]::FromArgb(25,25,30)
    $achievements.Font = New-Object System.Drawing.Font("Segoe UI", 9)

    $title = New-Object System.Windows.Forms.Label
    $title.Text = "Logros de $(Get-GameName $game)"
    $title.ForeColor = [System.Drawing.Color]::White
    $title.Font = New-Object System.Drawing.Font("Segoe UI", 14, [System.Drawing.FontStyle]::Bold)
    $title.Location = New-Object System.Drawing.Point(18, 16)
    $title.Size = New-Object System.Drawing.Size(700, 30)
    $achievements.Controls.Add($title)

    $script:AchievementsList = New-Object System.Windows.Forms.ListBox
    $script:AchievementsList.Location = New-Object System.Drawing.Point(20, 58)
    $script:AchievementsList.Size = New-Object System.Drawing.Size(700, 285)
    $script:AchievementsList.BackColor = [System.Drawing.Color]::FromArgb(38,38,45)
    $script:AchievementsList.ForeColor = [System.Drawing.Color]::White
    $script:AchievementsList.BorderStyle = "FixedSingle"
    $achievements.Controls.Add($script:AchievementsList)

    $hint = New-Object System.Windows.Forms.Label
    $hint.Text = "Para pruebas, escribe el codigo interno configurado en Admin. La lista no expone codigos."
    $hint.ForeColor = [System.Drawing.Color]::LightGray
    $hint.Location = New-Object System.Drawing.Point(20, 356)
    $hint.Size = New-Object System.Drawing.Size(700, 22)
    $achievements.Controls.Add($hint)

    $script:AchievementsCodeBox = New-Object System.Windows.Forms.TextBox
    $script:AchievementsCodeBox.Location = New-Object System.Drawing.Point(20, 385)
    $script:AchievementsCodeBox.Size = New-Object System.Drawing.Size(420, 26)
    $achievements.Controls.Add($script:AchievementsCodeBox)

    $unlockBtn = New-Object System.Windows.Forms.Button
    $unlockBtn.Text = "Desbloquear"
    $unlockBtn.Location = New-Object System.Drawing.Point(455, 382)
    $unlockBtn.Size = New-Object System.Drawing.Size(120, 32)
    $unlockBtn.Add_Click({ Unlock-AchievementFromWindow })
    $achievements.Controls.Add($unlockBtn)

    $refreshBtn = New-Object System.Windows.Forms.Button
    $refreshBtn.Text = "Refrescar"
    $refreshBtn.Location = New-Object System.Drawing.Point(590, 382)
    $refreshBtn.Size = New-Object System.Drawing.Size(130, 32)
    $refreshBtn.Add_Click({ Refresh-AchievementsWindow })
    $achievements.Controls.Add($refreshBtn)

    $script:AchievementsStatusLabel = New-Object System.Windows.Forms.Label
    $script:AchievementsStatusLabel.Text = "Listo."
    $script:AchievementsStatusLabel.ForeColor = [System.Drawing.Color]::LightGray
    $script:AchievementsStatusLabel.Location = New-Object System.Drawing.Point(20, 428)
    $script:AchievementsStatusLabel.Size = New-Object System.Drawing.Size(700, 22)
    $achievements.Controls.Add($script:AchievementsStatusLabel)

    $script:AchievementsForm = $achievements
    $script:AchievementsCurrentGame = $game
    $script:Achievements = @()
    $achievements.Add_Shown({ Refresh-AchievementsWindow })
    [void]$achievements.ShowDialog($form)
}

function Open-AppData {
    Start-Process explorer.exe $AppData
}

function Format-ChatTime($value) {
    if (-not $value) { return "" }
    try { return ([DateTime]$value).ToString("HH:mm") } catch { return [string]$value }
}

function Refresh-ChatConversations {
    if ($script:OfflineMode) { return }
    $token = Get-Token
    if (-not $token -or -not $script:ChatConversationsList) { return }

    try {
        $res = Api-Post "/api/client/messages/conversations/" @{} $token
        if (-not $res.success) { throw $res.message }

        $script:ChatConversations = @()
        $script:ChatConversationsList.Items.Clear()

        if ($res.data.conversations) {
            foreach ($conv in $res.data.conversations) {
                $script:ChatConversations += $conv
                $user = $conv.conversation_user
                $name = if ($user.display_name) { [string]$user.display_name } else { [string]$user.username }
                $unread = [int]($conv.unread_count)
                $last = ""
                if ($conv.last_message -and $conv.last_message.message) {
                    $last = ([string]$conv.last_message.message).Replace("`r", " ").Replace("`n", " ")
                    if ($last.Length -gt 34) { $last = $last.Substring(0, 34) + "..." }
                }
                $prefix = if ($unread -gt 0) { "($unread) " } else { "" }
                $script:ChatConversationsList.Items.Add("$prefix$name - $last") | Out-Null
            }
        }

        if ($script:ChatConversations.Count -eq 0) {
            $script:ChatConversationsList.Items.Add("Sin conversaciones") | Out-Null
        }
    } catch {
        if ($script:ChatStatusLabel) { $script:ChatStatusLabel.Text = "No se pudo refrescar mensajes." }
    }
}

function Load-ChatThread($userId) {
    if ($script:OfflineMode) { return }
    $token = Get-Token
    if (-not $token -or -not $script:ChatMessagesBox) { return }

    try {
        $res = Api-Post "/api/client/messages/thread/" @{ user_id = [int]$userId; limit = 50 } $token
        if (-not $res.success) { throw $res.message }

        $script:CurrentChatUserId = [int]$userId
        $other = $res.data.conversation_user
        $display = if ($other.display_name) { [string]$other.display_name } else { [string]$other.username }
        if ($script:ChatTitleLabel) { $script:ChatTitleLabel.Text = "Chat con $display" }

        $lines = New-Object System.Collections.Generic.List[string]
        if ($res.data.messages) {
            foreach ($m in $res.data.messages) {
                $who = if ($m.is_outgoing) { "Tu" } elseif ($m.sender_display_name) { [string]$m.sender_display_name } else { [string]$m.sender_username }
                $body = ([string]$m.message).Replace("`r", "").TrimEnd()
                $time = Format-ChatTime $m.created_at
                $lines.Add("[$time] ${who}: $body")
            }
        }

        $script:ChatMessagesBox.Text = ($lines -join "`r`n")
        $script:ChatMessagesBox.SelectionStart = $script:ChatMessagesBox.Text.Length
        $script:ChatMessagesBox.ScrollToCaret()
        if ($script:ChatStatusLabel) { $script:ChatStatusLabel.Text = "Mensajes actualizados." }
    } catch {
        if ($script:ChatStatusLabel) { $script:ChatStatusLabel.Text = "No se pudo cargar la conversacion." }
    }
}

function Open-ChatBySelectedConversation {
    if (-not $script:ChatConversationsList -or $script:ChatConversationsList.SelectedIndex -lt 0) { return }
    $idx = $script:ChatConversationsList.SelectedIndex
    if ($idx -ge $script:ChatConversations.Count) { return }
    $conv = $script:ChatConversations[$idx]
    if ($conv -and $conv.conversation_user -and $conv.conversation_user.id) {
        Load-ChatThread ([int]$conv.conversation_user.id)
    }
}

function Open-ChatByUserId {
    if (-not $script:ChatUserIdBox) { return }
    $id = 0
    if ([int]::TryParse($script:ChatUserIdBox.Text.Trim(), [ref]$id) -and $id -gt 0) {
        Load-ChatThread $id
    } else {
        [System.Windows.Forms.MessageBox]::Show("Indica un ID de usuario valido.", "Mensajes", "OK", "Warning") | Out-Null
    }
}

function Send-ChatMessage {
    if ($script:OfflineMode) { return }
    if (-not $script:CurrentChatUserId) {
        [System.Windows.Forms.MessageBox]::Show("Selecciona una conversacion o abre un usuario por ID.", "Mensajes", "OK", "Information") | Out-Null
        return
    }

    $text = $script:ChatInputBox.Text.Trim()
    if ([string]::IsNullOrWhiteSpace($text)) { return }

    $token = Get-Token
    if (-not $token) { return }

    try {
        $res = Api-Post "/api/client/messages/send/" @{ to_user_id = [int]$script:CurrentChatUserId; message = $text } $token
        if (-not $res.success) { throw $res.message }
        $script:ChatInputBox.Text = ""
        Load-ChatThread ([int]$script:CurrentChatUserId)
        Refresh-ChatConversations
    } catch {
        [System.Windows.Forms.MessageBox]::Show("No se pudo enviar el mensaje.`r`n$($_.Exception.Message)", "Mensajes", "OK", "Error") | Out-Null
    }
}

function Open-MessagesWindow {
    if ($script:OfflineMode) {
        [System.Windows.Forms.MessageBox]::Show("Mensajes requiere conexion. En modo offline solo puedes abrir juegos instalados.", "Offline", "OK", "Information") | Out-Null
        return
    }

    if ($script:ChatForm -and -not $script:ChatForm.IsDisposed) {
        $script:ChatForm.Focus()
        return
    }

$chat = New-Object System.Windows.Forms.Form
$chat.Text = "RacLauncher - Mensajes"
$chat.Size = New-Object System.Drawing.Size(880, 560)
$chat.StartPosition = "CenterParent"
$chat.BackColor = [System.Drawing.Color]::FromArgb(25,25,30)
$chat.Font = New-Object System.Drawing.Font("Segoe UI", 9)
$chatTextFont = New-Object System.Drawing.Font("Segoe UI Emoji", 9)
$script:ChatForm = $chat

    $leftTitle = New-Object System.Windows.Forms.Label
    $leftTitle.Text = "Conversaciones"
    $leftTitle.ForeColor = [System.Drawing.Color]::White
    $leftTitle.Location = New-Object System.Drawing.Point(12, 12)
    $leftTitle.Size = New-Object System.Drawing.Size(160, 24)
    $chat.Controls.Add($leftTitle)

    $refresh = New-Object System.Windows.Forms.Button
    $refresh.Text = "Refrescar"
    $refresh.Location = New-Object System.Drawing.Point(175, 8)
    $refresh.Size = New-Object System.Drawing.Size(86, 28)
    $refresh.Add_Click({ Refresh-ChatConversations })
    $chat.Controls.Add($refresh)

    $script:ChatConversationsList = New-Object System.Windows.Forms.ListBox
    $script:ChatConversationsList.Location = New-Object System.Drawing.Point(12, 45)
    $script:ChatConversationsList.Size = New-Object System.Drawing.Size(250, 430)
$script:ChatConversationsList.BackColor = [System.Drawing.Color]::FromArgb(38,38,45)
$script:ChatConversationsList.ForeColor = [System.Drawing.Color]::White
$script:ChatConversationsList.Font = $chatTextFont
$script:ChatConversationsList.Add_SelectedIndexChanged({ Open-ChatBySelectedConversation })
$chat.Controls.Add($script:ChatConversationsList)

    $script:ChatTitleLabel = New-Object System.Windows.Forms.Label
    $script:ChatTitleLabel.Text = "Selecciona una conversacion"
    $script:ChatTitleLabel.ForeColor = [System.Drawing.Color]::White
    $script:ChatTitleLabel.Location = New-Object System.Drawing.Point(280, 12)
    $script:ChatTitleLabel.Size = New-Object System.Drawing.Size(260, 24)
    $chat.Controls.Add($script:ChatTitleLabel)

    $script:ChatUserIdBox = New-Object System.Windows.Forms.TextBox
    $script:ChatUserIdBox.Location = New-Object System.Drawing.Point(550, 10)
    $script:ChatUserIdBox.Size = New-Object System.Drawing.Size(90, 24)
    $script:ChatUserIdBox.Text = ""
    $chat.Controls.Add($script:ChatUserIdBox)

    $openUser = New-Object System.Windows.Forms.Button
    $openUser.Text = "Abrir ID"
    $openUser.Location = New-Object System.Drawing.Point(650, 8)
    $openUser.Size = New-Object System.Drawing.Size(80, 28)
    $openUser.Add_Click({ Open-ChatByUserId })
    $chat.Controls.Add($openUser)

    $script:ChatMessagesBox = New-Object System.Windows.Forms.TextBox
    $script:ChatMessagesBox.Location = New-Object System.Drawing.Point(280, 45)
    $script:ChatMessagesBox.Size = New-Object System.Drawing.Size(560, 365)
    $script:ChatMessagesBox.Multiline = $true
    $script:ChatMessagesBox.ReadOnly = $true
$script:ChatMessagesBox.ScrollBars = "Vertical"
$script:ChatMessagesBox.BackColor = [System.Drawing.Color]::FromArgb(38,38,45)
$script:ChatMessagesBox.ForeColor = [System.Drawing.Color]::White
$script:ChatMessagesBox.Font = $chatTextFont
$chat.Controls.Add($script:ChatMessagesBox)

$script:ChatInputBox = New-Object System.Windows.Forms.TextBox
$script:ChatInputBox.Location = New-Object System.Drawing.Point(280, 425)
$script:ChatInputBox.Size = New-Object System.Drawing.Size(455, 26)
$script:ChatInputBox.Font = $chatTextFont
$script:ChatInputBox.ImeMode = "On"
$script:ChatInputBox.ShortcutsEnabled = $true
$script:ChatInputBox.Add_KeyDown({
        if ($_.KeyCode -eq "Enter") {
            $_.SuppressKeyPress = $true
            Send-ChatMessage
        }
    })
    $chat.Controls.Add($script:ChatInputBox)

    $sendBtn = New-Object System.Windows.Forms.Button
    $sendBtn.Text = "Enviar"
    $sendBtn.Location = New-Object System.Drawing.Point(745, 422)
    $sendBtn.Size = New-Object System.Drawing.Size(95, 32)
    $sendBtn.Add_Click({ Send-ChatMessage })
    $chat.Controls.Add($sendBtn)

    $script:ChatStatusLabel = New-Object System.Windows.Forms.Label
    $script:ChatStatusLabel.Text = "Listo."
    $script:ChatStatusLabel.ForeColor = [System.Drawing.Color]::LightGray
    $script:ChatStatusLabel.Location = New-Object System.Drawing.Point(280, 465)
    $script:ChatStatusLabel.Size = New-Object System.Drawing.Size(560, 24)
    $chat.Controls.Add($script:ChatStatusLabel)

    $script:ChatTimer = New-Object System.Windows.Forms.Timer
    $script:ChatTimer.Interval = 10000
    $script:ChatTimer.Add_Tick({
        Refresh-ChatConversations
        if ($script:CurrentChatUserId) { Load-ChatThread ([int]$script:CurrentChatUserId) }
    })
    $script:ChatTimer.Start()

    $chat.Add_FormClosing({
        try {
            if ($script:ChatTimer) {
                $script:ChatTimer.Stop()
                $script:ChatTimer.Dispose()
                $script:ChatTimer = $null
            }
        } catch {}
        $script:ChatForm = $null
    })

    Refresh-ChatConversations
    [void]$chat.Show($form)
}

function Redeem-CodeFromLauncher {
    if ($script:OfflineMode) {
        [System.Windows.Forms.MessageBox]::Show("Canjear codigos requiere conexion.", "Offline", "OK", "Information") | Out-Null
        return
    }

    $token = Get-Token
    if (-not $token) { return }

    $code = [Microsoft.VisualBasic.Interaction]::InputBox("Codigo de juego u objeto:", "Canjear codigo", "")
    $code = $code.Trim()
    if ([string]::IsNullOrWhiteSpace($code)) { return }

    try {
        $res = Api-Post "/api/client/redeem/" @{ code = $code } $token
        if (-not $res.success) { throw $res.message }
        [System.Windows.Forms.MessageBox]::Show("Codigo canjeado correctamente.", "Canje", "OK", "Information") | Out-Null
        Refresh-Library
    } catch {
        [System.Windows.Forms.MessageBox]::Show("No se pudo canjear el codigo.`r`n$($_.Exception.Message)", "Canje", "OK", "Error") | Out-Null
    }
}

function Open-InfoListWindow($title, $text) {
    $info = New-Object System.Windows.Forms.Form
    $info.Text = $title
    $info.Size = New-Object System.Drawing.Size(680, 480)
    $info.StartPosition = "CenterParent"
    $info.BackColor = [System.Drawing.Color]::FromArgb(25,25,30)
    $info.Font = New-Object System.Drawing.Font("Segoe UI", 9)

    $box = New-Object System.Windows.Forms.TextBox
    $box.Multiline = $true
    $box.ReadOnly = $true
    $box.ScrollBars = "Vertical"
    $box.BackColor = [System.Drawing.Color]::FromArgb(38,38,45)
    $box.ForeColor = [System.Drawing.Color]::White
    $box.Location = New-Object System.Drawing.Point(16, 16)
    $box.Size = New-Object System.Drawing.Size(630, 390)
    $box.Text = $text
    $info.Controls.Add($box)

    $close = New-Object System.Windows.Forms.Button
    $close.Text = "Cerrar"
    $close.Location = New-Object System.Drawing.Point(540, 410)
    $close.Size = New-Object System.Drawing.Size(105, 32)
    $close.Add_Click({ $info.Close() })
    $info.Controls.Add($close)

    [void]$info.ShowDialog($form)
}

function Open-GroupsWindow {
    if ($script:OfflineMode) {
        [System.Windows.Forms.MessageBox]::Show("Grupos requiere conexion.", "Offline", "OK", "Information") | Out-Null
        return
    }

    $token = Get-Token
    if (-not $token) { return }

    try {
        $res = Api-Post "/api/client/groups/" @{} $token
        if (-not $res.success) { throw $res.message }
        $lines = New-Object System.Collections.Generic.List[string]
        $lines.Add("Mis grupos")
        $lines.Add("----------")
        if ($res.data.my_groups) {
            foreach ($group in $res.data.my_groups) {
                $lines.Add("$($group.name) [$($group.visibility)] - miembros: $($group.member_count)")
            }
        } else {
            $lines.Add("Sin grupos.")
        }
        $lines.Add("")
        $lines.Add("Grupos publicos")
        $lines.Add("---------------")
        if ($res.data.public_groups) {
            foreach ($group in $res.data.public_groups) {
                $lines.Add("$($group.name) / $($group.slug) - miembros: $($group.member_count)")
            }
        } else {
            $lines.Add("Sin grupos publicos.")
        }
        Open-InfoListWindow "RacLauncher - Grupos" ($lines -join "`r`n")
    } catch {
        [System.Windows.Forms.MessageBox]::Show("No se pudieron cargar grupos.`r`n$($_.Exception.Message)", "Grupos", "OK", "Error") | Out-Null
    }
}

function Open-FamilyWindow {
    if ($script:OfflineMode) {
        [System.Windows.Forms.MessageBox]::Show("Family Sharing requiere conexion.", "Offline", "OK", "Information") | Out-Null
        return
    }

    $token = Get-Token
    if (-not $token) { return }

    try {
        $res = Api-Post "/api/client/family/" @{} $token
        if (-not $res.success) { throw $res.message }
        $lines = New-Object System.Collections.Generic.List[string]
        $lines.Add("Family Sharing")
        $lines.Add("--------------")
        if ($res.data.family) {
            foreach ($row in $res.data.family) {
                $lines.Add("Owner: $($row.owner_username) | Miembro: $($row.member_username) | Estado: $($row.status)")
            }
        } else {
            $lines.Add("No hay relaciones familiares configuradas.")
        }
        Open-InfoListWindow "RacLauncher - Family Sharing" ($lines -join "`r`n")
    } catch {
        [System.Windows.Forms.MessageBox]::Show("No se pudo cargar Family Sharing.`r`n$($_.Exception.Message)", "Family Sharing", "OK", "Error") | Out-Null
    }
}

function Sync-SelectedGameCloud {
    if ($script:OfflineMode) {
        [System.Windows.Forms.MessageBox]::Show("Cloud sync requiere conexion.", "Offline", "OK", "Information") | Out-Null
        return
    }

    $game = Selected-Game
    if (-not $game) { return }

    $result = [System.Windows.Forms.MessageBox]::Show(
        "Si = subir save local al servidor.`r`nNo = bajar save del servidor al archivo local.",
        "Cloud sync",
        "YesNoCancel",
        "Question"
    )

    if ($result -eq "Yes") {
        Sync-CloudForGame $game "push" $false
        [System.Windows.Forms.MessageBox]::Show("Sincronizacion de subida ejecutada. Revisa el log para detalles.", "Cloud sync", "OK", "Information") | Out-Null
    } elseif ($result -eq "No") {
        Sync-CloudForGame $game "pull" $false
        [System.Windows.Forms.MessageBox]::Show("Sincronizacion de bajada ejecutada. Revisa el log para detalles.", "Cloud sync", "OK", "Information") | Out-Null
    }
}

function Find-LauncherUpdateSource($stagingDir) {
    $scriptFile = Get-ChildItem -Path $stagingDir -Filter "RacLauncher.ps1" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($scriptFile) { return $scriptFile.Directory.FullName }
    return $stagingDir
}

function Apply-LauncherRelease($release) {
    if (-not $release -or -not $release.download_url) { return }

    $version = [string]$release.version
    $zipPath = Join-Path $UpdatesDir ("RacLauncher-" + $version + ".zip")
    $stagingDir = Join-Path $UpdatesDir ("RacLauncher-" + $version)

    try {
        if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
        if (Test-Path $stagingDir) { Remove-Item $stagingDir -Recurse -Force }
        New-Item -ItemType Directory -Force -Path $stagingDir | Out-Null

        Set-Status "Descargando update del launcher $version..."
        $wc = New-Object System.Net.WebClient
        $wc.DownloadFile([string]$release.download_url, $zipPath)

        if ($release.checksum_sha256) {
            $expected = ([string]$release.checksum_sha256).Trim().ToLowerInvariant()
            $actual = Get-FileSha256 $zipPath
            if ($expected.Length -eq 64 -and $actual -ne $expected) {
                throw "SHA-256 invalido para update del launcher."
            }
        }

        [System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $stagingDir)
        Remove-DownloadedZip $zipPath
        $sourceDir = Find-LauncherUpdateSource $stagingDir
        $cmdPath = Join-Path $UpdatesDir "Apply-RacLauncherUpdate.cmd"
        $runCmd = Join-Path $LauncherDir "Run-RacLauncher.cmd"
        $restartLine = if (Test-Path $runCmd) { 'start "" "' + $runCmd + '"' } else { 'start "" powershell -ExecutionPolicy Bypass -File "' + (Join-Path $LauncherDir "RacLauncher.ps1") + '"' }

        @(
            "@echo off",
            "timeout /t 2 /nobreak >nul",
            "xcopy `"$sourceDir\*`" `"$LauncherDir\`" /E /Y /I >nul",
            $restartLine
        ) | Set-Content -Path $cmdPath -Encoding ASCII

        [System.Windows.Forms.MessageBox]::Show("El launcher se actualizara ahora y se reiniciara.", "Update launcher", "OK", "Information") | Out-Null
        Start-Process -FilePath $cmdPath
        $form.Close()
    } catch {
        [System.Windows.Forms.MessageBox]::Show("No se pudo actualizar el launcher.`r`n$($_.Exception.Message)", "Update launcher", "OK", "Error") | Out-Null
    }
}

function Check-LauncherUpdate {
    if ($script:OfflineMode -or $script:LauncherUpdateChecked) { return }
    $script:LauncherUpdateChecked = $true

    $token = Get-Token
    if (-not $token) { return }

    try {
        $res = Api-Post "/api/client/launcher/update-check/" @{ current_version = $AppVersion; os = "windows" } $token
        if (-not $res.success -or -not $res.data.update_available -or -not $res.data.latest) { return }

        $release = $res.data.latest
        $answer = [System.Windows.Forms.MessageBox]::Show(
            "Hay una nueva version del launcher: $($release.version).`r`nQuieres descargarla y aplicarla ahora?",
            "Update launcher",
            "YesNo",
            "Information"
        )
        if ($answer -eq "Yes") {
            Apply-LauncherRelease $release
        }
    } catch {
        Log "No se pudo comprobar update del launcher: $($_.Exception.Message)"
    }
}


Save-Config

# UI
$form = New-Object System.Windows.Forms.Form
$form.Text = "RacLauncher Beta 0.1.12"
$form.Size = New-Object System.Drawing.Size(960, 620)
$form.StartPosition = "CenterScreen"
$form.BackColor = [System.Drawing.Color]::FromArgb(20, 20, 24)
$form.Font = New-Object System.Drawing.Font("Segoe UI", 9)
$form.MinimumSize = New-Object System.Drawing.Size(860, 560)

# Login Panel
$script:LoginPanel = New-Object System.Windows.Forms.Panel
$script:LoginPanel.Dock = "Fill"
$script:LoginPanel.BackColor = [System.Drawing.Color]::FromArgb(18, 18, 22)
$form.Controls.Add($script:LoginPanel)

$loginTitle = New-Object System.Windows.Forms.Label
$loginTitle.Text = "RacLauncher"
$loginTitle.ForeColor = [System.Drawing.Color]::White
$loginTitle.Font = New-Object System.Drawing.Font("Segoe UI", 28, [System.Drawing.FontStyle]::Bold)
$loginTitle.TextAlign = "MiddleCenter"
$loginTitle.Location = New-Object System.Drawing.Point(0, 70)
$loginTitle.Size = New-Object System.Drawing.Size(940, 60)
$script:LoginPanel.Controls.Add($loginTitle)

$loginSub = New-Object System.Windows.Forms.Label
$loginSub.Text = "Inicia sesion para acceder a tu biblioteca"
$loginSub.ForeColor = [System.Drawing.Color]::LightGray
$loginSub.Font = New-Object System.Drawing.Font("Segoe UI", 11)
$loginSub.TextAlign = "MiddleCenter"
$loginSub.Location = New-Object System.Drawing.Point(0, 130)
$loginSub.Size = New-Object System.Drawing.Size(940, 30)
$script:LoginPanel.Controls.Add($loginSub)

$loginCard = New-Object System.Windows.Forms.Panel
$loginCard.BackColor = [System.Drawing.Color]::FromArgb(35, 35, 42)
$loginCard.Location = New-Object System.Drawing.Point(310, 195)
$loginCard.Size = New-Object System.Drawing.Size(340, 235)
$script:LoginPanel.Controls.Add($loginCard)

$userLbl = New-Object System.Windows.Forms.Label
$userLbl.Text = "Usuario o correo"
$userLbl.ForeColor = [System.Drawing.Color]::White
$userLbl.Location = New-Object System.Drawing.Point(25, 25)
$userLbl.Size = New-Object System.Drawing.Size(280, 22)
$loginCard.Controls.Add($userLbl)

$script:UserBox = New-Object System.Windows.Forms.TextBox
$script:UserBox.Location = New-Object System.Drawing.Point(25, 50)
$script:UserBox.Size = New-Object System.Drawing.Size(290, 26)
$loginCard.Controls.Add($script:UserBox)

$passLbl = New-Object System.Windows.Forms.Label
$passLbl.Text = "Contrasena"
$passLbl.ForeColor = [System.Drawing.Color]::White
$passLbl.Location = New-Object System.Drawing.Point(25, 90)
$passLbl.Size = New-Object System.Drawing.Size(280, 22)
$loginCard.Controls.Add($passLbl)

$script:PassBox = New-Object System.Windows.Forms.TextBox
$script:PassBox.Location = New-Object System.Drawing.Point(25, 115)
$script:PassBox.Size = New-Object System.Drawing.Size(290, 26)
$script:PassBox.UseSystemPasswordChar = $true
$script:PassBox.Add_KeyDown({
    if ($_.KeyCode -eq "Enter") { Do-Login }
})
$loginCard.Controls.Add($script:PassBox)

$script:LoginBtn = New-Object System.Windows.Forms.Button
$script:LoginBtn.Text = "Iniciar sesion"
$script:LoginBtn.Location = New-Object System.Drawing.Point(25, 158)
$script:LoginBtn.Size = New-Object System.Drawing.Size(290, 36)
$script:LoginBtn.Add_Click({ Do-Login })
$loginCard.Controls.Add($script:LoginBtn)

$script:LoginStatusLabel = New-Object System.Windows.Forms.Label
$script:LoginStatusLabel.Text = "Listo."
$script:LoginStatusLabel.ForeColor = [System.Drawing.Color]::LightGray
$script:LoginStatusLabel.TextAlign = "MiddleCenter"
$script:LoginStatusLabel.Location = New-Object System.Drawing.Point(25, 202)
$script:LoginStatusLabel.Size = New-Object System.Drawing.Size(290, 20)
$loginCard.Controls.Add($script:LoginStatusLabel)

$loginFooter = New-Object System.Windows.Forms.Label
$loginFooter.Text = "$AppVersion  -  $BaseUrl"
$loginFooter.ForeColor = [System.Drawing.Color]::Gray
$loginFooter.TextAlign = "MiddleCenter"
$loginFooter.Location = New-Object System.Drawing.Point(0, 520)
$loginFooter.Size = New-Object System.Drawing.Size(940, 25)
$script:LoginPanel.Controls.Add($loginFooter)

# Library Panel
$script:LibraryPanel = New-Object System.Windows.Forms.Panel
$script:LibraryPanel.Dock = "Fill"
$script:LibraryPanel.BackColor = [System.Drawing.Color]::FromArgb(25,25,30)
$script:LibraryPanel.Visible = $false
$form.Controls.Add($script:LibraryPanel)

$topBar = New-Object System.Windows.Forms.Panel
$topBar.BackColor = [System.Drawing.Color]::FromArgb(32,32,38)
$topBar.Dock = "Top"
$topBar.Height = 72
$script:LibraryPanel.Controls.Add($topBar)

$title = New-Object System.Windows.Forms.Label
$title.Text = "RacLauncher"
$title.ForeColor = [System.Drawing.Color]::White
$title.Font = New-Object System.Drawing.Font("Segoe UI", 18, [System.Drawing.FontStyle]::Bold)
$title.Location = New-Object System.Drawing.Point(18, 15)
$title.Size = New-Object System.Drawing.Size(260, 40)
$topBar.Controls.Add($title)

$script:LoggedLabel = New-Object System.Windows.Forms.Label
$script:LoggedLabel.Text = "Cuenta:"
$script:LoggedLabel.ForeColor = [System.Drawing.Color]::LightGray
$script:LoggedLabel.Location = New-Object System.Drawing.Point(290, 25)
$script:LoggedLabel.Size = New-Object System.Drawing.Size(250, 24)
$topBar.Controls.Add($script:LoggedLabel)

$script:RefreshBtn = New-Object System.Windows.Forms.Button
$script:RefreshBtn.Text = "Refrescar"
$script:RefreshBtn.Location = New-Object System.Drawing.Point(548, 20)
$script:RefreshBtn.Size = New-Object System.Drawing.Size(82, 32)
$script:RefreshBtn.Add_Click({ Refresh-Library })
$topBar.Controls.Add($script:RefreshBtn)

$script:SwitchAccountBtn = New-Object System.Windows.Forms.Button
$script:SwitchAccountBtn.Text = "Cambiar"
$script:SwitchAccountBtn.Location = New-Object System.Drawing.Point(638, 20)
$script:SwitchAccountBtn.Size = New-Object System.Drawing.Size(82, 32)
$script:SwitchAccountBtn.Add_Click({ Open-AccountSwitcher })
$topBar.Controls.Add($script:SwitchAccountBtn)

$script:OpenFolderBtn = New-Object System.Windows.Forms.Button
$script:OpenFolderBtn.Text = "Carpeta"
$script:OpenFolderBtn.Location = New-Object System.Drawing.Point(728, 20)
$script:OpenFolderBtn.Size = New-Object System.Drawing.Size(78, 32)
$script:OpenFolderBtn.Add_Click({ Open-AppData })
$topBar.Controls.Add($script:OpenFolderBtn)

$script:LogoutBtn = New-Object System.Windows.Forms.Button
$script:LogoutBtn.Text = "Salir"
$script:LogoutBtn.Location = New-Object System.Drawing.Point(814, 20)
$script:LogoutBtn.Size = New-Object System.Drawing.Size(78, 32)
$script:LogoutBtn.Add_Click({ Do-Logout })
$topBar.Controls.Add($script:LogoutBtn)

$leftLabel = New-Object System.Windows.Forms.Label
$leftLabel.Text = "Biblioteca"
$leftLabel.ForeColor = [System.Drawing.Color]::White
$leftLabel.Font = New-Object System.Drawing.Font("Segoe UI", 13, [System.Drawing.FontStyle]::Bold)
$leftLabel.Location = New-Object System.Drawing.Point(22, 90)
$leftLabel.Size = New-Object System.Drawing.Size(200, 28)
$script:LibraryPanel.Controls.Add($leftLabel)

$script:GamesList = New-Object System.Windows.Forms.ListBox
$script:GamesList.Location = New-Object System.Drawing.Point(22, 125)
$script:GamesList.Size = New-Object System.Drawing.Size(650, 300)
$script:GamesList.BackColor = [System.Drawing.Color]::FromArgb(38,38,45)
$script:GamesList.ForeColor = [System.Drawing.Color]::White
$script:GamesList.BorderStyle = "FixedSingle"
$script:GamesList.Add_SelectedIndexChanged({ Update-ActionButtons })
$script:LibraryPanel.Controls.Add($script:GamesList)

$actionsPanel = New-Object System.Windows.Forms.Panel
$actionsPanel.BackColor = [System.Drawing.Color]::FromArgb(35,35,42)
$actionsPanel.Location = New-Object System.Drawing.Point(700, 125)
$actionsPanel.Size = New-Object System.Drawing.Size(210, 300)
$script:LibraryPanel.Controls.Add($actionsPanel)

$actionTitle = New-Object System.Windows.Forms.Label
$actionTitle.Text = "Acciones"
$actionTitle.ForeColor = [System.Drawing.Color]::White
$actionTitle.Font = New-Object System.Drawing.Font("Segoe UI", 12, [System.Drawing.FontStyle]::Bold)
$actionTitle.Location = New-Object System.Drawing.Point(18, 15)
$actionTitle.Size = New-Object System.Drawing.Size(170, 25)
$actionsPanel.Controls.Add($actionTitle)

$script:InstallBtn = New-Object System.Windows.Forms.Button
$script:InstallBtn.Text = "Instalar / Actualizar"
$script:InstallBtn.Location = New-Object System.Drawing.Point(20, 52)
$script:InstallBtn.Size = New-Object System.Drawing.Size(170, 36)
$script:InstallBtn.Add_Click({ Do-Install })
$actionsPanel.Controls.Add($script:InstallBtn)

$script:PlayBtn = New-Object System.Windows.Forms.Button
$script:PlayBtn.Text = "Jugar"
$script:PlayBtn.Location = New-Object System.Drawing.Point(20, 90)
$script:PlayBtn.Size = New-Object System.Drawing.Size(170, 36)
$script:PlayBtn.Add_Click({ Do-Play })
$actionsPanel.Controls.Add($script:PlayBtn)

$script:MessagesBtn = New-Object System.Windows.Forms.Button
$script:MessagesBtn.Text = "Mensajes"
$script:MessagesBtn.Location = New-Object System.Drawing.Point(20, 128)
$script:MessagesBtn.Size = New-Object System.Drawing.Size(170, 36)
$script:MessagesBtn.Add_Click({ Open-MessagesWindow })
$actionsPanel.Controls.Add($script:MessagesBtn)

$script:AchievementsBtn = New-Object System.Windows.Forms.Button
$script:AchievementsBtn.Text = "Logros"
$script:AchievementsBtn.Location = New-Object System.Drawing.Point(20, 166)
$script:AchievementsBtn.Size = New-Object System.Drawing.Size(170, 36)
$script:AchievementsBtn.Add_Click({ Open-AchievementsWindow })
$actionsPanel.Controls.Add($script:AchievementsBtn)

$script:RedeemBtn = New-Object System.Windows.Forms.Button
$script:RedeemBtn.Text = "Canjear"
$script:RedeemBtn.Location = New-Object System.Drawing.Point(20, 212)
$script:RedeemBtn.Size = New-Object System.Drawing.Size(80, 30)
$script:RedeemBtn.Add_Click({ Redeem-CodeFromLauncher })
$actionsPanel.Controls.Add($script:RedeemBtn)

$script:CloudSyncBtn = New-Object System.Windows.Forms.Button
$script:CloudSyncBtn.Text = "Cloud"
$script:CloudSyncBtn.Location = New-Object System.Drawing.Point(110, 212)
$script:CloudSyncBtn.Size = New-Object System.Drawing.Size(80, 30)
$script:CloudSyncBtn.Add_Click({ Sync-SelectedGameCloud })
$actionsPanel.Controls.Add($script:CloudSyncBtn)

$script:GroupsBtn = New-Object System.Windows.Forms.Button
$script:GroupsBtn.Text = "Grupos"
$script:GroupsBtn.Location = New-Object System.Drawing.Point(20, 250)
$script:GroupsBtn.Size = New-Object System.Drawing.Size(80, 30)
$script:GroupsBtn.Add_Click({ Open-GroupsWindow })
$actionsPanel.Controls.Add($script:GroupsBtn)

$script:FamilyBtn = New-Object System.Windows.Forms.Button
$script:FamilyBtn.Text = "Family"
$script:FamilyBtn.Location = New-Object System.Drawing.Point(110, 250)
$script:FamilyBtn.Size = New-Object System.Drawing.Size(80, 30)
$script:FamilyBtn.Add_Click({ Open-FamilyWindow })
$actionsPanel.Controls.Add($script:FamilyBtn)

$progressLabel = New-Object System.Windows.Forms.Label
$progressLabel.Text = "Descarga / instalacion"
$progressLabel.ForeColor = [System.Drawing.Color]::White
$progressLabel.Location = New-Object System.Drawing.Point(22, 440)
$progressLabel.Size = New-Object System.Drawing.Size(200, 20)
$script:LibraryPanel.Controls.Add($progressLabel)

$script:ProgressBar = New-Object System.Windows.Forms.ProgressBar
$script:ProgressBar.Location = New-Object System.Drawing.Point(22, 465)
$script:ProgressBar.Size = New-Object System.Drawing.Size(650, 22)
$script:ProgressBar.Minimum = 0
$script:ProgressBar.Maximum = 100
$script:LibraryPanel.Controls.Add($script:ProgressBar)

$script:CancelBtn = New-Object System.Windows.Forms.Button
$script:CancelBtn.Text = "Cancelar descarga"
$script:CancelBtn.Location = New-Object System.Drawing.Point(700, 455)
$script:CancelBtn.Size = New-Object System.Drawing.Size(210, 36)
$script:CancelBtn.Enabled = $false
$script:CancelBtn.Add_Click({ Cancel-Download })
$script:LibraryPanel.Controls.Add($script:CancelBtn)

$script:StatusLabel = New-Object System.Windows.Forms.Label
$script:StatusLabel.Text = "Listo."
$script:StatusLabel.ForeColor = [System.Drawing.Color]::LightGray
$script:StatusLabel.Location = New-Object System.Drawing.Point(22, 500)
$script:StatusLabel.Size = New-Object System.Drawing.Size(890, 20)
$script:LibraryPanel.Controls.Add($script:StatusLabel)

$script:LogBox = New-Object System.Windows.Forms.TextBox
$script:LogBox.Location = New-Object System.Drawing.Point(22, 525)
$script:LogBox.Size = New-Object System.Drawing.Size(890, 35)
$script:LogBox.Multiline = $true
$script:LogBox.ScrollBars = "Vertical"
$script:LogBox.BackColor = [System.Drawing.Color]::FromArgb(38,38,45)
$script:LogBox.ForeColor = [System.Drawing.Color]::LightGray
$script:LibraryPanel.Controls.Add($script:LogBox)

$script:Games = @()
$script:IsBusy = $false
$script:OfflineMode = $false
$script:DownloadClient = $null
$script:RunningGames = @{}
$script:ChatConversations = @()
$script:CurrentChatUserId = $null
$script:Achievements = @()
$script:AchievementsCurrentGame = $null
$script:AchievementsForm = $null
$script:AccountSwitcherAccounts = @()
$script:LauncherUpdateChecked = $false

$script:GameStateTimer = New-Object System.Windows.Forms.Timer
$script:GameStateTimer.Interval = 5000
$script:GameStateTimer.Add_Tick({ Check-RunningGames })
$script:GameStateTimer.Start()

$form.Add_Shown({
    $session = Load-Session
    if ($session -and $session.client_token) {
        Show-Library
    } else {
        Show-Login
    }
})

$form.Add_FormClosing({
    if ($script:IsBusy) {
        $result = [System.Windows.Forms.MessageBox]::Show(
            "Hay una descarga o instalacion en curso. Cerrar igual?",
            "RacLauncher",
            "YesNo",
            "Warning"
        )

        if ($result -ne "Yes") {
            $_.Cancel = $true
        } else {
            try {
                if ($script:DownloadClient) { $script:DownloadClient.CancelAsync() }
            } catch {}
        }
    }

    try {
        if ($script:GameStateTimer) { $script:GameStateTimer.Stop() }
        Send-PresenceOnline
    } catch {}
})

[void]$form.ShowDialog()
