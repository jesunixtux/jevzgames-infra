<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class GameCode
{
    private const MAX_BATCH = 100;
    private const REQUEST_STATUSES = ['pending', 'approved', 'rejected', 'revoked'];
    private const CODE_STATUSES = ['active', 'redeemed', 'revoked'];

    public static function ensureTables(): void
    {
        Game::ensureLicenseTables();
        Game::ensureVisibilityColumn();
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS game_license_codes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                batch_id VARCHAR(64) NOT NULL,
                code_hash VARCHAR(128) NOT NULL UNIQUE,
                code_preview VARCHAR(32) NOT NULL,
                source ENUM("internal", "external_request", "admin") NOT NULL DEFAULT "internal",
                request_id BIGINT UNSIGNED NULL,
                status ENUM("active", "redeemed", "revoked") NOT NULL DEFAULT "active",
                created_by INT UNSIGNED NULL,
                redeemed_by INT UNSIGNED NULL,
                redeemed_at DATETIME NULL,
                revoked_by INT UNSIGNED NULL,
                revoked_at DATETIME NULL,
                revoked_reason TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_game_license_codes_game (game_id),
                INDEX idx_game_license_codes_batch (batch_id),
                INDEX idx_game_license_codes_status (status),
                CONSTRAINT fk_game_license_codes_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_license_codes_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_game_license_codes_redeemer FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_game_license_codes_revoker FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS game_code_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                requester_user_id INT UNSIGNED NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                status ENUM("pending", "approved", "rejected", "revoked") NOT NULL DEFAULT "pending",
                request_note TEXT NULL,
                response_reason TEXT NULL,
                batch_id VARCHAR(64) NULL,
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_game_code_requests_game (game_id),
                INDEX idx_game_code_requests_user (requester_user_id),
                INDEX idx_game_code_requests_status (status),
                CONSTRAINT fk_game_code_requests_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_code_requests_user FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_code_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function requestStatuses(): array
    {
        return self::REQUEST_STATUSES;
    }

    public static function codeStatuses(): array
    {
        return self::CODE_STATUSES;
    }

    public static function canManageGameCodes(array $game, int $userId, bool $canManageAll = false): bool
    {
        if ($canManageAll) {
            return true;
        }

        return $userId > 0 && (int) ($game['owner_user_id'] ?? 0) === $userId;
    }

    public static function gameForAccess(int $gameId, int $userId, bool $canManageAll = false): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare('SELECT * FROM games WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch();
        if (!is_array($game)) {
            throw new RuntimeException('Juego no encontrado.');
        }

        if (!self::canManageGameCodes($game, $userId, $canManageAll)) {
            throw new RuntimeException('No tienes acceso a los codigos de este juego.');
        }

        return $game;
    }

    public static function listCodes(int $gameId, int $userId, bool $canManageAll = false): array
    {
        self::gameForAccess($gameId, $userId, $canManageAll);
        $stmt = Database::pdo()->prepare(
            'SELECT c.*, r.status AS request_status, u.username AS redeemed_username
             FROM game_license_codes c
             LEFT JOIN game_code_requests r ON r.id = c.request_id
             LEFT JOIN users u ON u.id = c.redeemed_by
             WHERE c.game_id = :game_id
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 500'
        );
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetchAll();
    }

    public static function listRequests(?string $status = null, ?int $userId = null): array
    {
        self::ensureTables();
        $params = [];
        $where = [];
        if ($status !== null && in_array($status, self::REQUEST_STATUSES, true)) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }
        if ($userId !== null && $userId > 0) {
            $where[] = 'r.requester_user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT r.*, g.name AS game_name, g.slug AS game_slug, g.source_type,
                    u.username AS requester_username, reviewer.username AS reviewer_username,
                    codes.code_count,
                    codes.code_previews
             FROM game_code_requests r
             INNER JOIN games g ON g.id = r.game_id
             INNER JOIN users u ON u.id = r.requester_user_id
             LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
             LEFT JOIN (
                SELECT request_id,
                       COUNT(*) AS code_count,
                       GROUP_CONCAT(code_preview ORDER BY id ASC SEPARATOR ", ") AS code_previews
                FROM game_license_codes
                GROUP BY request_id
             ) codes ON codes.request_id = r.id
             ' . ($where !== [] ? 'WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY FIELD(r.status, "pending", "approved", "rejected", "revoked"), r.created_at DESC, r.id DESC
             LIMIT 250'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function generateDirect(int $gameId, int $quantity, int $creatorId, bool $canManageAll = false): array
    {
        $game = self::gameForAccess($gameId, $creatorId, $canManageAll);
        if ((string) ($game['source_type'] ?? 'internal') === 'external' && !$canManageAll) {
            throw new RuntimeException('Los juegos externos deben solicitar codigos para aprobacion.');
        }

        return self::insertCodes($gameId, self::cleanQuantity($quantity), $creatorId, 'internal', null);
    }

    public static function requestForExternalGame(int $gameId, int $quantity, int $requesterId, string $note = ''): int
    {
        $game = self::gameForAccess($gameId, $requesterId, false);
        if ((string) ($game['source_type'] ?? 'internal') !== 'external') {
            throw new RuntimeException('Solo los juegos externos usan solicitud de codigos.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_code_requests (game_id, requester_user_id, quantity, status, request_note, created_at, updated_at)
             VALUES (:game_id, :requester_user_id, :quantity, "pending", :request_note, NOW(), NOW())'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'requester_user_id' => $requesterId,
            'quantity' => self::cleanQuantity($quantity),
            'request_note' => trim($note) !== '' ? trim($note) : null,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function approveRequest(int $requestId, int $adminUserId): array
    {
        $request = self::requestById($requestId, true);
        if ((string) $request['status'] !== 'pending') {
            throw new RuntimeException('La solicitud no esta pendiente.');
        }

        $codes = self::insertCodes((int) $request['game_id'], (int) $request['quantity'], $adminUserId, 'external_request', $requestId);
        $batchId = $codes['batch_id'];
        Database::pdo()->prepare(
            'UPDATE game_code_requests
             SET status = "approved", batch_id = :batch_id, reviewed_by = :reviewed_by, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $requestId,
            'batch_id' => $batchId,
            'reviewed_by' => $adminUserId,
        ]);

        SystemNotification::create(
            (int) $request['requester_user_id'],
            'game_codes.approved',
            'Solicitud de codigos aprobada',
            'Tu solicitud de ' . (int) $request['quantity'] . ' codigos para ' . (string) $request['game_name'] . ' fue aprobada.',
            '/games-code/?game_id=' . (int) $request['game_id'],
            $adminUserId,
            ['request_id' => $requestId, 'batch_id' => $batchId]
        );

        $codes['game_id'] = (int) $request['game_id'];
        $codes['request_id'] = $requestId;

        return $codes;
    }

    public static function rejectRequest(int $requestId, int $adminUserId, string $reason, string $status = 'rejected'): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Debes indicar el motivo.');
        }
        if (!in_array($status, ['rejected', 'revoked'], true)) {
            throw new RuntimeException('Estado de solicitud invalido.');
        }

        $request = self::requestById($requestId, true);
        Database::pdo()->prepare(
            'UPDATE game_code_requests
             SET status = :status, response_reason = :reason, reviewed_by = :reviewed_by, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $requestId,
            'status' => $status,
            'reason' => $reason,
            'reviewed_by' => $adminUserId,
        ]);

        if ($status === 'revoked') {
            Database::pdo()->prepare(
                'UPDATE game_license_codes
                 SET status = "revoked", revoked_by = :revoked_by, revoked_at = NOW(), revoked_reason = :reason, updated_at = NOW()
                 WHERE request_id = :request_id AND status = "active"'
            )->execute([
                'request_id' => $requestId,
                'revoked_by' => $adminUserId,
                'reason' => $reason,
            ]);
        }

        SystemNotification::create(
            (int) $request['requester_user_id'],
            $status === 'revoked' ? 'game_codes.request_revoked' : 'game_codes.rejected',
            $status === 'revoked' ? 'Solicitud de codigos revocada' : 'Solicitud de codigos rechazada',
            'Motivo: ' . $reason,
            '/games-code/?game_id=' . (int) $request['game_id'],
            $adminUserId,
            ['request_id' => $requestId, 'reason' => $reason]
        );
    }

    public static function revokeCode(int $codeId, int $actorUserId, string $reason, bool $canManageAll = false): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Debes indicar el motivo para revocar.');
        }

        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT c.*, g.owner_user_id
             FROM game_license_codes c
             INNER JOIN games g ON g.id = c.game_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $codeId]);
        $code = $stmt->fetch();
        if (!is_array($code)) {
            throw new RuntimeException('Codigo no encontrado.');
        }
        if (!$canManageAll && (int) ($code['owner_user_id'] ?? 0) !== $actorUserId) {
            throw new RuntimeException('No tienes acceso a este codigo.');
        }
        if ((string) $code['status'] === 'redeemed') {
            throw new RuntimeException('No puedes revocar un codigo ya canjeado.');
        }

        Database::pdo()->prepare(
            'UPDATE game_license_codes
             SET status = "revoked", revoked_by = :revoked_by, revoked_at = NOW(), revoked_reason = :reason, updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $codeId,
            'revoked_by' => $actorUserId,
            'reason' => $reason,
        ]);
    }

    public static function redeem(int $userId, string $code): ?array
    {
        self::ensureTables();
        $code = self::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT c.*, g.name AS game_name, g.slug AS game_slug
                 FROM game_license_codes c
                 INNER JOIN games g ON g.id = c.game_id
                 WHERE c.code_hash = :code_hash
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['code_hash' => self::codeHash($code)]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $pdo->rollBack();
                return null;
            }

            if ((string) $row['status'] !== 'active') {
                throw new RuntimeException('Este codigo de juego no esta activo.');
            }

            $license = Game::grantLicense($userId, (int) $row['game_id'], 'game_code');
            $pdo->prepare(
                'UPDATE game_license_codes
                 SET status = "redeemed", redeemed_by = :redeemed_by, redeemed_at = NOW(), updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'id' => (int) $row['id'],
                'redeemed_by' => $userId,
            ]);
            $pdo->commit();

            return [
                'code_preview' => (string) $row['code_preview'],
                'game_id' => (int) $row['game_id'],
                'reward_type' => 'game_license',
                'granted' => [
                    'licenses' => [$license],
                    'items' => [],
                ],
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function requestById(int $requestId, bool $forUpdate = false): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, g.name AS game_name, g.slug AS game_slug
             FROM game_code_requests r
             INNER JOIN games g ON g.id = r.game_id
             WHERE r.id = :id
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();
        if (!is_array($request)) {
            throw new RuntimeException('Solicitud no encontrada.');
        }

        return $request;
    }

    private static function insertCodes(int $gameId, int $quantity, int $creatorId, string $source, ?int $requestId): array
    {
        self::ensureTables();
        $batchId = 'jvg_batch_' . bin2hex(random_bytes(10));
        $codes = [];
        $previews = [];
        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_license_codes
                (game_id, batch_id, code_hash, code_preview, source, request_id, status, created_by, created_at, updated_at)
             VALUES
                (:game_id, :batch_id, :code_hash, :code_preview, :source, :request_id, "active", :created_by, NOW(), NOW())'
        );

        for ($i = 0; $i < $quantity; $i++) {
            $code = self::generateCode();
            try {
                $stmt->execute([
                    'game_id' => $gameId,
                    'batch_id' => $batchId,
                    'code_hash' => self::codeHash($code),
                    'code_preview' => self::codePreview($code),
                    'source' => $source,
                    'request_id' => $requestId,
                    'created_by' => $creatorId > 0 ? $creatorId : null,
                ]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $i--;
                    continue;
                }
                throw $exception;
            }
            $codes[] = $code;
            $previews[] = self::codePreview($code);
        }

        return [
            'batch_id' => $batchId,
            'codes' => $codes,
            'previews' => $previews,
            'quantity' => count($codes),
        ];
    }

    private static function cleanQuantity(int $quantity): int
    {
        if ($quantity < 1 || $quantity > self::MAX_BATCH) {
            throw new RuntimeException('Puedes solicitar o generar entre 1 y 100 codigos.');
        }

        return $quantity;
    }

    private static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private static function codeHash(string $code): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', self::normalizeCode($code), $pepper);
    }

    private static function codePreview(string $code): string
    {
        $code = self::normalizeCode($code);
        return substr($code, 0, 8) . '...' . substr($code, -6);
    }

    private static function generateCode(): string
    {
        return 'JVG-GAME-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
