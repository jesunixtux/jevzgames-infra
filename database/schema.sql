CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'blocked', 'pending_recovery') NOT NULL DEFAULT 'active',
    display_name VARCHAR(120) NULL,
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_status (status),
    INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    session_token_hash VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS games (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT UNSIGNED NULL,
    name VARCHAR(140) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    description TEXT NULL,
    status ENUM('development', 'playtest', 'beta', 'published', 'archived') NOT NULL DEFAULT 'development',
    current_version VARCHAR(60) NULL,
    banner_path VARCHAR(255) NULL,
    config_json LONGTEXT NULL,
    endpoints_json LONGTEXT NULL,
    external_database_json LONGTEXT NULL,
    cdn_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_games_owner (owner_user_id),
    INDEX idx_games_status (status),
    CONSTRAINT fk_games_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_games (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NOT NULL,
    external_player_id VARCHAR(190) NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    extra_json LONGTEXT NULL,
    UNIQUE KEY uq_user_games_user_game (user_id, game_id),
    INDEX idx_user_games_game (game_id),
    CONSTRAINT fk_user_games_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_games_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_builds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    version VARCHAR(60) NOT NULL,
    channel ENUM('development', 'playtest', 'beta', 'stable', 'archived') NOT NULL DEFAULT 'development',
    file_path VARCHAR(255) NULL,
    checksum VARCHAR(128) NULL,
    notes TEXT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_game_builds_game_version_channel (game_id, version, channel),
    CONSTRAINT fk_game_builds_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NULL,
    author_user_id INT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_news_game (game_id),
    CONSTRAINT fk_news_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    event VARCHAR(120) NOT NULL,
    level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
    ip_address VARCHAR(45) NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_logs_user (user_id),
    INDEX idx_activity_logs_event (event),
    CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value LONGTEXT NULL,
    value_type ENUM('string', 'integer', 'boolean', 'json') NOT NULL DEFAULT 'string',
    is_private TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cdn_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    mode ENUM('local', 'external') NOT NULL DEFAULT 'local',
    base_url VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    config_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cdn_settings_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_api_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    public_key VARCHAR(120) NOT NULL UNIQUE,
    secret_key_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    INDEX idx_game_api_keys_game (game_id),
    CONSTRAINT fk_game_api_keys_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_profiles (
    user_id INT UNSIGNED PRIMARY KEY,
    display_name VARCHAR(120) NULL,
    bio TEXT NULL,
    avatar_path VARCHAR(255) NULL,
    visibility ENUM('public', 'private') NOT NULL DEFAULT 'public',
    extra_json LONGTEXT NULL,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_public_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    assigned_user_id INT UNSIGNED NULL,
    subject VARCHAR(180) NOT NULL,
    status ENUM('open', 'closed', 'solved', 'unsolved') NOT NULL DEFAULT 'open',
    expires_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_support_tickets_user (user_id),
    INDEX idx_support_tickets_assigned (assigned_user_id),
    INDEX idx_support_tickets_status (status),
    CONSTRAINT fk_support_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_support_tickets_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_user_id INT UNSIGNED NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_messages_ticket (ticket_id),
    CONSTRAINT fk_support_messages_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redeemable_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NULL,
    code_hash VARCHAR(255) NOT NULL UNIQUE,
    code_preview VARCHAR(40) NOT NULL,
    reward_type VARCHAR(80) NOT NULL,
    reward_json LONGTEXT NULL,
    max_uses INT UNSIGNED NOT NULL DEFAULT 1,
    current_uses INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_redeemable_codes_game (game_id),
    INDEX idx_redeemable_codes_status (status),
    CONSTRAINT fk_redeemable_codes_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_redeemable_codes_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS code_redemptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NULL,
    redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reward_snapshot_json LONGTEXT NULL,
    UNIQUE KEY uq_code_redemptions_code_user (code_id, user_id),
    INDEX idx_code_redemptions_user (user_id),
    CONSTRAINT fk_code_redemptions_code FOREIGN KEY (code_id) REFERENCES redeemable_codes(id) ON DELETE CASCADE,
    CONSTRAINT fk_code_redemptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_code_redemptions_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    client_id VARCHAR(190) NULL,
    client_secret_hash VARCHAR(255) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
    config_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_external_integrations_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(80) NOT NULL,
    external_user_id VARCHAR(190) NOT NULL,
    external_username VARCHAR(190) NULL,
    extra_json LONGTEXT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_external_accounts_provider_user (provider, external_user_id),
    INDEX idx_external_accounts_user (user_id),
    CONSTRAINT fk_external_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
