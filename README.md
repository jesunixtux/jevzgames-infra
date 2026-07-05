# JevzGames Infra

Infraestructura monolitica modular en PHP puro para integrar videojuegos propios o de terceros bajo una cuenta principal, APIs HTTP/JSON y paneles web.

La primera fase deja una base funcional y simple:

- Instalador web inicial.
- Configuracion privada fuera de `public/`.
- Conexion PDO a MySQL/MariaDB.
- Usuario `superroot` creado solo desde el instalador.
- Registro, login, logout y perfil basico.
- Mantener sesion iniciada con token persistente hasheado.
- Verificacion por correo configurable con modo local por log o SMTP con PHPMailer.
- Sistema de idiomas `en`/`es`, con `en` por defecto, textos publicos y EULA versionado por idioma desde Superroot.
- Modo oscuro local por navegador.
- Roles base: `user`, `developer`, `admin`, `supporter`, `superroot`.
- Soporte basico con tickets, chat por polling, extension de tiempo y cierre.
- Panel Admin para usuarios, juegos, codigos canjeables, soporte y logs.
- Panel Superroot para configuracion global, contenido editable, CDN, integraciones, usuarios y mantenimiento.
- Pagina `/games/` con catalogo, detalle y vinculacion usuario-juego.
- Paginas `/library/` y `/achievements/` para biblioteca y logros del usuario.
- Helper de rutas y helper CDN `asset_url()`.
- API JSON inicial en `/api/status/`.
- OAuth device-code para vincular un juego o app compatible a la cuenta principal sin pedir contrasena dentro del juego.
- APIs de runtime para perfil, BD dedicada, datos persistentes de jugador y logros configurables.
- Logros con imagen desbloqueada/bloqueada y textos por idioma.
- Inventario con imagenes, codigos canjeables reales, licencias de juegos y pagina `/redeem/`.
- Comunidad, mensajes estilo chat, notificaciones por sesion y perfiles publicos con presencia online/jugando.
- Publish on Games, Workshop y cliente tipo Steam configurables desde Superroot.
- Juegos externos con base dedicada opcional, builds propias y versiones que abren Steam u otra plataforma.
- APIs de cliente para biblioteca, presencia, mensajes y logros del launcher.
- Codigos de licencia de juegos separados de codigos de objetos, con solicitudes para juegos externos.
- Family Sharing, grupos, horas jugadas y juegos compartidos en biblioteca del launcher.
- Cloud sync tipo Steam por ruta de savegame configurable, conservando el modo viejo por slots.
- Repositorio de releases del launcher y endpoint de auto-update.
- SDK Unity importable para usar las APIs del launcher y mostrar desbloqueos de logros en pantalla.
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
  verify-email/index.php
  eula/index.php
  logout/index.php
  games/index.php
  library/index.php
  achievements/index.php
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
  api/client/achievements/list/index.php
  api/client/achievements/unlock/index.php
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
sdks/
  unity/
    JevzGamesApi.unitypackage
    JevzGamesApi/
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
- Obtener juegos con build instalable, vincular o desvincular el juego a la cuenta del usuario.
- Desvincular borra datos de ese juego para el usuario: cloud saves, player data, logros, tokens, inventario y licencia del juego.
- Controlar visibilidad por juego: `public` aparece en catalogo, `unlisted` solo abre con URL directa y `private` solo lo ve el dueño, Admin o Superroot.

El boton manual de vincular queda reservado a `admin` y `superroot`. Los jugadores normales se vinculan al iniciar sesion desde una app compatible o al obtener un juego instalable.
Si el cliente tipo Steam esta activo en Superroot, la web no muestra enlaces directos para descargar ZIP de juegos; los juegos con build se instalan y actualizan desde el cliente.

## Launcher y Unity

El cliente tipo Steam usa token Bearer `jvg_ct_...` para login, biblioteca, presencia, mensajes y logros. Los juegos iniciados desde el launcher pueden recibir contexto por argumentos:

```text
--jevzgames-api=http://jevzgames.local
--jevzgames-token=jvg_ct_...
--jevzgames-game=slug-del-juego
```

