# RacLauncher Beta 0.1.8

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
```
