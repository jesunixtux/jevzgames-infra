# API

Las APIs viven bajo:

```text
public/api/
```

Cada endpoint usa su propia carpeta con `index.php`.

## Formato de respuesta

```json
{
  "success": true,
  "message": "OK",
  "data": {}
}
```

Usa:

```php
api_response(true, 'OK', [
    'key' => 'value',
]);
```

## Endpoints disponibles

### GET `/api/status/`

Devuelve estado basico de la plataforma.

Ejemplo:

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "app": {
      "name": "JevzGames Infra",
      "environment": "development",
      "base_url": "http://localhost"
    },
    "installed": true,
    "database": {
      "configured": true,
      "connected": true
    },
    "php": {
      "version": "8.2.0"
    },
    "time": "2026-06-14T12:00:00+00:00"
  }
}
```

### POST `/api/game-info/`

Devuelve la configuracion publica del juego asociado a una public key activa.

Request:

```json
{
  "public_key": "jvg_pk_..."
}
```

### POST `/api/version-check/`

Compara la version del cliente con la version registrada del juego.

Request:

```json
{
  "public_key": "jvg_pk_...",
  "version": "0.1.0"
}
```

### POST `/api/oauth/device-code/`

Inicia el vinculo OAuth tipo device-code para juegos o clientes sin navegador embebido.

Request:

```json
{
  "public_key": "jvg_pk_..."
}
```

Respuesta relevante:

```json
{
  "success": true,
  "data": {
    "device_code": "jvg_dc_...",
    "user_code": "ABCD-1234",
    "verification_uri_complete": "http://jevzgames.local/oauth/authorize/?user_code=ABCD-1234",
    "expires_in": 600,
    "interval": 3
  }
}
```

### POST `/api/oauth/token/`

La app compatible llama este endpoint cada `interval` segundos hasta que el usuario apruebe o expire.

Request:

```json
{
  "public_key": "jvg_pk_...",
  "device_code": "jvg_dc_..."
}
```

Mientras espera, responde `success: false` con `message: authorization_pending`. Al aprobar, responde `success: true` con `access_token` y la licencia activa del juego. El `access_token` queda persistente hasta que el usuario desvincule el juego o el token sea revocado.

### POST `/api/user-profile/`

Endpoint autenticado para validar el token y obtener el usuario vinculado al juego.

Cada llamada autenticada con token de juego refresca la presencia del usuario como `in_game` para ese juego.

Header:

```text
Authorization: Bearer jvg_at_...
```

Body:

```json
{}
```

La respuesta incluye `license` si el usuario tiene licencia activa para el juego.

### POST `/api/game-license/check/`

Endpoint autenticado para DRM basico. Confirma usuario, juego, licencia activa y ultima build instalable.

Header:

```text
Authorization: Bearer jvg_at_...
```

Body:

```json
{}
```

### POST `/api/game-database/status/`

Endpoint autenticado. Devuelve estado sanitizado de la base dedicada configurada para el juego del token.

Header:

```text
Authorization: Bearer jvg_at_...
```

### POST `/api/player-data/save/`

Guarda datos JSON del jugador. Usa la base dedicada si esta activa y conectable; si no, usa `game_player_data` en la base principal.

```json
{
  "key": "save_slot_1",
  "data": {
    "level": 3,
    "coins": 77
  }
}
```

### POST `/api/player-data/get/`

Recupera datos JSON del jugador.

```json
{
  "key": "save_slot_1"
}
```

### POST `/api/achievements/list/`

Lista logros activos/ocultos del juego y el progreso del jugador autenticado.
Cada logro puede incluir:

- `image_path`: imagen del logro desbloqueado.
- `locked_image_path`: imagen para mostrarlo bloqueado.

### POST `/api/achievements/progress/`

Actualiza progreso de un logro configurable.

```json
{
  "achievement_code": "first_run",
  "mode": "unlock",
  "progress": 1,
  "progress_data": {
    "source": "game"
  }
}
```

`mode` puede ser `set`, `add` o `unlock`.

### POST `/api/achievements/unlock/`

Atajo para desbloquear un logro.

```json
{
  "achievement_code": "first_run"
}
```

### POST `/api/inventory/list/`

Endpoint autenticado con token Bearer de juego. Lista el inventario del jugador para ese juego.

Header:

```text
Authorization: Bearer jvg_at_...
```

### POST `/api/redeem/`

Endpoint autenticado con token Bearer de juego. Canjea un codigo y agrega la recompensa al inventario o licencia el juego configurado.

```json
{
  "code": "JVG-XXXX-XXXX"
}
```

`reward_json` de un codigo puede usar:

```json
{
  "item": "skin_blue",
  "quantity": 1,
  "name": "Skin azul",
  "image_path": "/uploads/items/skin_blue.png"
}
```

O multiples items:

```json
{
  "items": [
    {"item_key": "skin_blue", "quantity": 1, "name": "Skin azul"},
    {"item_key": "coins", "quantity": 100, "type": "currency"}
  ]
}
```

O licencias de juego:

```json
{"game_slug":"jumpfall"}
```

### POST `/api/cloud-saves/config/`

Endpoint autenticado. Lista las configuraciones cloud activas del juego del token.

Header:

```text
Authorization: Bearer jvg_at_...
```

### POST `/api/cloud-saves/save/`

Guarda una partida cloud en la configuracion y slot indicados.

```json
{
  "config_key": "default",
  "slot": 1,
  "save": {
    "level": 3,
    "coins": 77
  },
  "metadata": {
    "source": "game"
  }
}
```

### POST `/api/cloud-saves/list/`

Lista las partidas cloud del jugador autenticado para el juego del token.

### POST `/api/cloud-saves/get/`

Recupera una partida cloud.

```json
{
  "config_key": "default",
  "slot": 1
}
```

### GET `/api/client/config/`

Devuelve la configuracion publica del cliente tipo Steam. Depende de Superroot > Funciones > Cliente.

Incluye endpoints y politica local offline:

```json
{
  "enabled": true,
  "name": "JevzGames Client",
  "endpoints": {
    "login": "/api/client/login/",
    "me": "/api/client/me/",
    "library": "/api/client/library/",
    "presence": "/api/client/presence/",
    "achievements_list": "/api/client/achievements/list/",
    "achievements_unlock": "/api/client/achievements/unlock/",
    "messages_conversations": "/api/client/messages/conversations/"
  },
  "offline_cache": {
    "schema_version": 1,
    "local_files": {
      "session": "session.json",
      "library": "library-cache.json",
      "installed_game": "games/<slug>/installed.json"
    }
  }
}
```

### POST `/api/client/login/`

Login para un launcher propio. Si la cuenta esta suspendida responde `403` con el mensaje de suspension.

```json
{
  "identity": "usuario_o_email",
  "password": "secret",
  "client_name": "JevzGames Desktop"
}
```

Devuelve `client_token`.

El cliente puede guardar localmente el token, usuario basico y cache de biblioteca, pero nunca debe guardar la contrasena.

### GET|POST `/api/client/me/`

Header:

```text
Authorization: Bearer jvg_ct_...
```

Devuelve el usuario autenticado y su presencia actual:

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "username": "admin",
      "email": "admin@example.com",
      "display_name": "admin",
      "status": "active"
    },
    "presence": {
      "status": "online",
      "game_slug": null,
      "game_name": null
    }
  }
}
```

