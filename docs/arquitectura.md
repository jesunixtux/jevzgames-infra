# Arquitectura

JevzGames Infra usa un monolito modular. La aplicacion corre en un solo proyecto PHP, pero separa responsabilidades por carpetas claras.

## Por que monolito modular

La meta es mantener el sistema simple, facil de instalar y facil de modificar. Un monolito evita dependencias obligatorias y servicios externos innecesarios. La parte modular esta en la organizacion interna:

- `app/core`: bootstrap, PDO, layout y utilidades base.
- `app/helpers`: funciones comunes como rutas, CDN, JSON y escape HTML.
- `app/security`: CSRF y autenticacion.
- `app/models`: acceso a datos.
- `app/services`: servicios compartidos como logging.
- `app/installers`: instalador inicial.
- `public`: unica carpeta expuesta por el servidor web.

## Flujo inicial

1. El usuario entra a `public/index.php`.
2. Si no existe `app/config/installed.lock`, se ofrece el instalador.
3. El instalador valida requisitos, crea la base, ejecuta `schema.sql` y `seeds.sql`.
4. El instalador escribe `app/config/config.php`.
5. El instalador crea el usuario `superroot`.
6. El instalador crea `app/config/installed.lock`.
7. Login, registro y paneles empiezan a usar la configuracion privada.

## Base de datos principal

La base principal guarda datos compartidos por toda la infraestructura:

- Usuarios.
- Roles y permisos.
- Sesiones preparadas para futuro.
- Juegos registrados.
- Vinculaciones usuario-juego.
- Builds/versiones.
- API keys por juego.
- Configuracion global.
- CDN.
- Soporte.
- Codigos canjeables.
- Integraciones externas.
- Cuentas externas vinculadas.
- Logs de actividad.

## Bases externas por juego

Cada juego puede tener una base externa opcional. Esa configuracion se guarda preparada en `games.external_database_json`.

La cuenta principal sigue viviendo en la base principal. Si un usuario juega un juego especifico, la relacion se guarda en `user_games`.

## Pagina de juegos

La pagina `/games/` lee la tabla `games` y muestra juegos en estados visibles:

- `development`
- `playtest`
- `beta`
- `published`

Los juegos `archived` no se muestran en el catalogo publico.

El detalle usa `?game=slug` y permite vincular la cuenta del usuario con el juego mediante `user_games`.

## CDN

El helper `asset_url($path)` resuelve assets segun la configuracion.

Si CDN externa esta activa:

```text
https://cdn.ejemplo.com/img/logo.png
```

Si no esta activa:

```text
/assets/img/logo.png
```

## APIs

Las APIs viven bajo `public/api/` y responden JSON limpio con este formato:

```json
{
  "success": true,
  "message": "OK",
  "data": {}
}
```

La fase actual incluye:

- `/api/status/`
- `/api/game-info/`
- `/api/version-check/`
- `/api/oauth/device-code/`
- `/api/oauth/token/`
- `/api/user-profile/`
- `/api/game-database/status/`
- `/api/player-data/save/`
- `/api/player-data/get/`
- `/api/achievements/list/`
- `/api/achievements/progress/`
- `/api/achievements/unlock/`

Las API keys por juego viven en `game_api_keys`. El OAuth device-code guarda solicitudes temporales en `game_oauth_device_codes` y tokens Bearer en `game_oauth_tokens`, siempre hasheados en base de datos.

## OAuth para Unity

El juego no pide la contrasena del usuario. Al arrancar, Unity llama `/api/oauth/device-code/` con la `public_key` del juego, abre `/oauth/authorize/` en el navegador y hace polling a `/api/oauth/token/`.

Cuando el usuario aprueba la solicitud web, la plataforma:

1. Crea o confirma la relacion en `user_games`.
2. Marca el device code como autorizado.
3. Entrega a Unity un token Bearer de 30 dias.

Unity valida ese token con `/api/user-profile/`. Si el usuario queda bloqueado, el juego se archiva o el token expira, la API rechaza el token.

## BD dedicada por juego

Cada juego puede tener `games.external_database_json` con credenciales de una base MySQL/MariaDB dedicada. Esa configuracion se gestiona desde Admin y se prueba desde el panel.

El juego no recibe credenciales. Unity llama APIs del backend:

- `game-database/status` para verificar conectividad.
- `player-data/save` y `player-data/get` para persistir datos del jugador.

Si la base dedicada esta activa y conectable, el backend crea/usa la tabla `jevzgames_player_data` dentro de esa base. Si no hay base dedicada, el backend usa `game_player_data` en la base principal.

## Logros

Los logros configurables viven en `game_achievements` y el progreso del jugador en `user_achievements`.

Admin configura:

- `code`: identificador estable que usa Unity.
- `title`, `description`, `points`.
- `goal_value`: meta numerica.
- `status`: `active`, `hidden`, `disabled`.
- `reward_json` y `config_json` para reglas o recompensas propias del juego.

Unity actualiza progreso con `achievements/progress` usando modo `set`, `add` o `unlock`.

## Roles

- `user`: usuario normal.
- `developer`: gestiona juegos propios.
- `admin`: administra usuarios, juegos, logs y moderacion.
- `supporter`: atiende soporte.
- `superroot`: controla configuracion sensible.

`superroot` no se registra desde la web publica. Solo lo crea el instalador inicial.

## Panel Admin

El panel `/admin/` cubre operaciones diarias:

- Usuarios normales: bloqueo, desbloqueo y recuperacion.
- Juegos: creacion, edicion y cambio de estado.
- Codigos canjeables: creacion, activacion y desactivacion con hash HMAC y preview.
- Soporte: revision de tickets y acceso al panel supporter.
- Logs: lectura de actividad y archivo `app.log`.

Admin no modifica cuentas `admin` ni `superroot`. Esa frontera queda en el panel Superroot.

## Panel Superroot

El panel `/superroot/` concentra configuracion sensible:

- Configuracion global de plataforma.
- Configuracion CDN local o externa.
- Ajustes basicos de sesion y API.
- Integraciones externas configurables.
- Gestion de roles operativos.
- Mantenimiento y revision de logs.

Los cambios de configuracion escriben `app/config/config.php` y sincronizan valores no secretos en `system_settings`.

Las integraciones guardan `client_secret` como hash en `external_integrations.client_secret_hash`; el secreto no se muestra de vuelta.

El rol `superroot` queda protegido y no se administra desde la tabla de usuarios del panel.

## Sistema de codigos

La tabla `redeemable_codes` guarda codigos como hash, no como texto plano. `code_redemptions` registra quien canjeo cada codigo.

La logica de canje queda para una fase posterior, pero el esquema ya evita repetir canjes por usuario y codigo.

## Sistema de soporte

El soporte usa `support_tickets` y `support_messages`.

Flujo actual:

1. El usuario crea un ticket desde `/support/`.
2. El sistema crea una conversacion abierta con vencimiento inicial de 3 minutos.
3. El panel `/supporter/` permite ver tickets, asignarse uno, responder y extender el tiempo.
4. El supporter puede cerrar, marcar solucionado o marcar no solucionado.
5. La conversacion refresca mensajes con polling/AJAX simple.

No se usan WebSockets en esta fase.

## Integraciones externas

`external_integrations` guarda proveedores como Steam, Epic o GOG sin hardcodearlos. `external_accounts` vincula cuentas externas a usuarios internos.

Las integraciones externas quedan separadas del OAuth interno de juegos; Steam, Epic o GOG pueden agregarse despues como proveedores en `external_integrations`.
