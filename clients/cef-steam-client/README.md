# JevzGames CEF Client

Launcher local tipo Steam para Windows usando Chromium Embedded Framework mediante CefSharp.

## Requisitos locales

- Windows.
- .NET SDK 6 o superior.
- XAMPP corriendo `http://jevzgames.local`.
- Cliente activado en `/superroot/?section=features`.

## Ejecutar en local

```bat
cd C:\xampp\jevzgames-infra\clients\cef-steam-client
.\run-local.cmd
```

La primera ejecucion descarga CefSharp/CEF desde NuGet. Esa descarga es pesada.

Si prefieres usar el script PowerShell y Windows lo bloquea por politica de ejecucion, ejecutalo solo para esta vez con:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\run-local.ps1
```

## Publicar build portable

```bat
cd C:\xampp\jevzgames-infra\clients\cef-steam-client
.\publish-local.cmd
```

Alternativa PowerShell de una sola ejecucion:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\publish-local.ps1
```

El resultado queda en:

```text
clients/cef-steam-client/dist/
```

## Flujo del launcher

1. Lee `GET /api/client/config/`.
2. Hace login en `POST /api/client/login/`.
3. Guarda el `client_token` en `%AppData%\JevzGamesClient\client-state.json`.
4. Lee biblioteca en `POST /api/client/library/`.
5. Si un juego tiene `install_build`, muestra Instalar/Jugar.
6. Descarga el `.zip`.
7. Verifica SHA-256 si el backend lo entrego.
8. Extrae en `%AppData%\JevzGamesClient\games\<slug>`.
9. Ejecuta el `executable_path` configurado en Admin.

## Formato del ZIP del juego

El `.zip` debe contener el ejecutable en la ruta indicada al subir la build.

Ejemplo simple:

```text
JumpFall.zip
  JumpFall.exe
  UnityPlayer.dll
  JumpFall_Data/
```

En Admin configura:

```text
Ejecutable dentro del zip: JumpFall.exe
```

Ejemplo con carpeta:

```text
JumpFall.zip
  Windows/
    JumpFall.exe
    UnityPlayer.dll
    JumpFall_Data/
```

En Admin configura:

```text
Ejecutable dentro del zip: Windows/JumpFall.exe
```

## Backend relacionado

Admin:

```text
/admin/?section=games
```

En `Builds instalables` sube el `.zip`, version, canal y ejecutable relativo.

API:

```text
GET  /api/client/config/
POST /api/client/login/
POST /api/client/library/
POST /api/client/inventory/
POST /api/client/redeem/
POST /api/client/logout/
```

`/api/client/library/` devuelve `install_build` por juego:

```json
{
  "download_url": "http://jevzgames.local/uploads/builds/jumpfall/jumpfall-0.1.0-development.zip",
  "version": "0.1.0",
  "channel": "development",
  "checksum": "sha256...",
  "executable_path": "JumpFall.exe"
}
```