### POST `/api/client/library/`

Header:

```text
Authorization: Bearer jvg_ct_...
```

Devuelve:

- `owned_games`: biblioteca real del launcher. Solo juegos vinculados o con licencia activa.
- `linked_games`: alias antiguo para compatibilidad.
- `catalog`: catalogo visible para explorar u obtener juegos. Solo incluye juegos con `visibility=public`.
- `offline_cache`: reglas y nombres de archivos locales recomendados.

Cada juego puede incluir `install_build` si Admin subio un `.zip` instalable o registro una version externa:

```json
{
  "owned_games": [
    {
      "id": 1,
      "name": "JumpFall",
      "slug": "jumpfall",
      "status": "published",
      "visibility": "public",
      "current_version": "0.1.0",
      "has_license": true,
      "is_linked": true,
      "offline_allowed": true,
      "offline_available": true,
      "last_played_at": "2026-07-01 12:30:00",
      "offline_entitlement": {
        "available": true,
        "reason": "ok",
        "cache_version": 1,
        "license_key_preview": "jvg_lic_abcd...123456"
      },
      "install_build": {
        "version": "0.1.0",
        "channel": "stable",
        "delivery_type": "zip",
        "download_url": "http://jevzgames.local/uploads/builds/jumpfall/jumpfall-0.1.0-stable.zip",
        "checksum": "sha256...",
        "size_bytes": 123456,
        "executable_path": "JumpFall.exe"
      }
    }
  ],
  "install_build": {
    "version": "0.1.0",
    "channel": "development",
    "delivery_type": "external_platform",
    "platform": "steam",
    "platform_app_id": "480",
    "launch_url": "steam://run/480",
    "download_url": null
  },
  "zip_install_build": {
    "version": "0.1.0",
    "channel": "development",
    "delivery_type": "zip",
    "download_url": "http://jevzgames.local/uploads/builds/jumpfall/jumpfall-0.1.0-development.zip",
    "checksum": "sha256...",
    "size_bytes": 123456,
    "executable_path": "JumpFall.exe"
  }
}
```

