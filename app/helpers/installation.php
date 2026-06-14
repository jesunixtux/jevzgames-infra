<?php
declare(strict_types=1);

function installed_lock_path(): string
{
    return CONFIG_PATH . DIRECTORY_SEPARATOR . 'installed.lock';
}

function private_config_path(): string
{
    return CONFIG_PATH . DIRECTORY_SEPARATOR . 'config.php';
}

function installer_is_locked(): bool
{
    return is_file(installed_lock_path());
}

function is_installed(): bool
{
    return installer_is_locked() && is_file(private_config_path());
}

function require_installed(): void
{
    if (!is_installed()) {
        redirect_to('/install/');
    }
}
