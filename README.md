# JevzGames Infra

Infraestructura monolitica modular en PHP puro para integrar videojuegos propios o de terceros bajo una cuenta principal, APIs HTTP/JSON y paneles web.

La primera fase deja una base funcional y simple:

- Instalador web inicial.
- Configuracion privada fuera de `public/`.
- Conexion PDO a MySQL/MariaDB.
- Usuario `superroot` creado solo desde el instalador.
- Registro, login, logout y perfil basico.
- Mantener sesion iniciada con token persistente hasheado.
- Modo oscuro local por navegador.
- Roles base: `user`, `developer`, `admin`, `supporter`, `superroot`.
- Soporte basico con tickets, chat por polling, extension de tiempo y cierre.
- Panel Admin para usuarios, juegos, codigos canjeables, soporte y logs.
- Panel Superroot para configuracion global, CDN, integraciones, usuarios y mantenimiento.
- Pagina `/games/` con catalogo, detalle y vinculacion usuario-juego.
- Helper de rutas y helper CDN `asset_url()`.
- API JSON inicial en `/api/status/`.
- OAuth device-code para vincular un juego Unity a la cuenta principal sin pedir contrasena dentro del juego.
- APIs de runtime para perfil, BD dedicada, datos persistentes de jugador y logros configurables.
- Logros con imagen desbloqueada/bloqueada.
- Inventario, codigos canjeables reales y pagina `/redeem/`.
- Comunidad, mensajes, notificaciones por sesion y perfiles publicos.
- Publish on Games, Workshop y cliente tipo Steam configurables desde Superroot.
- Esquema SQL preparado para usuarios, juegos, roles, codigos, soporte, integraciones y API keys.

## Requisitos

- PHP 8.1 o superior.
- MySQL o MariaDB.
- Extension `pdo_mysql`.
- Apache o Nginx.
- En local: XAMPP funciona bien si la raiz web apunta a `public/`.

## Instalacion local con XAMPP

1. Copia o conserva el proyecto en `C:\xampp\jevzgames-infra`.
2. Inicia Apache y MySQL desde XAMPP.
3. Configura Apache para que el document root apunte a:

   ```apache
   C:/xampp/jevzgames-infra/public
   ```

4. Abre el instalador:

   ```text
   http://localhost/install/
   ```

5. Usa una base como `jevzgames_main`.
6. Para XAMPP local normalmente sirve:

   ```text
   Host: 127.0.0.1
   Puerto: 3306
   Usuario: root
   Contrasena: vacia
   ```

7. Crea el usuario `superroot`.
8. Al finalizar se crea:

   ```text
   app/config/config.php
   app/config/installed.lock
   ```

Si `installed.lock` existe, el instalador queda bloqueado.

## Estructura

```text
app/
  config/
  core/
  database/
  helpers/
  installers/
  models/
  security/
  services/
public/
  index.php
  install/index.php
  login/index.php
  register/index.php
  logout/index.php
  games/index.php
  inventory/index.php
  redeem/index.php
  publish-on-games/index.php
  workshop/index.php
  client/index.php
  profile/index.php
  support/index.php
  api/index.php
  api/status/index.php
  api/game-info/index.php
  api/version-check/index.php
  api/oauth/device-code/index.php
  api/oauth/token/index.php
  api/user-profile/index.php
  api/game-database/status/index.php
  api/player-data/save/index.php
  api/player-data/get/index.php
  api/achievements/list/index.php
  api/achievements/progress/index.php
  api/achievements/unlock/index.php
  oauth/authorize/index.php
  admin/index.php
  supporter/index.php
  supporter/messages/index.php
  superroot/index.php
storage/
  logs/
  cache/
  uploads/
  sessions/
database/
  schema.sql
  seeds.sql
docs/
  arquitectura.md
  instalacion.md
  api.md
```

## Reglas del proyecto

