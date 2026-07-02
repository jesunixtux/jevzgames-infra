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
        'i18n.default_locale' => ['en', 'string'],
        'i18n.supported_locales_json' => ['{"en":"English","es":"Español"}', 'json'],
        'i18n.enabled_locales_json' => ['["en","es"]', 'json'],
        'content.translations_json' => ['{"en":{"home_title":"JevzGames","home_intro":"Your library, friends, achievements and inventory in one place.","games_intro":"Discover available games and builds.","library_intro":"Games linked or licensed to your account.","footer_text":"JevzGames, games and community."},"es":{"home_title":"JevzGames","home_intro":"Tu biblioteca, amigos, logros e inventario en un solo lugar.","games_intro":"Descubre juegos y builds disponibles.","library_intro":"Juegos vinculados o con licencia en tu cuenta.","footer_text":"JevzGames, juegos y comunidad."}}', 'json'],
        'content.home_title' => ['JevzGames', 'string'],
        'content.home_intro' => ['Tu biblioteca, amigos, logros e inventario en un solo lugar.', 'string'],
        'content.games_intro' => ['Descubre juegos y builds disponibles.', 'string'],
        'content.library_intro' => ['Juegos vinculados o con licencia en tu cuenta.', 'string'],
        'content.footer_text' => ['JevzGames, juegos y comunidad.', 'string'],
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
        'legal.eula_translations_json' => ['{"en":{"version":"1.0","title":"JevzGames EULA","body":"Write the platform terms of use and license here."},"es":{"version":"1.0","title":"EULA JevzGames","body":"Escribe aqui los terminos de uso y licencia de la plataforma."}}', 'json'],
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

    public static function supportedLocales(): array
    {
        $fallback = [
            'en' => 'English',
            'es' => 'Español',
        ];

        try {
            self::ensureDefaults();
            $raw = self::rawSettingValue('i18n.supported_locales_json');
            $decoded = json_decode($raw !== '' ? $raw : '{}', true);
            if (!is_array($decoded)) {
                return $fallback;
            }

            $locales = [];
            foreach ($decoded as $locale => $label) {
                $locale = self::normalizeLocale((string) $locale);
                $label = trim((string) $label);
                if ($locale !== '' && $label !== '' && strlen($label) <= 80) {
                    $locales[$locale] = $label;
                }
            }

            return $locales !== [] ? $locales : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public static function languageSettings(): array
    {
        $values = self::values();
        $supported = self::supportedLocales();
        $enabled = [];
        $rawEnabled = is_array($values['i18n.enabled_locales_json'] ?? null) ? $values['i18n.enabled_locales_json'] : [];

        foreach ($rawEnabled as $locale) {
            $locale = self::normalizeLocale((string) $locale);
            if ($locale !== '' && isset($supported[$locale]) && !in_array($locale, $enabled, true)) {
                $enabled[] = $locale;
            }
        }

        if ($enabled === []) {
            $enabled = ['en', 'es'];
        }

        $default = self::normalizeLocale((string) ($values['i18n.default_locale'] ?? 'en'));
        if (!isset($supported[$default])) {
            $default = 'en';
        }

        if (!in_array($default, $enabled, true)) {
            array_unshift($enabled, $default);
        }

        return [
            'default_locale' => $default,
            'enabled_locales' => array_values($enabled),
            'supported_locales' => $supported,
        ];
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
        $eulaTranslations = self::eulaTranslationsFromInput($input, $eulaEnabled === '1');
        $languageSettings = self::languageSettings();
        $defaultLocale = (string) $languageSettings['default_locale'];
        $defaultEula = $eulaTranslations[$defaultLocale] ?? reset($eulaTranslations);

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
            'legal.eula_version' => [(string) ($defaultEula['version'] ?? '1.0'), 'string'],
            'legal.eula_title' => [(string) ($defaultEula['title'] ?? 'JevzGames EULA'), 'string'],
            'legal.eula_body' => [(string) ($defaultEula['body'] ?? ''), 'string'],
            'legal.eula_translations_json' => [json_encode($eulaTranslations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json'],
        ];

        self::upsert($settings);
    }

    public static function saveContent(array $input): void
    {
        $languageSettings = self::languageSettingsFromInput($input);
        $translations = self::contentTranslationsFromInput($input);
        $defaultLocale = (string) $languageSettings['default_locale'];
        $defaultContent = $translations[$defaultLocale] ?? reset($translations);

        self::upsert([
            'i18n.default_locale' => [$defaultLocale, 'string'],
            'i18n.supported_locales_json' => [json_encode($languageSettings['supported_locales'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json'],
            'i18n.enabled_locales_json' => [json_encode($languageSettings['enabled_locales'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json'],
            'content.translations_json' => [json_encode($translations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json'],
            'content.home_title' => [(string) ($defaultContent['home_title'] ?? 'JevzGames'), 'string'],
            'content.home_intro' => [(string) ($defaultContent['home_intro'] ?? ''), 'string'],
            'content.games_intro' => [(string) ($defaultContent['games_intro'] ?? ''), 'string'],
            'content.library_intro' => [(string) ($defaultContent['library_intro'] ?? ''), 'string'],
            'content.footer_text' => [(string) ($defaultContent['footer_text'] ?? ''), 'string'],
        ]);
    }

    public static function contentSettings(?string $locale = null): array
    {
        $translations = self::contentTranslations();
        $settings = self::languageSettings();
        $locale = self::selectLocale($locale, $settings);

        return $translations[$locale] ?? $translations[$settings['default_locale']] ?? reset($translations);
    }

    public static function contentTranslations(): array
    {
        $values = self::values();
        $defaults = self::defaultContentTranslations($values);
        $stored = is_array($values['content.translations_json'] ?? null) ? $values['content.translations_json'] : [];

        return self::mergeLocalePayload($defaults, $stored, ['home_title', 'home_intro', 'games_intro', 'library_intro', 'footer_text']);
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

    public static function eulaSettings(?string $locale = null): array
    {
        $values = self::values();
        $translations = self::eulaTranslations();
        $languageSettings = self::languageSettings();
        $locale = self::selectLocale($locale, $languageSettings);
        $translation = $translations[$locale] ?? $translations[$languageSettings['default_locale']] ?? reset($translations);

        return [
            'enabled' => (bool) $values['legal.eula_enabled'],
            'required' => (bool) $values['legal.eula_required'],
            'locale' => $locale,
            'version' => (string) ($translation['version'] ?? $values['legal.eula_version']),
            'title' => (string) ($translation['title'] ?? $values['legal.eula_title']),
            'body' => (string) ($translation['body'] ?? $values['legal.eula_body']),
        ];
    }

    public static function eulaTranslations(): array
    {
        $values = self::values();
        $defaults = self::defaultEulaTranslations($values);
        $stored = is_array($values['legal.eula_translations_json'] ?? null) ? $values['legal.eula_translations_json'] : [];

        return self::mergeLocalePayload($defaults, $stored, ['version', 'title', 'body']);
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
                'me' => \url('/api/client/me/'),
                'library' => \url('/api/client/library/'),
                'obtain_game' => \url('/api/client/obtain-game/'),
                'redeem' => \url('/api/client/redeem/'),
                'inventory' => \url('/api/client/inventory/'),
                'license_check' => \url('/api/game-license/check/'),
                'presence' => \url('/api/client/presence/'),
                'presence_status' => \url('/api/client/presence/status/'),
                'messages_conversations' => \url('/api/client/messages/conversations/'),
                'messages_thread' => \url('/api/client/messages/thread/'),
                'messages_send' => \url('/api/client/messages/send/'),
                'messages_mark_read' => \url('/api/client/messages/mark-read/'),
                'logout' => \url('/api/client/logout/'),
            ],
            'offline_cache' => [
                'schema_version' => 1,
                'local_files' => [
                    'session' => 'session.json',
                    'library' => 'library-cache.json',
                    'installed_game' => 'games/<slug>/installed.json',
                ],
                'rules' => [
                    'store_passwords' => false,
                    'store_token' => true,
                    'launch_installed_owned_games_offline' => true,
                    'download_new_games_offline' => false,
                    'obtain_new_licenses_offline' => false,
                    'offline_requires_prior_license' => true,
                ],
            ],
        ];
    }

    private static function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim(str_replace('_', '-', $locale)));
        $locale = substr($locale, 0, 2);

        return preg_match('/^[a-z]{2}$/', $locale) ? $locale : '';
    }

    private static function selectLocale(?string $locale, array $settings): string
    {
        if ($locale === null && function_exists('current_locale')) {
            $locale = (string) \current_locale();
        }

        $locale = self::normalizeLocale((string) $locale);
        if ($locale !== '' && in_array($locale, $settings['enabled_locales'] ?? [], true)) {
            return $locale;
        }

        return (string) ($settings['default_locale'] ?? 'en');
    }

    private static function languageSettingsFromInput(array $input): array
    {
        $supported = self::localesFromInput($input);
        $default = self::normalizeLocale((string) ($input['default_locale'] ?? 'en'));
        if (!isset($supported[$default])) {
            $default = 'en';
        }

        $enabled = [];
        $rawEnabled = isset($input['enabled_locales']) && is_array($input['enabled_locales']) ? $input['enabled_locales'] : ['en', 'es'];
        foreach ($rawEnabled as $locale) {
            $locale = self::normalizeLocale((string) $locale);
            if ($locale !== '' && isset($supported[$locale]) && !in_array($locale, $enabled, true)) {
                $enabled[] = $locale;
            }
        }

        if (!in_array($default, $enabled, true)) {
            array_unshift($enabled, $default);
        }

        return [
            'default_locale' => $default,
            'enabled_locales' => array_values($enabled),
            'supported_locales' => $supported,
        ];
    }

    private static function localesFromInput(array $input): array
    {
        $supported = self::supportedLocales();
        $raw = trim((string) ($input['supported_locales_text'] ?? ''));
        if ($raw === '') {
            return $supported;
        }

        $parsed = [];
        foreach (preg_split('/\R+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '=')) {
                [$locale, $label] = explode('=', $line, 2);
            } else {
                $locale = $line;
                $label = strtoupper($line);
            }

            $locale = self::normalizeLocale((string) $locale);
            $label = self::cleanSettingText((string) $label, 80, 'El nombre del idioma');
            if ($locale === '' || $label === '') {
                throw new RuntimeException('Cada idioma debe usar formato codigo=Nombre, por ejemplo en=English.');
            }

            $parsed[$locale] = $label;
        }

        if (!isset($parsed['en'])) {
            $parsed = ['en' => 'English'] + $parsed;
        }

        if (!isset($parsed['es'])) {
            $parsed['es'] = 'Español';
        }

        if (count($parsed) > 12) {
            throw new RuntimeException('Puedes configurar hasta 12 idiomas.');
        }

        return $parsed;
    }

    private static function contentTranslationsFromInput(array $input): array
    {
        $current = self::contentTranslations();
        $posted = isset($input['content']) && is_array($input['content']) ? $input['content'] : [];
        $flatFallback = $posted === [] ? [
            'home_title' => $input['home_title'] ?? null,
            'home_intro' => $input['home_intro'] ?? null,
            'games_intro' => $input['games_intro'] ?? null,
            'library_intro' => $input['library_intro'] ?? null,
            'footer_text' => $input['footer_text'] ?? null,
        ] : [];
        $limits = [
            'home_title' => [160, 'El titulo de inicio'],
            'home_intro' => [1000, 'El texto de inicio'],
            'games_intro' => [500, 'El texto del catalogo'],
            'library_intro' => [500, 'El texto de biblioteca'],
            'footer_text' => [240, 'El footer'],
        ];
        $translations = [];

        foreach (self::supportedLocales() as $locale => $_label) {
            $row = isset($posted[$locale]) && is_array($posted[$locale]) ? $posted[$locale] : [];
            if ($flatFallback !== [] && $locale === self::languageSettings()['default_locale']) {
                $row = array_filter($flatFallback, static fn ($value): bool => $value !== null);
            }

            foreach ($limits as $key => [$max, $label]) {
                $value = array_key_exists($key, $row) ? (string) $row[$key] : (string) ($current[$locale][$key] ?? '');
                $translations[$locale][$key] = self::cleanSettingText($value, $max, $label . ' (' . $locale . ')');
            }

            if ($translations[$locale]['home_title'] === '') {
                $translations[$locale]['home_title'] = 'JevzGames';
            }
        }

        return $translations;
    }

    private static function eulaTranslationsFromInput(array $input, bool $requireBody): array
    {
        $current = self::eulaTranslations();
        $posted = isset($input['eula']) && is_array($input['eula']) ? $input['eula'] : [];
        $flatFallback = $posted === [] ? [
            'version' => $input['eula_version'] ?? null,
            'title' => $input['eula_title'] ?? null,
            'body' => $input['eula_body'] ?? null,
        ] : [];
        $languageSettings = self::languageSettings();
        $translations = [];

        foreach (self::supportedLocales() as $locale => $_label) {
            $row = isset($posted[$locale]) && is_array($posted[$locale]) ? $posted[$locale] : [];
            if ($flatFallback !== [] && $locale === $languageSettings['default_locale']) {
                $row = array_filter($flatFallback, static fn ($value): bool => $value !== null);
            }

            $version = self::cleanSettingText((string) ($row['version'] ?? $current[$locale]['version'] ?? '1.0'), 40, 'La version del EULA (' . $locale . ')');
            $title = self::cleanSettingText((string) ($row['title'] ?? $current[$locale]['title'] ?? 'JevzGames EULA'), 180, 'El titulo del EULA (' . $locale . ')');
            $body = trim((string) ($row['body'] ?? $current[$locale]['body'] ?? ''));

            if ($version === '') {
                throw new RuntimeException('La version del EULA (' . $locale . ') debe tener entre 1 y 40 caracteres.');
            }

            if ($title === '') {
                throw new RuntimeException('El titulo del EULA (' . $locale . ') debe tener entre 1 y 180 caracteres.');
            }

            if ($requireBody && in_array($locale, $languageSettings['enabled_locales'], true) && $body === '') {
                throw new RuntimeException('El texto del EULA (' . $locale . ') no puede estar vacio si el EULA esta activo.');
            }

            $translations[$locale] = [
                'version' => $version,
                'title' => $title,
                'body' => $body,
            ];
        }

        return $translations;
    }

    private static function defaultContentTranslations(array $values): array
    {
        $defaults = json_decode(self::DEFAULTS['content.translations_json'][0], true);
        $defaults = is_array($defaults) ? $defaults : [];
        $defaults['es'] = [
            'home_title' => (string) ($values['content.home_title'] ?? $defaults['es']['home_title'] ?? 'JevzGames'),
            'home_intro' => (string) ($values['content.home_intro'] ?? $defaults['es']['home_intro'] ?? ''),
            'games_intro' => (string) ($values['content.games_intro'] ?? $defaults['es']['games_intro'] ?? ''),
            'library_intro' => (string) ($values['content.library_intro'] ?? $defaults['es']['library_intro'] ?? ''),
            'footer_text' => (string) ($values['content.footer_text'] ?? $defaults['es']['footer_text'] ?? ''),
        ];

        return $defaults;
    }

    private static function defaultEulaTranslations(array $values): array
    {
        $defaults = json_decode(self::DEFAULTS['legal.eula_translations_json'][0], true);
        $defaults = is_array($defaults) ? $defaults : [];
        $defaults['es'] = [
            'version' => (string) ($values['legal.eula_version'] ?? $defaults['es']['version'] ?? '1.0'),
            'title' => (string) ($values['legal.eula_title'] ?? $defaults['es']['title'] ?? 'EULA JevzGames'),
            'body' => (string) ($values['legal.eula_body'] ?? $defaults['es']['body'] ?? ''),
        ];

        return $defaults;
    }

    private static function mergeLocalePayload(array $defaults, array $stored, array $keys): array
    {
        $merged = [];
        foreach (self::supportedLocales() as $locale => $_label) {
            $base = isset($defaults[$locale]) && is_array($defaults[$locale]) ? $defaults[$locale] : [];
            $row = isset($stored[$locale]) && is_array($stored[$locale]) ? $stored[$locale] : [];

            foreach ($keys as $key) {
                $merged[$locale][$key] = (string) ($row[$key] ?? $base[$key] ?? '');
            }
        }

        return $merged;
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