Modo offline:

- Permitido: ejecutar juegos ya instalados si existen en `owned_games` y `offline_available=true`.
- No permitido: descargar juegos nuevos sin conexion.
- No permitido: obtener licencias nuevas sin conexion.
- Cache local recomendada: `session.json`, `library-cache.json`, `games/<slug>/installed.json`.
- No guardar contrasenas localmente.
- Si `delivery_type=external_platform`, el launcher debe abrir `launch_url` online y no guardarlo como instalacion offline.

### GET|POST `/api/client/achievements/list/`

Lista los logros del juego para el usuario autenticado con token del launcher. Requiere que el juego este en `owned_games`.

Header:

```text
Authorization: Bearer jvg_ct_...
```

Request:

```json
{
  "game_slug": "jumpfall"
}
```

Respuesta:

```json
{
  "success": true,
  "data": {
    "game": {
      "id": 1,
      "name": "JumpFall",
      "slug": "jumpfall"
    },
    "achievements": [
      {
        "id": 10,
        "title": "First run",
        "description": "Open the game once.",
        "image_url": "http://jevzgames.local/uploads/achievements/first.png",
        "points": 10,
        "unlocked": false,
        "progress_percent": 0
      }
    ]
  }
}
```

Este endpoint no devuelve el codigo interno del logro para que el launcher pueda mostrar la UI sin exponerlo.

### POST `/api/client/achievements/unlock/`

Desbloquea un logro desde un juego lanzado por el cliente. El juego manda el codigo configurado en Admin; la respuesta trae datos listos para un toast tipo Steam.

Header:

```text
Authorization: Bearer jvg_ct_...
```

Body:

```json
{
  "game_slug": "jumpfall",
  "achievement_code": "first_run",
  "progress_data": {
    "source": "unity"
  }
}
```

Respuesta:

```json
{
  "success": true,
  "data": {
    "just_unlocked": true,
    "achievement": {
      "title": "First run",
      "description": "Open the game once.",
      "image_url": "http://jevzgames.local/uploads/achievements/first.png",
      "points": 10,
      "unlocked": true
    },
    "toast": {
      "enabled": true,
      "position": "bottom",
      "title": "First run",
      "description": "Open the game once.",
      "image_url": "http://jevzgames.local/uploads/achievements/first.png",
      "points": 10
    }
  }
}
```

El SDK Unity en `sdks/unity/JevzGamesApi.unitypackage` usa estos endpoints cuando el launcher pasa `--jevzgames-api`, `--jevzgames-token` y `--jevzgames-game`.

### POST `/api/client/obtain-game/`

Endpoint legado. Crea la licencia del juego para el usuario autenticado y lo agrega a biblioteca. El launcher 1.1 no debe usarlo: la licencia normal se obtiene desde la web.

Header:

```text
Authorization: Bearer jvg_ct_...
```

Body:

```json
{"game_id": 1}
```

Tambien acepta:

```json
{"slug": "jumpfall"}
```

### POST `/api/client/inventory/`

Lista inventario completo del usuario autenticado por `client_token`.

### POST `/api/client/redeem/`

Canjea codigos desde el cliente.

### POST `/api/client/presence/`

Actualiza la presencia del usuario autenticado por `client_token`. No guarda historial, solo la fila actual de `user_presence`.

Header:

```text
Authorization: Bearer jvg_ct_...
```

Request para conectado:

```json
{
  "status": "online"
}
```

Request para mostrar juego activo:

```json
{
  "status": "in_game",
  "game_slug": "jumpfall"
}
```

Tambien acepta `game_id`. Para limpiar juego activo envia `{"status":"online"}` o revoca el token con logout.

