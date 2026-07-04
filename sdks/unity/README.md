# JevzGames Unity SDK

This SDK lets a Unity game launched by a JevzGames-compatible client use the local infrastructure APIs.

## Runtime flow

1. The launcher starts the game with:
   - `--jevzgames-api=http://jevzgames.local`
   - `--jevzgames-token=jvg_ct_...`
   - `--jevzgames-game=game-slug`
2. `JevzGamesApiClient` reads those values from command-line arguments or these environment variables:
   - `JEVZGAMES_API_BASE`
   - `JEVZGAMES_CLIENT_TOKEN`
   - `JEVZGAMES_GAME_SLUG`
3. The game can list and unlock achievements through `/api/client/achievements/*`.
4. When an unlock succeeds and is new, the SDK shows a bottom-screen toast.

## Quick setup

1. Import `JevzGamesApi.unitypackage`.
2. Add an empty GameObject in the first scene.
3. Add `JevzGamesLauncherBridge`.
4. Set fallback `Base Url` and `Game Slug` for local testing.
5. Unlock an achievement from code:

```csharp
using JevzGames.Api;

public class DemoAchievement : MonoBehaviour
{
    public void OnFirstRun()
    {
        JevzGamesApiClient.Instance.UnlockAchievement("first_run");
    }
}
```

For local editor tests without the launcher, call:

```csharp
JevzGamesApiClient.Instance.Configure(
    "http://jevzgames.local",
    "jvg_ct_your_client_token",
    "your-game-slug"
);
```
