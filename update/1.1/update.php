<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

<<<<<<< Updated upstream
use App\Models\ClientApp;
use App\Models\DirectMessage;
use App\Models\Game;
use App\Models\GameBuild;
use App\Models\PasswordReset;
use App\Models\Presence;
=======
use App\Models\Game;
>>>>>>> Stashed changes

if (!is_installed()) {
    echo "JevzGames is not installed. Run /install/ first.\n";
    exit(1);
}

echo "JevzGames update 1.1\n";
echo "Applying database changes...\n";

try {
<<<<<<< Updated upstream
    ClientApp::ensureTables();
    Presence::ensureTables();
    DirectMessage::ensureTables();
    PasswordReset::ensureTables();
    Game::ensureLicenseTables();
    Game::ensureUserGameMetadataColumns();
    Game::ensureVisibilityColumn();
    GameBuild::ensureTables();
=======
    Game::ensureVisibilityColumn();
>>>>>>> Stashed changes
} catch (Throwable $exception) {
    echo "Update failed: " . $exception->getMessage() . "\n";
    echo "Check that MySQL is running and app/config/config.php points to the installed database.\n";
    exit(1);
}

echo "Done.\n";
<<<<<<< Updated upstream
echo "Now copy the updated repository files over the existing installation, keeping app/config/config.php, storage/, public/uploads/ and phpmailer/ intact.\n";
=======
echo "Game visibility is now available in Admin > Games.\n";
>>>>>>> Stashed changes