Acepta `online`, `in_game` y `offline`. Cuando se envia `in_game`, preferir `game_slug`. El juego debe estar vinculado o licenciado en la biblioteca del usuario. El backend actualiza `user_presence` y `user_games.last_played_at`.

### GET|POST `/api/client/presence/status/`

Consulta presencia con Bearer token. Sin body devuelve la presencia del usuario autenticado. Opcionalmente acepta `user_id` para consultar otro usuario.

```json
{
  "success": true,
  "data": {
    "presence": {
      "status": "in_game",
      "connected": true,
      "game_slug": "jumpfall",
      "game_name": "JumpFall"
    }
  }
}
```

### POST `/api/client/messages/conversations/`

Lista conversaciones del usuario autenticado.

```json
{
  "success": true,
  "data": {
    "conversations": [
      {
        "thread_id": 10,
        "conversation_user": {
          "id": 2,
          "username": "player2",
          "display_name": "Player 2"
        },
        "last_message": {
          "id": 99,
          "message": "Hola",
          "message_html": "Hola",
          "is_outgoing": false,
          "created_at": "2026-07-01 12:00:00"
        },
        "unread_count": 1
      }
    ],
    "unread_count": 1
  }
}
```

### POST `/api/client/messages/thread/`

Body:

```json
{
  "user_id": 2,
  "limit": 50,
  "after_id": 0,
  "before_id": 0
}
```

Devuelve mensajes con `message` y `message_html`. El cliente debe mostrar `message_html` o escapar `message`.

### POST `/api/client/messages/send/`

Body:

```json
{
  "to_user_id": 2,
  "message": "Hola"
}
```

Valida Bearer token, destinatario activo, politicas sociales, longitud 1-2000 y usa prepared statements.

### POST `/api/client/messages/mark-read/`

Body:

```json
{
  "conversation_user_id": 2
}
```

Marca como leidos los mensajes recibidos en esa conversacion.

## Client APIs 1.2

### POST `/api/client/cloud/configs/`

Devuelve configuraciones cloud activas para un juego de la biblioteca del usuario. `sync_mode=api_slot` conserva el modo viejo por slots; `sync_mode=file_path` indica al launcher una ruta local sugerida para sincronizar archivos de guardado.

```json
{
  "game_slug": "jumpfall"
}
```

### POST `/api/client/cloud/push/`

Sube una partida desde el launcher. Para `file_path`, el launcher envia el archivo en base64:

```json
{
  "game_slug": "jumpfall",
  "config_key": "default",
  "slot": 1,
  "content_base64": "...",
  "local_path": "%USERPROFILE%/Saved Games/JumpFall/save.dat",
  "mtime_utc": "2026-07-05T12:00:00Z"
}
```

### POST `/api/client/cloud/pull/`

Recupera el slot cloud para escribirlo en el archivo local configurado:

```json
{
  "game_slug": "jumpfall",
  "config_key": "default",
  "slot": 1
}
```

### POST `/api/client/groups/`

Lista grupos del usuario y grupos publicos para el launcher:

```json
{
  "my_groups": [],
  "public_groups": []
}
```

### POST `/api/client/family/`

Lista relaciones de Family Sharing del usuario. Los juegos compartidos por familia tambien aparecen en `owned_games` como `shared_from_family=true`.

### POST `/api/client/launcher/update-check/`

Consulta el repositorio de releases del launcher configurado desde `/client/` por Superroot:

```json
{
  "current_version": "0.1.12-beta",
  "os": "windows"
}
```

Respuesta:

```json
{
  "update_available": true,
  "latest": {
    "version": "0.1.12-beta",
    "download_url": "https://example.com/RacLauncher.zip",
    "checksum_sha256": "..."
  }
}
```

### Codigos 1.2

- Codigos de objetos/inventario: Admin > Codigos, siguen usando `redeemable_codes`.
- Codigos de licencia de juego: `/games-code/`, tabla `game_license_codes`, maximo 100 por batch.
- Juegos externos: el owner solicita copias; Admin/Superroot aprueba, rechaza o revoca. Rechazar/revocar exige motivo y crea notificacion para el solicitante.
- El endpoint de canje es el mismo: `POST /api/client/redeem/` o `POST /api/redeem/`.

### Playtime y presencia