Tambien se aceptan variables de entorno `JEVZGAMES_API_BASE`, `JEVZGAMES_CLIENT_TOKEN` y `JEVZGAMES_GAME_SLUG`.

El paquete Unity esta en:

```text
sdks/unity/JevzGamesApi.unitypackage
```

Importalo, agrega `JevzGamesLauncherBridge` en la primera escena y desbloquea logros desde el juego:

```csharp
JevzGames.Api.JevzGamesApiClient.Instance.UnlockAchievement("first_run");
```

Cuando el backend confirma un desbloqueo nuevo, el SDK muestra una notificacion en la parte inferior de la pantalla.

La biblioteca del usuario vive en:

```text
/library/
```

Los logros visibles y su progreso viven en:

```text
/achievements/
```

Un juego se registra desde Admin en la tabla `games` con:

- `name`
- `slug`
- `description`
- `status`
- `visibility` (`public`, `unlisted`, `private`)
- `developer_name`
- `publisher_name`
- `source_type` (`internal`, `external`)
- `current_version`
- `config_json`
- `endpoints_json`
- `external_database_json`
- `cdn_json`

## Juegos externos 1.1

Superroot puede activar una base dedicada para juegos de terceros desde:

```text
/superroot/?section=extern-games-config
```

Por defecto esta funcion queda desactivada. Para usarla:

- Crea una base MySQL exclusiva para juegos externos.
- Guarda host, puerto, base, usuario y password en Superroot.
- Activa `Juegos externos` y `Permitir publicar/configurar`.
- Asigna el rol `developer-extern` a los usuarios externos.

Los usuarios con `developer-extern` gestionan sus juegos desde:

```text
/external-games/
```

Los slugs se generan automaticamente, los juegos se sincronizan con la tabla principal `games` como `source_type=external`, y la desarrolladora/publisher solo se muestran publicamente cuando estan configurados.

## Panic Reinstall

Superroot puede ejecutar `panic reinstall` desde mantenimiento. Reaplica `database/schema.sql`, `database/seeds.sql` y migraciones runtime sin borrar datos existentes. Requiere la password del Superroot actual y no ejecuta `DROP` ni `TRUNCATE`.

## Vincular un juego con la plataforma

La cuenta de usuario se crea una sola vez en la base principal. La relacion con cada juego se guarda en `user_games`.

Los juegos consumen APIs HTTP/JSON con API keys por juego. El flujo recomendado para una app compatible es:

1. Admin crea una API key desde `/admin/?section=games`.
2. La app guarda la `public_key` del juego.
3. Al ejecutar, la app llama `POST /api/oauth/device-code/`.
4. La app abre `/oauth/authorize/?user_code=...`.
5. El usuario inicia sesion. Para usuarios normales el vinculo se aprueba automaticamente; admin y superroot conservan aprobacion manual para pruebas.
6. La app hace polling a `POST /api/oauth/token/` y guarda el token Bearer.
7. Las llamadas autenticadas usan `Authorization: Bearer <token>`.

Al aprobarse el OAuth, el backend crea el vinculo y una licencia activa para el juego. El token Bearer no caduca por tiempo: queda activo hasta que el usuario desvincule el juego o se revoque. Cada llamada autenticada actualiza la presencia publica como `Jugando <juego>`.

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
- `POST /api/game-license/check/`: confirma usuario, juego, licencia activa y build instalable para DRM basico.

La BD dedicada se configura en Admin dentro del juego, campo `BD dedicada JSON`, por ejemplo:

```json
{"enabled":true,"host":"127.0.0.1","port":3306,"database":"mi_juego_db","user":"root","password":"","charset":"utf8mb4"}
```

Las credenciales nunca se entregan por API al juego; solo las usa el backend.

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
- Crear codigos que entregan items, imagenes de items o licencias de juegos.
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
- Editar textos publicos principales desde `Contenido`.
- Configurar verificacion por correo en modo `log` o `mail`.
- Configurar idiomas, textos publicos y EULA publico por idioma, version vigente y obligatoriedad de aceptacion.
- Gestion simple de roles `user`, `developer`, `admin`, `supporter`.
- Bloqueo, desbloqueo y estado de recuperacion de usuarios.
- Modo mantenimiento global para dejar entrar solo a `admin`, `superroot` y `developer`.
- Vista de mantenimiento y ultimos logs.

