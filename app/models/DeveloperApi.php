<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class DeveloperApi
{
    public static function context(string $clientToken): array
    {
        $session = ClientApp::authenticate($clientToken, false);
        if (!$session) {
            throw new RuntimeException('Token invalido o expirado.');
        }

        $user = User::findByIdWithRoles((int) $session['user_id']);
        if (!$user) {
            throw new RuntimeException('Token invalido o expirado.');
        }

        $roles = $user['roles'] ?? [];
        if (count(array_intersect($roles, ['developer', 'admin', 'superroot'])) === 0) {
            throw new RuntimeException('Acceso developer requerido.');
        }

        return [
            'session' => $session,
            'user' => $user,
            'user_id' => (int) $user['id'],
            'all_games' => count(array_intersect($roles, ['admin', 'superroot'])) > 0,
        ];
    }

    public static function games(array $context): array
    {
        $params = [];
        $where = '1=1';
        if (empty($context['all_games'])) {
            $where = 'g.owner_user_id = :owner_user_id';
            $params['owner_user_id'] = (int) $context['user_id'];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT g.id, g.owner_user_id, g.name, g.slug, g.description, g.status, g.current_version, g.created_at, g.updated_at,
                    builds.build_count,
                    builds.latest_build_at,
                    keys.active_api_keys,
                    keys.revoked_api_keys
             FROM games g
             LEFT JOIN (
                SELECT game_id, COUNT(*) AS build_count, MAX(created_at) AS latest_build_at
                FROM game_builds
                GROUP BY game_id
             ) builds ON builds.game_id = g.id
             LEFT JOIN (
                SELECT game_id,
                       SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) AS active_api_keys,
                       SUM(CASE WHEN status = "revoked" THEN 1 ELSE 0 END) AS revoked_api_keys
                FROM game_api_keys
                GROUP BY game_id
             ) keys ON keys.game_id = g.id
             WHERE ' . $where . '
             ORDER BY FIELD(g.status, "published", "beta", "playtest", "development", "archived"), g.name ASC'
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'owner_user_id' => isset($row['owner_user_id']) ? (int) $row['owner_user_id'] : null,
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
            'status' => (string) $row['status'],
            'current_version' => $row['current_version'] ?? null,
            'build_count' => (int) ($row['build_count'] ?? 0),
            'latest_build_at' => $row['latest_build_at'] ?? null,
            'active_api_keys' => (int) ($row['active_api_keys'] ?? 0),
            'revoked_api_keys' => (int) ($row['revoked_api_keys'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ], $stmt->fetchAll());
    }

    public static function detail(array $context, array $input): array
    {
        $game = self::accessibleGame($context, $input);
        $gameId = (int) $game['id'];

        return [
            'game' => self::gamePayload($game),
            'builds' => GameBuild::list($gameId),
            'latest_build' => GameBuild::latestForGame($gameId),
            'api_keys' => self::apiKeysForGame($gameId),
        ];
    }

    public static function createApiKey(array $context, array $input): array
    {
        $game = self::accessibleGame($context, $input);

        return Admin::createGameApiKey((int) $game['id']);
    }

    public static function revokeApiKey(array $context, array $input): void
    {
        $apiKeyId = (int) ($input['api_key_id'] ?? 0);
        if ($apiKeyId <= 0) {
            throw new RuntimeException('api_key_id es requerido.');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT k.*, g.owner_user_id
             FROM game_api_keys k
             INNER JOIN games g ON g.id = k.game_id
             WHERE k.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $apiKeyId]);
        $key = $stmt->fetch();
        if (!is_array($key)) {
            throw new RuntimeException('API key no encontrada.');
        }

        self::assertCanAccessGame($context, $key);
        Admin::revokeGameApiKey($apiKeyId);
    }

    public static function runTest(array $context, array $input): array
    {
        $game = self::accessibleGame($context, $input);
        $test = (string) ($input['test'] ?? 'game_info');
        $publicKey = trim((string) ($input['public_key'] ?? ''));
        if ($publicKey === '') {
            $publicKey = self::firstActivePublicKey((int) $game['id']);
        }

        $endpoint = '';
        $request = [];
        $response = [];

        if ($test === 'game_info') {
            $endpoint = \url('/api/game-info/');
            $request = ['public_key' => $publicKey];
            $response = ['game' => OAuth::publicGamePayload($game)];
        } elseif ($test === 'version_check') {
            $endpoint = \url('/api/version-check/');
            $version = (string) ($input['version'] ?? '0.0.0');
            $request = ['public_key' => $publicKey, 'version' => $version];
            $currentVersion = (string) ($game['current_version'] ?? '');
            $response = [
                'game' => OAuth::publicGamePayload($game),
                'current_version' => $currentVersion !== '' ? $currentVersion : null,
                'client_version' => $version,
                'update_required' => $currentVersion !== '' && version_compare($version, $currentVersion, '<'),
            ];
        } elseif ($test === 'database_status') {
            $endpoint = \url('/api/game-database/status/');
            $request = ['Authorization' => 'Bearer <game_access_token>'];
            $response = ['dedicated_database' => GameDatabase::publicStatusFromGame($game)];
        } elseif ($test === 'oauth_device_code') {
            $endpoint = \url('/api/oauth/device-code/');
            $request = ['public_key' => $publicKey];
            $response = OAuth::createDeviceCode($publicKey);
        } else {
            throw new RuntimeException('Prueba no soportada.');
        }

        return [
            'test' => $test,
            'endpoint' => $endpoint,
            'request' => $request,
            'response' => $response,
        ];
    }

    public static function accessibleGame(array $context, array $input): array
    {
        $gameId = (int) ($input['game_id'] ?? 0);
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));

        if ($gameId <= 0 && $slug === '') {
            throw new RuntimeException('game_id o slug es requerido.');
        }

        $where = $gameId > 0 ? 'g.id = :value' : 'g.slug = :value';
        $stmt = Database::pdo()->prepare(
            'SELECT g.*, u.username AS owner_username
             FROM games g
             LEFT JOIN users u ON u.id = g.owner_user_id
             WHERE ' . $where . '
             LIMIT 1'
        );
        $stmt->execute(['value' => $gameId > 0 ? $gameId : $slug]);
        $game = $stmt->fetch();
        if (!is_array($game)) {
            throw new RuntimeException('Juego no encontrado.');
        }

        self::assertCanAccessGame($context, $game);

        return $game;
    }

    public static function statusForException(\Throwable $exception): int
    {
        $message = $exception->getMessage();
        if ($message === 'Token invalido o expirado.') {
            return 401;
        }
        if (in_array($message, ['Acceso developer requerido.', 'No tienes acceso a este juego.'], true)) {
            return 403;
        }

        return 400;
    }

    private static function assertCanAccessGame(array $context, array $game): void
    {
        if (!empty($context['all_games'])) {
            return;
        }

        if ((int) ($game['owner_user_id'] ?? 0) !== (int) $context['user_id']) {
            throw new RuntimeException('No tienes acceso a este juego.');
        }
    }

    private static function apiKeysForGame(int $gameId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, game_id, public_key, status, last_used_at, created_at, revoked_at
             FROM game_api_keys
             WHERE game_id = :game_id
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['game_id' => $gameId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'game_id' => (int) $row['game_id'],
            'public_key' => (string) $row['public_key'],
            'status' => (string) $row['status'],
            'last_used_at' => $row['last_used_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
        ], $stmt->fetchAll());
    }

    private static function firstActivePublicKey(int $gameId): string
    {
        $stmt = Database::pdo()->prepare(
            'SELECT public_key
             FROM game_api_keys
             WHERE game_id = :game_id AND status = "active"
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['game_id' => $gameId]);
        $publicKey = $stmt->fetchColumn();
        if (!is_string($publicKey) || $publicKey === '') {
            throw new RuntimeException('Este juego no tiene API key activa.');
        }

        return $publicKey;
    }

    private static function gamePayload(array $game): array
    {
        return [
            'id' => (int) $game['id'],
            'owner_user_id' => isset($game['owner_user_id']) ? (int) $game['owner_user_id'] : null,
            'owner_username' => $game['owner_username'] ?? null,
            'name' => (string) $game['name'],
            'slug' => (string) $game['slug'],
            'description' => (string) ($game['description'] ?? ''),
            'status' => (string) $game['status'],
            'current_version' => $game['current_version'] ?? null,
            'config' => Game::decodeJson($game['config_json'] ?? null),
            'endpoints' => Game::decodeJson($game['endpoints_json'] ?? null),
            'dedicated_database' => GameDatabase::publicStatusFromGame($game),
            'cdn' => Game::decodeJson($game['cdn_json'] ?? null),
            'created_at' => $game['created_at'] ?? null,
            'updated_at' => $game['updated_at'] ?? null,
        ];
    }
}
