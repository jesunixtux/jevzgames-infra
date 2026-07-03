# JevzGames Update 1.1

<<<<<<< Updated upstream
This update adds launcher-oriented distribution controls:

- game visibility: `public`, `unlisted`, `private`
- external platform builds with `launch_url`
- ZIP downloads hidden from web pages when the Steam-like client is enabled
- launcher flow where licenses are obtained from the web and the client only installs, updates or opens games
=======
This update adds game visibility controls for existing installations.

## What Changes

- Adds `games.visibility` with values:
  - `public`: visible in catalog and accessible by URL.
  - `unlisted`: hidden from catalog, accessible by direct URL.
  - `private`: hidden from catalog and direct public access; available to the owner and admin/superroot.
- Adds Admin controls to edit visibility.
- Keeps owned/licensed games in launcher/library responses.
>>>>>>> Stashed changes

## How To Apply

1. Backup the database and current installation folder.
2. Copy the updated repository files over the existing installation.
<<<<<<< Updated upstream
3. Keep these existing local paths from the old installation:
   - `app/config/config.php`
   - `storage/`
   - `public/uploads/`
   - `phpmailer/`
=======
3. Keep local paths from the old installation:
   - `app/config/config.php`
   - `storage/`
   - `public/uploads/`
   - `phpmailer/` if installed manually
>>>>>>> Stashed changes
4. Run:

```bat
cd C:\xampp\jevzgames-infra
C:\xampp\php\php.exe update\1.1\update.php
```

<<<<<<< Updated upstream
The script is idempotent and can be run again.

## What Changes

- `games.visibility` controls catalog visibility.
- `game_builds.delivery_type` differentiates ZIP builds from external platforms.
- `game_builds.platform`, `platform_app_id` and `platform_url` describe Steam or other platforms.
- The client API returns `install_build.launch_url` for external platforms.

For Steam, set platform to `steam` and either set `platform_app_id` or a full `steam://run/<app_id>` URL.
=======
The script is idempotent and can be run again safely.
>>>>>>> Stashed changes