- PHP puro, sin Laravel, Symfony, CodeIgniter ni frameworks PHP.
- HTML, CSS y JavaScript puro.
- No Composer obligatorio.
- No React, Vue ni Node.js obligatorio.
- La carpeta publica es `public/`.
- No crear archivos tipo `login.php` o `register.php` en la raiz publica.
- Cada seccion vive en su carpeta con `index.php`.
- No guardar contrasenas ni API keys secretas en texto plano.
- Usar PDO con prepared statements.
- Escapar salida HTML con `htmlspecialchars()` mediante `e()`.
- Proteger formularios importantes con CSRF.

## Agregar una nueva pagina

Ejemplo para `/public/example/index.php`:

```php
<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/core/bootstrap.php';

use App\Core\Page;

require_installed();

Page::header('Example');
?>
<section class="panel">
    <h1>Example</h1>
</section>
<?php
Page::footer();
```

## Agregar un endpoint API

Ejemplo para `/public/api/example/index.php`:

```php
<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/core/bootstrap.php';

api_response(true, 'OK', [
    'example' => true,
]);
```

Formato estandar:

```json
{
  "success": true,
  "message": "OK",
  "data": {}
}
```

## Juegos

El catalogo publico vive en:

```text
/games/
```

Permite:

- Listar juegos visibles.
- Filtrar por estado.
- Abrir detalle por `?game=slug`.
- Ver version, estado, descripcion y JSON publico.
- Vincular o desvincular el juego a la cuenta del usuario.
- Desvincular borra datos de ese juego para el usuario: cloud saves, player data, logros, tokens e inventario del juego.

El boton manual de vincular queda reservado a `admin` y `superroot`. Los jugadores normales se vinculan al iniciar sesion desde Unity o desde el cliente.

Un juego se registra desde Admin en la tabla `games` con:

- `name`
- `slug`
- `description`
- `status`
- `current_version`
- `config_json`
- `endpoints_json`
- `external_database_json`
- `cdn_json`

## Vincular un juego con la plataforma

La cuenta de usuario se crea una sola vez en la base principal. La relacion con cada juego se guarda en `user_games`.

Los juegos consumen APIs HTTP/JSON con API keys por juego. El flujo recomendado para Unity es:

1. Admin crea una API key desde `/admin/?section=games`.
2. Unity guarda la `public_key` del juego.
3. Al ejecutar, Unity llama `POST /api/oauth/device-code/`.
4. La app abre `/oauth/authorize/?user_code=...`.
5. El usuario inicia sesion. Para usuarios normales el vinculo se aprueba automaticamente; admin y superroot conservan aprobacion manual para pruebas.
6. Unity hace polling a `POST /api/oauth/token/` y guarda el token Bearer.
7. Las llamadas autenticadas usan `Authorization: Bearer <token>`.

En el proyecto Unity de prueba se agrego `Assets/JevzGames/JevzGamesOAuthClient.cs`. Adjuntalo a un GameObject, pega la `public_key` del juego y al iniciar abrira el navegador para aprobar el vinculo.

## Runtime de juego

El backend expone APIs autenticadas con Bearer token para que el juego no toque la base directamente:

- `POST /api/game-database/status/`: informa si la BD dedicada del juego esta configurada y conectable.
- `POST /api/player-data/save/`: guarda JSON por jugador y key. Si hay BD dedicada activa, guarda ahi; si no, usa la base principal.
- `POST /api/player-data/get/`: recupera JSON guardado por jugador y key.
- `POST /api/achievements/list/`: lista logros configurados y progreso del jugador.
- `POST /api/achievements/progress/`: set/add/unlock de progreso de un logro.
- `POST /api/achievements/unlock/`: atajo para desbloquear un logro.
- `POST /api/inventory/list/`: inventario del jugador para el juego.
- `POST /api/redeem/`: canje de codigos desde el juego.

La BD dedicada se configura en Admin dentro del juego, campo `BD dedicada JSON`, por ejemplo:

```json
{"enabled":true,"host":"127.0.0.1","port":3306,"database":"mi_juego_db","user":"root","password":"","charset":"utf8mb4"}
```

