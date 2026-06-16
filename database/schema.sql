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

CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector VARCHAR(32) NOT NULL UNIQUE,
    token_hash VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_auth_remember_tokens_user (user_id),
    INDEX idx_auth_remember_tokens_expires (expires_at),
    CONSTRAINT fk_auth_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_verification_tokens_user (user_id),
    INDEX idx_email_verification_tokens_expires (expires_at),
    CONSTRAINT fk_email_verification_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_eula_acceptances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    version VARCHAR(40) NOT NULL,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    UNIQUE KEY uq_user_eula_acceptances_user_version (user_id, version),
    INDEX idx_user_eula_acceptances_user (user_id),
    CONSTRAINT fk_user_eula_acceptances_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS user_game_licenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NOT NULL,
    source VARCHAR(80) NOT NULL DEFAULT 'manual',
    license_key_hash VARCHAR(128) NOT NULL UNIQUE,
    license_key_preview VARCHAR(32) NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_game_licenses_scope (user_id, game_id),
    INDEX idx_user_game_licenses_user (user_id),
    INDEX idx_user_game_licenses_game (game_id),
    INDEX idx_user_game_licenses_status (status),
    CONSTRAINT fk_user_game_licenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_game_licenses_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_builds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    version VARCHAR(60) NOT NULL,
    channel ENUM('development', 'playtest', 'beta', 'stable', 'archived') NOT NULL DEFAULT 'development',
    file_path VARCHAR(255) NULL,
    checksum VARCHAR(128) NULL,
    size_bytes BIGINT UNSIGNED NULL,
    executable_path VARCHAR(255) NULL,
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

