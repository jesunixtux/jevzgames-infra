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

La fase 1 incluye `/api/status/`. Las API keys por juego quedan preparadas en `game_api_keys`.

## Roles

- `user`: usuario normal.
- `developer`: gestiona juegos propios.
- `admin`: administra usuarios, juegos, logs y moderacion.
- `supporter`: atiende soporte.
- `superroot`: controla configuracion sensible.

`superroot` no se registra desde la web publica. Solo lo crea el instalador inicial.

## Sistema de codigos

La tabla `redeemable_codes` guarda codigos como hash, no como texto plano. `code_redemptions` registra quien canjeo cada codigo.

La logica de canje queda para una fase posterior, pero el esquema ya evita repetir canjes por usuario y codigo.

## Integraciones externas

`external_integrations` guarda proveedores como Steam, Epic o GOG sin hardcodearlos. `external_accounts` vincula cuentas externas a usuarios internos.

La fase 1 no implementa OAuth ni integraciones reales.
