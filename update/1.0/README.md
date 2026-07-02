# JevzGames Update 1.0

This update prepares an existing JevzGames installation for launcher clients with:

- client `/me` and presence status APIs
- launcher-owned library metadata
- offline cache metadata
- direct message APIs for client chat
- `user_games.last_played_at`

## How To Apply

1. Backup the database and the current installation folder.
2. Copy the updated repository files over the existing installation.
3. Keep these existing local paths from the old installation:
   - `app/config/config.php`
   - `storage/`
   - `public/uploads/`
   - `phpmailer/` if it was installed manually
4. Run:

```bat
cd C:\xampp\jevzgames-infra
C:\xampp\php\php.exe update\1.0\update.php
```

The script is idempotent. It can be run again if a previous run was interrupted.

## Client Local Cache

Launcher clients should store only:

- `session.json`
- `library-cache.json`
- `games/<slug>/installed.json`

Do not store passwords locally. Offline launch must use a game already present in `owned_games` with `offline_available=true` and an installed build already present on disk.
