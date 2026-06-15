<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class PlatformSettings
{
    private const DEFAULTS = [
        'features.publish_on_games_enabled' => ['0', 'boolean'],
        'features.workshop_enabled' => ['0', 'boolean'],
        'features.client_enabled' => ['0', 'boolean'],
        'client.name' => ['JevzGames Client', 'string'],
        'client.download_url' => ['', 'string'],
        'client.min_version' => ['0.1.0', 'string'],
        'client.config_json' => ['', 'json'],
    ];

    public static function values(): array
    {
        self::ensureDefaults();
        $stmt = Database::pdo()->query(
            'SELECT setting_key, setting_value, value_type
             FROM system_settings
             WHERE setting_key IN ("' . implode('","', array_keys(self::DEFAULTS)) . '")'
        );

        $values = [];
        foreach (self::DEFAULTS as $key => [$default, $type]) {
            $values[$key] = self::cast($default, $type);
        }

        foreach ($stmt->fetchAll() as $row) {
            $values[(string) $row['setting_key']] = self::cast((string) ($row['setting_value'] ?? ''), (string) ($row['value_type'] ?? 'string'));
        }

        return $values;
    }

    public static function enabled(string $feature): bool
    {
        $key = 'features.' . $feature . '_enabled';
        $values = self::values();

        return !empty($values[$key]);
    }

    public static function save(array $input): void
    {
        $publish = isset($input['publish_on_games_enabled']) ? '1' : '0';
        $workshop = isset($input['workshop_enabled']) ? '1' : '0';
        $client = isset($input['client_enabled']) ? '1' : '0';
        $clientName = trim((string) ($input['client_name'] ?? 'JevzGames Client'));
        $downloadUrl = trim((string) ($input['client_download_url'] ?? ''));
        $minVersion = trim((string) ($input['client_min_version'] ?? '0.1.0'));
        $configJson = trim((string) ($input['client_config_json'] ?? ''));

        if ($clientName === '' || strlen($clientName) > 120) {
            throw new RuntimeException('El nombre del cliente debe tener entre 1 y 120 caracteres.');
        }

        if ($downloadUrl !== '' && !filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('La URL de descarga del cliente no es valida.');
        }

        if ($minVersion !== '' && strlen($minVersion) > 40) {
            throw new RuntimeException('La version minima del cliente es demasiado larga.');
        }

        if ($configJson !== '') {
            $decoded = json_decode($configJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new RuntimeException('El JSON del cliente no es valido.');
            }
            $configJson = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $settings = [
            'features.publish_on_games_enabled' => [$publish, 'boolean'],
            'features.workshop_enabled' => [$workshop, 'boolean'],
            'features.client_enabled' => [$client, 'boolean'],
            'client.name' => [$clientName, 'string'],
            'client.download_url' => [$downloadUrl, 'string'],
            'client.min_version' => [$minVersion !== '' ? $minVersion : '0.1.0', 'string'],
            'client.config_json' => [$configJson, 'json'],
        ];

        self::upsert($settings);
    }

    public static function clientConfigPayload(): array
    {
        $values = self::values();

        return [
            'enabled' => (bool) $values['features.client_enabled'],
            'name' => (string) $values['client.name'],
            'download_url' => (string) $values['client.download_url'],
            'min_version' => (string) $values['client.min_version'],
            'config' => is_array($values['client.config_json']) ? $values['client.config_json'] : [],
            'endpoints' => [
                'login' => \url('/api/client/login/'),
                'library' => \url('/api/client/library/'),
                'redeem' => \url('/api/client/redeem/'),
                'inventory' => \url('/api/client/inventory/'),
            ],
        ];
    }

    private static function ensureDefaults(): void
    {
        self::upsert(self::DEFAULTS, false);
    }

    private static function upsert(array $settings, bool $overwrite = true): void
    {
        $sql = 'INSERT INTO system_settings (setting_key, setting_value, value_type, is_private, created_at, updated_at)
                VALUES (:setting_key, :setting_value, :value_type, 0, NOW(), NOW())';
        if ($overwrite) {
            $sql .= ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()';
        } else {
            $sql .= ' ON DUPLICATE KEY UPDATE setting_key = setting_key';
        }

        $stmt = Database::pdo()->prepare($sql);
        foreach ($settings as $key => [$value, $type]) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => (string) $value,
                'value_type' => (string) $type,
            ]);
        }
    }

    private static function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'integer' => (int) $value,
            'json' => json_decode($value !== '' ? $value : '{}', true) ?: [],
            default => $value,
        };
    }
}