El rol `superroot` no se asigna ni se quita desde la tabla de usuarios.

## Correo y EULA

Superroot configura esto en:

```text
/superroot/?section=access
```

Verificacion por correo:

- `Log local`: recomendado en XAMPP. El enlace queda en `storage/logs/app.log` como evento `email_verification_link`.
- `SMTP con PHPMailer`: usa la carpeta externa `phpmailer/` y los datos SMTP configurados en Superroot.
- Si `Requerir correo verificado` esta activo, login web y login del cliente rechazan cuentas sin `email_verified_at`.
- El password SMTP se guarda cifrado en `system_settings`.

Rutas publicas:

```text
/verify-email/
/eula/
```

Si el EULA esta marcado como requerido, el registro pide aceptarlo. Cada idioma tiene su propia version; si cambias la version `en` o `es`, los usuarios deberan aceptar la version vigente de ese idioma en `/eula/`.

## Inventario y canjes

Los codigos de objetos/inventario se crean en Admin y se canjean desde:

```text
/redeem/
POST /api/redeem/
POST /api/client/redeem/
```

`reward_json` puede entregar uno o varios items, con imagen opcional:

```json
{"items":[{"item_key":"skin_blue","quantity":1,"name":"Skin azul","image_path":"/uploads/items/skin_blue.png"}]}
```

Tambien puede entregar una licencia de juego:

```json
{"game_slug":"jumpfall"}
```

Desde la version 1.2, las licencias de juegos tienen su propio apartado:

```text
/games-code/
```

Cada juego interno puede generar hasta 100 codigos por batch. Los juegos externos solicitan copias desde `/games-code/`; Admin o Superroot aprueba, rechaza o revoca, y rechazar/revocar exige motivo. Las notificaciones se envian al solicitante.

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
   - `GET|POST /api/client/me/`
   - `POST /api/client/library/`
   - `POST /api/client/inventory/`
   - `POST /api/client/redeem/`
   - `POST /api/client/presence/`
   - `GET|POST /api/client/presence/status/`
   - `POST /api/client/messages/conversations/`
   - `POST /api/client/messages/thread/`
   - `POST /api/client/messages/send/`
   - `POST /api/client/messages/mark-read/`
6. Usa `POST /api/client/presence/` para marcar `online` o `in_game` con `game_slug`; el juego debe estar en la biblioteca del usuario.
7. Usa `POST /api/client/logout/` para revocar el token.

Si una cuenta esta suspendida, el login web muestra un popup y el cliente recibe error `403`.

### Biblioteca online y offline

`POST /api/client/library/` devuelve dos listas separadas:

- `owned_games`: juegos vinculados o con licencia activa del usuario. Esta es la biblioteca que debe mostrar el launcher.
- `catalog`: catalogo publico visible. Solo incluye juegos con `visibility=public`; sirve para explorar u obtener juegos, no para modo offline.

El campo antiguo `linked_games` sigue existiendo para compatibilidad, pero el launcher nuevo debe preferir `owned_games`.

Cada item de `owned_games` incluye `visibility`, `install_build`, `has_license`, `is_linked`, `offline_allowed`, `offline_available` y `last_played_at`. El modo offline solo debe permitir ejecutar juegos ya instalados cuando `offline_available=true`; no debe descargar builds nuevas ni crear licencias nuevas sin conexion.
Si `install_build.delivery_type` es `external_platform`, el cliente debe abrir `install_build.launch_url` y no intentar descargar ZIP. Para Steam se puede usar `steam://run/<app_id>`.

Estructura local recomendada para el launcher:

```text
session.json
library-cache.json
games/<slug>/installed.json
```

`session.json` debe guardar el token Bearer y usuario basico, nunca la contrasena. `library-cache.json` debe ser una copia de `owned_games` y `offline_cache`. `installed.json` debe guardar version instalada, ruta local, checksum y ejecutable.

### Mensajes en cliente

El cliente puede implementar chat por polling cada 5 o 10 segundos con:

