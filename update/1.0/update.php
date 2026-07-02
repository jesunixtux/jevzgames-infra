<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;
use App\Models\DirectMessage;
use App\Models\Game;
use App\Models\GameBuild;
use App\Models\PasswordReset;
use App\Models\Presence;

if (!is_installed()) {
    echo "JevzGames is not installed. Run /install/ first.\n";
    exit(1);
}

echo "JevzGames update 1.0\n";
echo "Applying database changes...\n";

try {
    ClientApp::ensureTables();
    Game::ensureLicenseTables();
    Game::ensureUserGameMetadataColumns();
    GameBuild::ensureTables();
    Presence::ensureTables();
    DirectMessage::ensureTables();
    PasswordReset::ensureTables();
} catch (Throwable $exception) {
    echo "Update failed: " . $exception->getMessage() . "\n";
    echo "Check that MySQL is running and app/config/config.php points to the installed database.\n";
    exit(1);
}

echo "Done.\n";
echo "Now copy the updated repository files over the existing installation, keeping app/config/config.php and storage/ intact.\n";
