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
        'content.home_title' => ['JevzGames Infra', 'string'],
        'content.home_intro' => ['Infraestructura monolitica modular para usuarios, juegos, APIs y paneles internos.', 'string'],
        'content.games_intro' => ['Catalogo publico de juegos registrados en la infraestructura.', 'string'],
        'content.library_intro' => ['Lista de juegos vinculados o licenciados en tu cuenta.', 'string'],
        'content.footer_text' => ['JevzGames Infraestructura modular en PHP puro.', 'string'],
        'maintenance.enabled' => ['0', 'boolean'],
        'maintenance.message' => ['La plataforma esta en mantenimiento. Vuelve mas tarde.', 'string'],
        'auth.email_verification_enabled' => ['0', 'boolean'],
        'auth.email_verification_required' => ['0', 'boolean'],
        'auth.email_verification_delivery' => ['log', 'string'],
        'auth.email_verification_from' => ['no-reply@jevzgames.local', 'string'],
        'auth.email_verification_from_name' => ['JevzGames', 'string'],
        'auth.email_verification_subject' => ['Verifica tu correo en JevzGames', 'string'],
        'auth.email_verification_ttl_hours' => ['24', 'integer'],
        'mail.smtp_host' => ['', 'string'],
        'mail.smtp_port' => ['587', 'integer'],
        'mail.smtp_username' => ['', 'string'],
        'mail.smtp_password' => ['', 'string', 1],
        'mail.smtp_encryption' => ['tls', 'string'],
        'mail.smtp_auth' => ['1', 'boolean'],
        'mail.smtp_timeout' => ['15', 'integer'],
        'legal.eula_enabled' => ['0', 'boolean'],
        'legal.eula_required' => ['0', 'boolean'],
        'legal.eula_version' => ['1.0', 'string'],
        'legal.eula_title' => ['EULA JevzGames', 'string'],
        'legal.eula_body' => ['Escribe aqui los terminos de uso y licencia de la plataforma.', 'string'],
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

    public static function saveAccessLegal(array $input): void
    {
        $emailEnabled = isset($input['email_verification_enabled']) ? '1' : '0';
        $emailRequired = isset($input['email_verification_required']) ? '1' : '0';
        $delivery = trim((string) ($input['email_verification_delivery'] ?? 'log'));
        $from = trim((string) ($input['email_verification_from'] ?? 'no-reply@jevzgames.local'));
        $fromName = trim((string) ($input['email_verification_from_name'] ?? 'JevzGames'));
        $subject = trim((string) ($input['email_verification_subject'] ?? 'Verifica tu correo en JevzGames'));
        $ttlHours = (int) ($input['email_verification_ttl_hours'] ?? 24);
        $smtpHost = trim((string) ($input['smtp_host'] ?? ''));
        $smtpPort = (int) ($input['smtp_port'] ?? 587);
        $smtpUsername = trim((string) ($input['smtp_username'] ?? ''));
        $smtpPassword = (string) ($input['smtp_password'] ?? '');
        $smtpEncryption = trim((string) ($input['smtp_encryption'] ?? 'tls'));
        $smtpAuth = isset($input['smtp_auth']) ? '1' : '0';
        $smtpTimeout = (int) ($input['smtp_timeout'] ?? 15);
        $eulaEnabled = isset($input['eula_enabled']) ? '1' : '0';
        $eulaRequired = isset($input['eula_required']) ? '1' : '0';
        $eulaVersion = trim((string) ($input['eula_version'] ?? '1.0'));
        $eulaTitle = trim((string) ($input['eula_title'] ?? 'EULA JevzGames'));
        $eulaBody = trim((string) ($input['eula_body'] ?? ''));

        if ($delivery === 'mail') {
            $delivery = 'smtp';
        }

        if (!in_array($delivery, ['log', 'smtp'], true)) {
            throw new RuntimeException('El envio de verificacion debe ser log o smtp.');
        }

        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El remitente de email no es valido.');
        }

        if ($fromName === '' || strlen($fromName) > 120) {
            throw new RuntimeException('El nombre del remitente debe tener entre 1 y 120 caracteres.');
        }

        if ($subject === '' || strlen($subject) > 180) {
            throw new RuntimeException('El asunto del correo debe tener entre 1 y 180 caracteres.');
        }

        if ($ttlHours < 1 || $ttlHours > 720) {
            throw new RuntimeException('La expiracion del enlace debe estar entre 1 y 720 horas.');
        }

        if ($smtpPort < 1 || $smtpPort > 65535) {
            throw new RuntimeException('El puerto SMTP no es valido.');
        }

        if (!in_array($smtpEncryption, ['none', 'tls', 'ssl'], true)) {
            throw new RuntimeException('La seguridad SMTP debe ser none, tls o ssl.');
        }

        if ($smtpTimeout < 1 || $smtpTimeout > 120) {
            throw new RuntimeException('El timeout SMTP debe estar entre 1 y 120 segundos.');
        }

        $currentPassword = self::rawSettingValue('mail.smtp_password');
        $smtpPasswordValue = $smtpPassword !== '' ? self::encryptSecret($smtpPassword) : $currentPassword;
        if ($emailEnabled === '1' && $delivery === 'smtp') {
            if ($smtpHost === '') {
                throw new RuntimeException('Debes indicar host SMTP si usas envio SMTP.');
            }
            if ($smtpAuth === '1' && $smtpUsername === '') {
                throw new RuntimeException('Debes indicar usuario SMTP si la autenticacion esta activa.');
            }
            if ($smtpAuth === '1' && $smtpPasswordValue === '') {
                throw new RuntimeException('Debes indicar password SMTP si la autenticacion esta activa.');
            }
        }

        if ($eulaVersion === '' || strlen($eulaVersion) > 40) {
            throw new RuntimeException('La version del EULA debe tener entre 1 y 40 caracteres.');
        }

        if ($eulaTitle === '' || strlen($eulaTitle) > 180) {
            throw new RuntimeException('El titulo del EULA debe tener entre 1 y 180 caracteres.');
        }

        if ($eulaEnabled === '1' && $eulaBody === '') {
            throw new RuntimeException('El texto del EULA no puede estar vacio si el EULA esta activo.');
        }

        $settings = [
            'auth.email_verification_enabled' => [$emailEnabled, 'boolean'],
            'auth.email_verification_required' => [$emailRequired, 'boolean'],
            'auth.email_verification_delivery' => [$delivery, 'string'],
            'auth.email_verification_from' => [$from !== '' ? $from : 'no-reply@jevzgames.local', 'string'],
            'auth.email_verification_from_name' => [$fromName, 'string'],
            'auth.email_verification_subject' => [$subject, 'string'],
            'auth.email_verification_ttl_hours' => [(string) $ttlHours, 'integer'],
            'mail.smtp_host' => [$smtpHost, 'string'],
            'mail.smtp_port' => [(string) $smtpPort, 'integer'],
            'mail.smtp_username' => [$smtpUsername, 'string'],
            'mail.smtp_password' => [$smtpPasswordValue, 'string', 1],
            'mail.smtp_encryption' => [$smtpEncryption, 'string'],
            'mail.smtp_auth' => [$smtpAuth, 'boolean'],
            'mail.smtp_timeout' => [(string) $smtpTimeout, 'integer'],
            'legal.eula_enabled' => [$eulaEnabled, 'boolean'],
            'legal.eula_required' => [$eulaRequired, 'boolean'],
            'legal.eula_version' => [$eulaVersion, 'string'],
            'legal.eula_title' => [$eulaTitle, 'string'],
            'legal.eula_body' => [$eulaBody, 'string'],
        ];

        self::upsert($settings);
    }

    public static function saveContent(array $input): void
    {
        $homeTitle = self::cleanSettingText((string) ($input['home_title'] ?? 'JevzGames Infra'), 160, 'El titulo de inicio');
        $homeIntro = self::cleanSettingText((string) ($input['home_intro'] ?? ''), 1000, 'El texto de inicio');
        $gamesIntro = self::cleanSettingText((string) ($input['games_intro'] ?? ''), 500, 'El texto del catalogo');
        $libraryIntro = self::cleanSettingText((string) ($input['library_intro'] ?? ''), 500, 'El texto de biblioteca');
        $footerText = self::cleanSettingText((string) ($input['footer_text'] ?? ''), 240, 'El footer');

        self::upsert([
            'content.home_title' => [$homeTitle !== '' ? $homeTitle : 'JevzGames Infra', 'string'],
            'content.home_intro' => [$homeIntro, 'string'],
            'content.games_intro' => [$gamesIntro, 'string'],
            'content.library_intro' => [$libraryIntro, 'string'],
            'content.footer_text' => [$footerText, 'string'],
        ]);
    }

    public static function contentSettings(): array
    {
        $values = self::values();

        return [
            'home_title' => (string) $values['content.home_title'],
            'home_intro' => (string) $values['content.home_intro'],
            'games_intro' => (string) $values['content.games_intro'],
            'library_intro' => (string) $values['content.library_intro'],
            'footer_text' => (string) $values['content.footer_text'],
        ];
    }

    public static function saveMaintenance(array $input): void
    {
        $message = self::cleanSettingText((string) ($input['maintenance_message'] ?? ''), 1000, 'El mensaje de mantenimiento');
        if ($message === '') {
            $message = 'La plataforma esta en mantenimiento. Vuelve mas tarde.';
        }

        self::upsert([
            'maintenance.enabled' => [isset($input['maintenance_enabled']) ? '1' : '0', 'boolean'],
            'maintenance.message' => [$message, 'string'],
        ]);
    }

    public static function maintenanceSettings(): array
    {
        $values = self::values();

        return [
            'enabled' => (bool) $values['maintenance.enabled'],
            'message' => (string) $values['maintenance.message'],
            'allowed_roles' => ['admin', 'superroot', 'developer'],
        ];
    }

    public static function emailVerificationSettings(): array
    {
        $values = self::values();
        $delivery = (string) $values['auth.email_verification_delivery'];
        if ($delivery === 'mail') {
            $delivery = 'smtp';
        }
        $smtpPassword = self::decryptSecret((string) $values['mail.smtp_password']);

        return [
            'enabled' => (bool) $values['auth.email_verification_enabled'],
            'required' => (bool) $values['auth.email_verification_required'],
            'delivery' => $delivery,
            'from' => (string) $values['auth.email_verification_from'],
            'from_name' => (string) $values['auth.email_verification_from_name'],
            'subject' => (string) $values['auth.email_verification_subject'],
            'ttl_hours' => max(1, (int) $values['auth.email_verification_ttl_hours']),
            'smtp' => [
                'host' => (string) $values['mail.smtp_host'],
                'port' => max(1, (int) $values['mail.smtp_port']),
                'username' => (string) $values['mail.smtp_username'],
                'password' => $smtpPassword,
                'password_configured' => $smtpPassword !== '',
                'encryption' => (string) $values['mail.smtp_encryption'],
                'auth' => (bool) $values['mail.smtp_auth'],
                'timeout' => max(1, (int) $values['mail.smtp_timeout']),
            ],
        ];
    }

    public static function emailVerificationEnabled(): bool
    {
        return (bool) self::values()['auth.email_verification_enabled'];
    }

    public static function emailVerificationRequired(): bool
    {
        $values = self::values();

        return !empty($values['auth.email_verification_enabled']) && !empty($values['auth.email_verification_required']);
    }

    public static function eulaSettings(): array
    {
        $values = self::values();

        return [
            'enabled' => (bool) $values['legal.eula_enabled'],
            'required' => (bool) $values['legal.eula_required'],
            'version' => (string) $values['legal.eula_version'],
            'title' => (string) $values['legal.eula_title'],
            'body' => (string) $values['legal.eula_body'],
        ];
    }

    public static function eulaRequired(): bool
    {
        $values = self::values();

        return !empty($values['legal.eula_enabled']) && !empty($values['legal.eula_required']);
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
                'obtain_game' => \url('/api/client/obtain-game/'),
                'redeem' => \url('/api/client/redeem/'),
                'inventory' => \url('/api/client/inventory/'),
                'license_check' => \url('/api/game-license/check/'),
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
                VALUES (:setting_key, :setting_value, :value_type, :is_private, NOW(), NOW())';
        if ($overwrite) {
            $sql .= ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), is_private = VALUES(is_private), updated_at = NOW()';
        } else {
            $sql .= ' ON DUPLICATE KEY UPDATE setting_key = setting_key';
        }

        $stmt = Database::pdo()->prepare($sql);
        foreach ($settings as $key => $definition) {
            $value = $definition[0] ?? '';
            $type = $definition[1] ?? 'string';
            $isPrivate = (int) ($definition[2] ?? 0);
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => (string) $value,
                'value_type' => (string) $type,
                'is_private' => $isPrivate,
            ]);
        }
    }

    private static function rawSettingValue(string $key): string
    {
        $stmt = Database::pdo()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? '' : (string) $value;
    }

    private static function cleanSettingText(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if (strlen($value) > $maxLength) {
            throw new RuntimeException($label . ' es demasiado largo.');
        }

        return $value;
    }

    private static function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('La extension OpenSSL es requerida para guardar secretos SMTP.');
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el secreto SMTP.');
        }

        return 'enc:v1:' . base64_encode($iv) . ':' . base64_encode($cipher);
    }

    private static function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (!str_starts_with($stored, 'enc:v1:')) {
            return $stored;
        }

        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $parts = explode(':', $stored, 4);
        if (count($parts) !== 4) {
            return '';
        }

        $iv = base64_decode($parts[2], true);
        $cipher = base64_decode($parts[3], true);
        if ($iv === false || $cipher === false) {
            return '';
        }

        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    private static function secretKey(): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash('sha256', $pepper, true);
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
