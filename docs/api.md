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

### POST `/api/client/library/`

Header:

```text
Authorization: Bearer jvg_ct_...
```

Devuelve:

- `linked_games`: biblioteca vinculada del usuario.
- `catalog`: catalogo visible.

Cada juego puede incluir `install_build` si Admin subio o registro un `.zip` instalable:

```json
{
  "install_build": {
    "version": "0.1.0",
    "channel": "development",
    "download_url": "http://jevzgames.local/uploads/builds/jumpfall/jumpfall-0.1.0-development.zip",
    "checksum": "sha256...",
    "size_bytes": 123456,
    "executable_path": "JumpFall.exe"
  }
}
```

### POST `/api/client/obtain-game/`

Crea la licencia del juego para el usuario autenticado y lo agrega a biblioteca. Solo funciona si el juego visible tiene build instalable.

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

### POST `/api/client/logout/`

Revoca el token del cliente actual.

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
