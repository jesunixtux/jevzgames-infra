# RacLauncher Beta 0.1.12

## Cambios principales

- Usa `owned_games` de `/api/client/library/` y mantiene compatibilidad con `linked_games`.
- Refresca usuario y presencia con `/api/client/me/`.
- Guarda `library-cache.json` para modo offline.
- Permite abrir juegos ya instalados offline si el backend marco `offline_available=true`.
- En offline no descarga builds nuevas ni obtiene licencias nuevas.
- Ya no obtiene licencias desde el launcher; la licencia se obtiene desde la pagina web.
- Actualiza automaticamente juegos instalados cuando la build ZIP cambia de version.
- Si una version viene de Steam u otra plataforma, usa `launch_url` para abrir esa plataforma.
- Refresca presencia `in_game` cada 5 segundos mientras el juego esta abierto.
- Al cerrar el juego, vuelve a presencia `online`.
- Agrega ventana de Mensajes con polling cada 10 segundos.
- Envia JSON en UTF-8 real y usa fuente compatible con emojis en chat.
- Agrega ventana de Logros por juego usando `/api/client/achievements/list/`.
- Permite probar desbloqueos con `/api/client/achievements/unlock/`; la lista visible no expone codigos internos.
- Canjea codigos de juego u objeto con `/api/client/redeem/`.
- Verifica `checksum` SHA-256 y tamano antes de extraer un ZIP.
- Borra el `.zip` temporal despues de una extraccion correcta.
- Sincroniza cloud saves configurados como `file_path`: pull antes de abrir el juego y push al cerrarlo.
- Muestra grupos y Family Sharing desde los endpoints del cliente.
- Revisa `/api/client/launcher/update-check/` y puede aplicar un ZIP de update del launcher publicado por Superroot.

## Como probar

1. Extrae el ZIP.
2. Ejecuta `Run-RacLauncher.cmd`.
3. Inicia sesion.
4. Entra a Biblioteca para generar cache local.
5. Instala un juego.
6. Abre el juego con `Jugar`.
7. Mira tu perfil web: deberia salir jugando el juego.
8. Cierra el juego.
9. En unos segundos debe volver a Cerrado/online.
10. Abre `Mensajes` para probar conversaciones privadas.
11. Selecciona un juego, abre `Logros` y verifica progreso/desbloqueados.
12. Prueba `Canjear` con un codigo de `/games-code/` o un codigo de inventario.
13. Configura un cloud save `file_path` en Admin y verifica que el launcher haga pull/push al abrir/cerrar.

## Modo offline

1. Inicia sesion al menos una vez con internet.
2. Entra a Biblioteca para guardar `%AppData%\RacLauncher\library-cache.json`.
3. Instala un juego.
4. Cierra el launcher, corta internet y abre de nuevo.
5. Debe mostrar la biblioteca cacheada.
6. Solo debe permitir jugar juegos instalados con licencia offline.

No se guarda la contrasena localmente.

## Backend usado

```text
https://racacount.jevzgames.com
```

## Datos locales

```text
%AppData%\RacLauncher\session.json
%AppData%\RacLauncher\library-cache.json
%AppData%\RacLauncher\games\<slug>\installed.json
%AppData%\RacLauncher\updates\
```

## Updates del launcher

Superroot publica releases desde `/client/`. El launcher consulta:

```text
POST /api/client/launcher/update-check/
```

Si hay una version mayor, descarga el ZIP, verifica `checksum_sha256` si existe, extrae en `%AppData%\RacLauncher\updates\` y ejecuta `Apply-RacLauncherUpdate.cmd` para reemplazar archivos al cerrar.
