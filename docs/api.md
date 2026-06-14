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

## Endpoint inicial

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

## Endpoints preparados para fases futuras

- `/api/game-info/`
- `/api/version-check/`
- `/api/user-login/`
- `/api/user-profile/`
- `/api/redeem-code/`
- `/api/link-game-account/`

## Autenticacion futura de juegos

La tabla `game_api_keys` esta preparada para:

- `public_key`
- `secret_key_hash`
- fecha de creacion
- ultima vez usada
- estado activa o revocada
- juego asociado

La clave secreta no debe guardarse en texto plano. Al crear una API key futura, se mostrara una sola vez y despues solo se conservara el hash.

## Ejemplo desde un motor de juego

Cualquier motor que pueda hacer HTTP puede consumir la API.

Ejemplo conceptual:

```text
GET https://tu-dominio.com/api/status/
Accept: application/json
```

La respuesta siempre debe parsearse como JSON y validar `success`.
