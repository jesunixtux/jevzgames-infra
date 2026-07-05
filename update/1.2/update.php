<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\Achievement;
use App\Models\ClientApp;
use App\Models\CloudSave;
use App\Models\Community;
use App\Models\DirectMessage;
use App\Models\EmailVerification;
use App\Models\ExternalGames;
use App\Models\FamilySharing;
use App\Models\Friend;
use App\Models\Game;
use App\Models\GameBuild;
use App\Models\GameCode;
use App\Models\GameGroup;
use App\Models\Inventory;
use App\Models\LauncherRepository;
use App\Models\Notification;
use App\Models\OAuth;
use App\Models\PasswordReset;
use App\Models\Playtime;
use App\Models\Presence;
use App\Models\PublicProfile;
use App\Models\PublishRequest;
use App\Models\SocialSettings;
use App\Models\SteamAuth;
use App\Models\SystemNotification;
use App\Models\UserAgreement;
use App\Models\Workshop;
use App\Security\Auth;

if (!is_installed()) {
    echo "JevzGames is not installed. Run /install/ first.\n";
    exit(1);
}

echo "JevzGames update 1.2\n";
echo "Applying database changes...\n";

try {
    Auth::ensureRememberTable();
    ClientApp::ensureTables();
    Presence::ensureTables();
    DirectMessage::ensureTables();
    PasswordReset::ensureTables();
    EmailVerification::ensureTables();
    SystemNotification::ensureTables();
    Notification::ensureTables();
    Friend::ensureTables();
    SocialSettings::ensureTables();
    PublicProfile::ensureTables();
    Community::ensureTables();
    Achievement::ensureTables();
    CloudSave::ensureTables();
    Inventory::ensureTables();
    OAuth::ensureTables();
    SteamAuth::ensureTables();
    UserAgreement::ensureTables();
    PublishRequest::ensureTables();
    Workshop::ensureTables();
    Game::ensureLicenseTables();
    Game::ensureUserGameMetadataColumns();
    Game::ensureVisibilityColumn();
    GameBuild::ensureTables();
    GameCode::ensureTables();
    FamilySharing::ensureTables();
    GameGroup::ensureTables();
    LauncherRepository::ensureTables();
    Playtime::ensureColumns();
    ExternalGames::ensureSystemRole();

    $external = ExternalGames::settings();
    if (!empty($external['enabled']) && !empty($external['configured'])) {
        ExternalGames::ensureExternalTables();
    }
} catch (Throwable $exception) {
    echo "Update failed: " . $exception->getMessage() . "\n";
    echo "Check that MySQL is running and app/config/config.php points to the installed database.\n";
    exit(1);
}

echo "Done.\n";
echo "Now copy the updated repository files over the existing installation, keeping app/config/config.php, storage/, public/uploads/ and phpmailer/ intact.\n";
echo "Open /client/ as Superroot to publish launcher releases, and /games-code/ to generate or approve game license codes.\n";
