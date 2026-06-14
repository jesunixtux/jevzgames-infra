# JevzGames Infra

Infraestructura monolitica modular en PHP puro para integrar videojuegos propios o de terceros bajo una cuenta principal, APIs HTTP/JSON y paneles web.

La primera fase deja una base funcional y simple:

- Instalador web inicial.
- Configuracion privada fuera de `public/`.
- Conexion PDO a MySQL/MariaDB.
- Usuario `superroot` creado solo desde el instalador.
- Registro, login, logout y perfil basico.
- Roles base: `user`, `developer`, `admin`, `supporter`, `superroot`.
- Helper de rutas y helper CDN `asset_url()`.
- API JSON inicial en `/api/status/`.
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
  profile/index.php
  api/index.php
  api/status/index.php
  admin/index.php
  supporter/index.php
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

## Crear un juego

La fase 1 solo deja el esquema preparado. Un juego debe registrarse en la tabla `games` con:

- `name`
- `slug`
- `description`
- `status`
- `current_version`
- `config_json`
- `endpoints_json`
- `external_database_json`
- `cdn_json`

La gestion web completa queda para una fase posterior.

## Vincular un juego con la plataforma

La cuenta de usuario se crea una sola vez en la base principal. La relacion con cada juego se guarda en `user_games`.

Los juegos deben consumir APIs HTTP/JSON y usar API keys por juego cuando esos endpoints se implementen.

## Apache

Configura el document root en `public/`. Las carpetas privadas tienen `.htaccess` para bloquear acceso directo, pero la proteccion principal es no exponerlas como raiz web.

## Nginx

Configura `root` apuntando a `public/` y bloquea acceso a archivos ocultos. Ver `docs/instalacion.md`.