Las credenciales nunca se entregan por API a Unity; solo las usa el backend.

## Soporte

Los usuarios autenticados pueden crear tickets en:

```text
/support/
```

El chat inicial dura 3 minutos. El panel supporter puede responder, asignarse tickets, extender el tiempo, cerrar y marcar como solucionado o no solucionado:

```text
/supporter/
```

El refresco de mensajes usa polling/AJAX simple, sin WebSockets obligatorios.

## Admin

El panel operativo vive en:

```text
/admin/
```

Funciones actuales:

- Resumen de usuarios, juegos, tickets y codigos.
- Bloquear, desbloquear o marcar recuperacion de usuarios normales.
- Crear y editar juegos basicos.
- Cambiar estado de juegos.
- Crear codigos canjeables con hash HMAC y preview.
- Revisar solicitudes de `/publish-on-games/`.
- Configurar Workshop por juego y moderar items.
- Activar o desactivar codigos.
- Revisar tickets y abrirlos en el panel soporte.
- Revisar logs de actividad y `storage/logs/app.log`.

Admin no gestiona cuentas `admin` ni `superroot`; eso queda reservado para Superroot.

## Superroot

El panel sensible vive en:

```text
/superroot/
```

Funciones actuales:

- Resumen de usuarios, juegos, tickets e integraciones.
- Configuracion de nombre, URL base, entorno, servidor, CDN, duracion de sesion y exposicion de errores API en desarrollo.
- Integraciones externas configurables con `client_secret` guardado como hash.
- Activar o desactivar Publish on Games, Workshop y cliente tipo Steam.
- Gestion simple de roles `user`, `developer`, `admin`, `supporter`.
- Bloqueo, desbloqueo y estado de recuperacion de usuarios.
- Vista de mantenimiento y ultimos logs.

El rol `superroot` no se asigna ni se quita desde la tabla de usuarios.

## Inventario y canjes

Los codigos se crean en Admin y se canjean desde:

```text
/redeem/
POST /api/redeem/
POST /api/client/redeem/
```

`reward_json` puede entregar uno o varios items:

```json
{"items":[{"item_key":"skin_blue","quantity":1,"name":"Skin azul"}]}
```

El usuario ve sus recompensas en:

```text
/inventory/
```

## Publish on Games

Superroot debe activar la funcion en:

```text
/superroot/?section=features
```

Luego los usuarios pueden enviar juegos desde:

```text
/publish-on-games/
```

Admin revisa en:

```text
/admin/?section=publish
```

Aprobar crea un juego en estado `development` con el usuario como owner.

## Workshop

Superroot activa Workshop globalmente en `Funciones`. Admin habilita Workshop por juego en:

```text
/admin/?section=workshop
```

Cada juego puede permitir uploads de usuarios y elegir moderacion `pre` o `post`. La pagina publica es:

```text
/workshop/
```

## Cliente tipo Steam

Superroot activa y configura el cliente en `Funciones`. La configuracion publica vive en:

```text
/client/
GET /api/client/config/
```

Flujo minimo para montar un launcher:

1. Lee `GET /api/client/config/`.
2. Si `enabled` es `true`, muestra login.
3. Envia `POST /api/client/login/` con `identity`, `password` y `client_name`.
4. Guarda `client_token` localmente.
5. Usa `Authorization: Bearer jvg_ct_...` para:
   - `POST /api/client/library/`
   - `POST /api/client/inventory/`
   - `POST /api/client/redeem/`
6. Usa `POST /api/client/logout/` para revocar el token.

Si una cuenta esta suspendida, el login web muestra un popup y el cliente recibe error `403`.

## Apache

Configura el document root en `public/`. Las carpetas privadas tienen `.htaccess` para bloquear acceso directo, pero la proteccion principal es no exponerlas como raiz web.

## Nginx

Configura `root` apuntando a `public/` y bloquea acceso a archivos ocultos. Ver `docs/instalacion.md`.