CREATE TABLE IF NOT EXISTS game_oauth_device_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    public_key VARCHAR(120) NOT NULL,
    device_code_hash VARCHAR(128) NOT NULL UNIQUE,
    user_code_hash VARCHAR(128) NOT NULL UNIQUE,
    user_code_preview VARCHAR(20) NOT NULL,
    status ENUM('pending', 'authorized', 'denied', 'expired') NOT NULL DEFAULT 'pending',
    approved_user_id INT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    last_polled_at DATETIME NULL,
    authorized_at DATETIME NULL,
    token_issued_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_game_oauth_device_codes_game (game_id),
    INDEX idx_game_oauth_device_codes_status (status),
    INDEX idx_game_oauth_device_codes_expires (expires_at),
    CONSTRAINT fk_game_oauth_device_codes_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_game_oauth_device_codes_user FOREIGN KEY (approved_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_oauth_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    device_code_id BIGINT UNSIGNED NULL,
    access_token_hash VARCHAR(128) NOT NULL UNIQUE,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    INDEX idx_game_oauth_tokens_game (game_id),
    INDEX idx_game_oauth_tokens_user (user_id),
    INDEX idx_game_oauth_tokens_status (status),
    CONSTRAINT fk_game_oauth_tokens_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_game_oauth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_game_oauth_tokens_device FOREIGN KEY (device_code_id) REFERENCES game_oauth_device_codes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_achievements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    image_path VARCHAR(255) NULL,
    locked_image_path VARCHAR(255) NULL,
    points INT UNSIGNED NOT NULL DEFAULT 0,
    goal_value DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'hidden', 'disabled') NOT NULL DEFAULT 'active',
    reward_json LONGTEXT NULL,
    config_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_game_achievements_game_code (game_id, code),
    INDEX idx_game_achievements_game (game_id),
    INDEX idx_game_achievements_status (status),
    CONSTRAINT fk_game_achievements_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_achievements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    achievement_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    progress_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    progress_json LONGTEXT NULL,
    unlocked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_achievements_user_achievement (user_id, achievement_id),
    INDEX idx_user_achievements_game_user (game_id, user_id),
    INDEX idx_user_achievements_unlocked (unlocked_at),
    CONSTRAINT fk_user_achievements_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES game_achievements(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_achievements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    data_key VARCHAR(120) NOT NULL,
    data_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_game_player_data_scope (game_id, user_id, data_key),
    INDEX idx_game_player_data_user (user_id),
    CONSTRAINT fk_game_player_data_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_game_player_data_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_cloud_save_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    config_key VARCHAR(100) NOT NULL,
    name VARCHAR(160) NOT NULL,
    max_slots INT UNSIGNED NOT NULL DEFAULT 3,
    max_bytes INT UNSIGNED NOT NULL DEFAULT 65536,
    auto_sync TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_game_cloud_save_configs_game_key (game_id, config_key),
    INDEX idx_game_cloud_save_configs_game (game_id),
    CONSTRAINT fk_game_cloud_save_configs_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_cloud_saves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    config_id BIGINT UNSIGNED NOT NULL,
    slot INT UNSIGNED NOT NULL DEFAULT 1,
    save_json LONGTEXT NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_cloud_saves_scope (game_id, user_id, config_id, slot),
    INDEX idx_user_cloud_saves_user (user_id),
    CONSTRAINT fk_user_cloud_saves_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_cloud_saves_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_cloud_saves_config FOREIGN KEY (config_id) REFERENCES game_cloud_save_configs(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS user_friends (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_user_id INT UNSIGNED NOT NULL,
    addressee_user_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted', 'rejected', 'blocked') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_friends_pair (requester_user_id, addressee_user_id),
    INDEX idx_user_friends_requester (requester_user_id, status),
    INDEX idx_user_friends_addressee (addressee_user_id, status),
    CONSTRAINT fk_user_friends_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_friends_addressee FOREIGN KEY (addressee_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_social_settings (
    user_id INT UNSIGNED PRIMARY KEY,
    friend_request_policy ENUM('anyone', 'mutual_friends', 'none') NOT NULL DEFAULT 'anyone',
    message_policy ENUM('anyone', 'friends', 'mutual_friends', 'none') NOT NULL DEFAULT 'anyone',
    private_show_bio TINYINT(1) NOT NULL DEFAULT 1,
    private_show_games TINYINT(1) NOT NULL DEFAULT 1,
    private_show_achievements TINYINT(1) NOT NULL DEFAULT 1,
    private_show_friends TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_social_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_relationship_controls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    target_user_id INT UNSIGNED NOT NULL,
    control ENUM('blocked', 'muted') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_relationship_controls_scope (user_id, target_user_id, control),
    INDEX idx_user_relationship_controls_user (user_id, control),
    INDEX idx_user_relationship_controls_target (target_user_id, control),
    CONSTRAINT fk_user_relationship_controls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_relationship_controls_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('active', 'hidden', 'deleted') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_community_posts_user (user_id),
    INDEX idx_community_posts_status_created (status, created_at),
    CONSTRAINT fk_community_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status ENUM('active', 'hidden', 'deleted') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_community_comments_post (post_id, status, created_at),
    INDEX idx_community_comments_user (user_id),
    CONSTRAINT fk_community_comments_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_community_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_message_threads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_a_id INT UNSIGNED NOT NULL,
    user_b_id INT UNSIGNED NOT NULL,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_direct_message_threads_pair (user_a_id, user_b_id),
    INDEX idx_direct_message_threads_a (user_a_id),
    INDEX idx_direct_message_threads_b (user_b_id),
    CONSTRAINT fk_direct_message_threads_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_direct_message_threads_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_user_id INT UNSIGNED NOT NULL,
    recipient_user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_direct_messages_thread (thread_id, created_at),
    INDEX idx_direct_messages_recipient_read (recipient_user_id, read_at),
    CONSTRAINT fk_direct_messages_thread FOREIGN KEY (thread_id) REFERENCES direct_message_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_direct_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_direct_messages_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS game_inventory_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NULL,
    item_key VARCHAR(120) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    item_type VARCHAR(80) NOT NULL DEFAULT 'item',
    image_path VARCHAR(255) NULL,
    metadata_json LONGTEXT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_game_inventory_items_scope (game_id, item_key),
    INDEX idx_game_inventory_items_game (game_id),
    INDEX idx_game_inventory_items_status (status),
    CONSTRAINT fk_game_inventory_items_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NULL,
    item_id BIGINT UNSIGNED NULL,
    item_key VARCHAR(120) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    source VARCHAR(80) NULL,
    metadata_json LONGTEXT NULL,
    acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_inventory_scope (user_id, game_id, item_key),
    INDEX idx_user_inventory_user (user_id),
    INDEX idx_user_inventory_game (game_id),
    CONSTRAINT fk_user_inventory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_inventory_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_inventory_item FOREIGN KEY (item_id) REFERENCES game_inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_publish_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NULL,
    name VARCHAR(140) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    description TEXT NULL,
    website_url VARCHAR(255) NULL,
    contact_email VARCHAR(190) NULL,
    build_url VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewer_user_id INT UNSIGNED NULL,
    review_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX idx_game_publish_requests_user (user_id),
    INDEX idx_game_publish_requests_status (status),
    CONSTRAINT fk_game_publish_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_game_publish_requests_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL,
    CONSTRAINT fk_game_publish_requests_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_workshop_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL UNIQUE,
    status ENUM('enabled', 'disabled') NOT NULL DEFAULT 'disabled',
    allow_user_uploads TINYINT(1) NOT NULL DEFAULT 0,
    moderation_mode ENUM('pre', 'post') NOT NULL DEFAULT 'pre',
    max_file_bytes INT UNSIGNED NOT NULL DEFAULT 10485760,
    allowed_types_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_game_workshop_configs_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workshop_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    description TEXT NULL,
    file_url VARCHAR(255) NULL,
    image_url VARCHAR(255) NULL,
    metadata_json LONGTEXT NULL,
    status ENUM('pending', 'published', 'rejected', 'hidden') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workshop_items_game_slug (game_id, slug),
    INDEX idx_workshop_items_game_status (game_id, status),
    INDEX idx_workshop_items_user (user_id),
    CONSTRAINT fk_workshop_items_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_workshop_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(128) NOT NULL UNIQUE,
    client_name VARCHAR(120) NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    INDEX idx_client_sessions_user (user_id),
    INDEX idx_client_sessions_status (status),
    CONSTRAINT fk_client_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
