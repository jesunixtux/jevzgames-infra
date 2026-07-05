# JevzGames Infra Update 1.2

This update is for installations that already run JevzGames Infra 1.1.

## What It Adds

- Game license codes separated from item/inventory codes.
- `/games-code/` for direct game-code generation and external-game code requests.
- Required rejection/revocation reason for external game-code requests.
- System notifications for approved, rejected and revoked code requests.
- Family Sharing and shared games in the launcher library.
- Groups page and launcher group visibility.
- Playtime counter stored in `user_games.total_play_seconds`.
- Steam-like cloud sync with `file_path`, while keeping legacy `api_slot`.
- Launcher release repository and `/api/client/launcher/update-check/`.
- RacLauncher 0.1.12 beta with redeem, account switching, UTF-8 chat, automatic cloud sync, groups/family views, SHA-256 ZIP verification, ZIP cleanup and launcher auto-update.
- Safer game unlinking: internal games keep cloud saves, achievements and inventory; external-platform games can still purge linked game data.
- `/apis-read/` guide additions for launcher, codes and cloud sync.

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
php update/1.2/update.php
```

On XAMPP Windows:

```bat
cd C:\xampp\jevzgames-infra
C:\xampp\php\php.exe update\1.2\update.php
```

5. Open `/client/` as Superroot and publish the latest launcher ZIP if you want launcher auto-updates.
6. Use `/games-code/` to generate internal game codes or approve external-game requests.

The script is additive. It creates missing tables/columns and does not drop or truncate data.
