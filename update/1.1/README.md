# JevzGames Infra Update 1.1

This update is for installations that already have JevzGames Infra installed.

## What It Adds

- Cleaner launcher/client APIs and offline metadata.
- Web ZIP download is hidden when the Steam-like client is enabled.
- External platform builds, such as Steam launch URLs.
- Public/unlisted/private game visibility.
- Game developer and publisher metadata.
- `developer-extern` role for third-party game publishers.
- `/external-games/` section for third-party game publishing and configuration.
- Third-party game owners can add ZIP, remote ZIP and external-platform builds from `/external-games/`.
- Client achievement endpoints for launchers and Unity games.
- Unity SDK package for launcher-compatible achievements and bottom-screen unlock notifications.
- Superroot `extern-games-config` for an optional dedicated external-games database.
- Superroot `panic reinstall`, which reapplies schema/seeds/migrations without deleting existing data.

## How To Apply

1. Back up the current installation and database.
2. Copy the new repository files over the installed code.
3. Keep these paths from the existing server:
   - `app/config/config.php`
   - `storage/`
   - `public/uploads/`
   - `phpmailer/`
4. Run:

```bash
php update/1.1/update.php
```

5. Open Superroot and review:
   - `Features`
   - `Extern games`
   - `Maintenance`

The external-games database is disabled by default. Enable it only after creating a separate MySQL database and saving its credentials in Superroot.
