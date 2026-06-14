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

Inicia el vinculo OAuth tipo device-code para juegos Unity o clientes sin navegador embebido.

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

Unity llama este endpoint cada `interval` segundos hasta que el usuario apruebe o expire.

Request:

```json
{
  "public_key": "jvg_pk_...",
  "device_code": "jvg_dc_..."
}
```

Mientras espera, responde `success: false` con `message: authorization_pending`. Al aprobar, responde `success: true` con `access_token`.

### POST `/api/user-profile/`

Endpoint autenticado para validar el token y obtener el usuario vinculado al juego.

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

### POST `/api/achievements/progress/`

Actualiza progreso de un logro configurable.

```json
{
  "achievement_code": "first_run",
  "mode": "unlock",
  "progress": 1,
  "progress_data": {
    "source": "unity"
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
    "source": "unity"
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

## Endpoints preparados para fases futuras

- `/api/user-login/`
- `/api/redeem-code/`

## Autenticacion futura de juegos

La tabla `game_api_keys` guarda:

- `public_key`
- `secret_key_hash`
- fecha de creacion
- ultima vez usada
- estado activa o revocada
- juego asociado

La clave secreta no se guarda en texto plano. Al crear una API key se muestra una sola vez y despues solo se conserva el hash. El flujo OAuth de Unity usa la `public_key`; la `secret_key` queda disponible para flujos servidor a servidor futuros.

## Ejemplo desde un motor de juego

Cualquier motor que pueda hacer HTTP puede consumir la API.

Ejemplo conceptual:

```text
POST http://jevzgames.local/api/oauth/device-code/
Accept: application/json
Content-Type: application/json
```

La respuesta siempre debe parsearse como JSON y validar `success`.