- `POST /api/client/messages/conversations/`
- `POST /api/client/messages/thread/`
- `POST /api/client/messages/send/`
- `POST /api/client/messages/mark-read/`

No usa WebSocket todavia. Todos los endpoints requieren Bearer token y reutilizan las mismas conversaciones del panel web.

### Cliente local incluido

El launcher local reparado para pruebas esta en:

```text
clients/raclauncher/
```

Para ejecutarlo:

```bat
cd C:\xampp\jevzgames-infra\clients\raclauncher
.\Run-RacLauncher.cmd
```

Para que un juego se instale tipo Steam:

1. Empaqueta el juego en `.zip`.
2. Asegurate de que el `.exe` quede dentro del zip.
3. En `/admin/?section=games`, bloque `Builds instalables`, sube el `.zip`.
4. Indica la ruta relativa del ejecutable, por ejemplo `JumpFall.exe` o `Windows/JumpFall.exe`.
5. El cliente recibe `install_build` desde `/api/client/library/`.
6. La licencia se obtiene desde la web; el launcher no debe crear licencias nuevas.
7. El cliente compara la version instalada local con la ultima build y actualiza automaticamente si hay otra version ZIP.
8. El cliente descarga, verifica checksum, extrae en `%AppData%\JevzGamesClient\games\<slug>` y ejecuta el `.exe`.
9. Si la build es de plataforma externa, abre `launch_url` en vez de descargar.
10. Al ejecutar un juego de la biblioteca, el cliente puede enviar `POST /api/client/presence/` con `{"status":"in_game","game_slug":"slug"}` y volver a `online` al cerrar.

El cliente incluido es el launcher WinForms/PowerShell beta. El cliente CEF puede construirse despues sobre los mismos endpoints.

### Cliente 1.2

El launcher beta incluido agrega:

- Canje de codigos con `/api/client/redeem/`.
- Verificacion SHA-256/tamano antes de extraer builds ZIP.
- Borrado del `.zip` temporal cuando la extraccion termina bien.
- Auto-update de juegos instalados cuando cambia la build.
- Auto-update del launcher desde releases configuradas por Superroot en `/client/`.
- Cloud sync por `file_path`: baja el save antes de abrir el juego y lo sube al cerrar.
- Ventanas simples para grupos y Family Sharing.
- Argumentos de lanzamiento `--jevzgames-api`, `--jevzgames-token` y `--jevzgames-game`.

### Family Sharing, grupos y horas

Los usuarios pueden gestionar Family Sharing desde:

```text
/family/
```

Los grupos viven en:

```text
/groups/
```

La biblioteca del launcher incluye juegos propios y juegos compartidos por familia. La presencia `in_game` acumula `total_play_seconds`, visible en perfiles si la privacidad permite mostrar juegos.

### Update 1.1

Para actualizar una infraestructura ya montada sin reinstalar, copia los archivos nuevos conservando `app/config/config.php`, `storage/`, `public/uploads/` y `phpmailer/`, y ejecuta:

```bat
cd C:\xampp\jevzgames-infra
C:\xampp\php\php.exe update\1.1\update.php
```

El script crea tablas faltantes y agrega columnas necesarias como `games.visibility` y metadatos de plataforma externa en `game_builds`.

Para aplicar controles de visibilidad de juegos en instalaciones existentes, copia los archivos nuevos y ejecuta:

```bat
C:\xampp\php\php.exe update\1.1\update.php
```

### Update 1.2

Despues de copiar los archivos nuevos sobre una instalacion 1.1, conserva `app/config/config.php`, `storage/`, `public/uploads/` y `phpmailer/`, y ejecuta:

```bat
cd C:\xampp\jevzgames-infra
C:\xampp\php\php.exe update\1.2\update.php
```

Este update crea tablas nuevas de codigos de juego, Family Sharing, grupos, notificaciones persistentes y releases del launcher; tambien agrega columnas de playtime y cloud sync sin borrar datos.

## Apache

Configura el document root en `public/`. Las carpetas privadas tienen `.htaccess` para bloquear acceso directo, pero la proteccion principal es no exponerlas como raiz web.

## Nginx

Configura `root` apuntando a `public/` y bloquea acceso a archivos ocultos. Ver `docs/instalacion.md`.