`POST /api/client/presence/` con `status=in_game` inicia contador de horas en `user_games.total_play_seconds`. Al volver a `online`, `offline` o logout, el contador se cierra. El perfil publico muestra online/jugando/offline y horas si la privacidad permite ver juegos.

### POST `/api/client/logout/`

Revoca el token del cliente actual.

## Developer APIs

Estas APIs usan `client_token` del cliente local:

```text
Authorization: Bearer jvg_ct_...
```

Acceso:

- `developer`: solo juegos donde `owner_user_id` es su usuario.
- `admin` y `superroot`: todos los juegos.
- `user` y `supporter`: `403`.

### POST `/api/developer/games/list/`

Lista juegos accesibles, cantidad de builds, ultima build y estado de API keys.

```json
{}
```

### POST `/api/developer/games/detail/`

Devuelve detalle, configuracion publica, builds y API keys sin secret.

```json
{
  "slug": "jumpfall"
}
```

Tambien acepta:

```json
{
  "game_id": 1
}
```

### POST `/api/developer/api-keys/create/`

Crea una API key para un juego permitido. `secret_key` se devuelve una sola vez.

```json
{
  "slug": "jumpfall"
}
```

### POST `/api/developer/api-keys/revoke/`

Revoca una API key de un juego permitido.

```json
{
  "api_key_id": 10
}
```

### POST `/api/developer/games/test/`

Devuelve request/response para pruebas guiadas en `/tutorials/`.

```json
{
  "slug": "jumpfall",
  "test": "game_info"
}
```

`test` puede ser `game_info`, `version_check`, `database_status` u `oauth_device_code`.

## Juegos externos 1.1

Los juegos de terceros usan el rol `developer-extern` y se configuran desde la web:

- Superroot configura la base externa en `/superroot/?section=extern-games-config`.
- El developer externo usa `/external-games/`.
- El slug del juego se genera automaticamente y se sincroniza en la tabla principal `games`.
- En `games.source_type` queda `external`.
- `developer_name` y `publisher_name` son opcionales; si estan vacios no se muestran publicamente.

La base externa queda apagada por defecto. Cuando esta activa, contiene `external_games` y `external_game_players`. La tabla principal sigue siendo la fuente para catalogo, builds, API keys y licencias.

Superroot tambien tiene `panic reinstall` en mantenimiento. Reaplica schema/seeds y migraciones runtime sin borrar datos, y requiere la password del Superroot actual.

## Steam Connect

Steam se conecta con OpenID 2.0, no con OAuth2 generico. Superroot debe crear una integracion activa con `provider=steam` y:

```json
{
  "login_enabled": false,
  "connect_enabled": true,
  "steam_api_key": "optional"
}
```

Rutas web:

- `GET /auth/steam/start/?mode=connect`
- `GET /auth/steam/callback/`

Al completar el flujo, se guarda `provider=steam` en `external_accounts`. Desconectar Steam desde perfil elimina solo esa vinculacion.

## Autenticacion futura de juegos

La tabla `game_api_keys` guarda:

- `public_key`
- `secret_key_hash`
- fecha de creacion
- ultima vez usada
- estado activa o revocada
- juego asociado

La clave secreta no se guarda en texto plano. Al crear una API key se muestra una sola vez y despues solo se conserva el hash. El flujo OAuth de juego usa la `public_key`; la `secret_key` queda disponible para flujos servidor a servidor futuros.

## OAuth externo para login web

Superroot puede crear integraciones externas. Los botones de login solo aparecen cuando la integracion esta `active` y su `config_json` incluye:

```json
{
  "login_enabled": true,
  "auth_url": "https://provider.example/oauth/authorize",
  "token_url": "https://provider.example/oauth/token",
  "userinfo_url": "https://provider.example/oauth/userinfo",
  "scope": "openid profile email",
  "client_secret": "solo-si-el-proveedor-lo-requiere",
  "id_field": "id",
  "email_field": "email",
  "username_field": "username"
}
```

El secreto de `client_secret_hash` no se puede recuperar porque esta hasheado. Para OAuth2 real, usa `client_secret` dentro del `config_json` o un proveedor que no requiera secreto en backend local.

## Ejemplo desde un motor de juego

Cualquier motor que pueda hacer HTTP puede consumir la API.

Ejemplo conceptual:

```text
POST http://jevzgames.local/api/oauth/device-code/
Accept: application/json
Content-Type: application/json
```

La respuesta siempre debe parsearse como JSON y validar `success`.
