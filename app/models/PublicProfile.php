<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class PublicProfile
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS public_profiles (
                user_id INT UNSIGNED PRIMARY KEY,
                display_name VARCHAR(120) NULL,
                bio TEXT NULL,
                avatar_path VARCHAR(255) NULL,
                visibility ENUM("public", "private") NOT NULL DEFAULT "public",
                extra_json LONGTEXT NULL,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_public_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function findByUsername(string $username): ?array
    {
        self::ensureTables();
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.username, u.display_name AS account_display_name, u.status, u.created_at, u.last_login_at,
                    p.display_name, p.bio, p.avatar_path, p.visibility, p.extra_json, p.updated_at AS profile_updated_at
             FROM users u
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE u.username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $profile = $stmt->fetch();

        if (!is_array($profile)) {
            return null;
        }

        return self::hydrateDefaults($profile);
    }

    public static function findByUserId(int $userId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.username, u.display_name AS account_display_name, u.status, u.created_at, u.last_login_at,
                    p.display_name, p.bio, p.avatar_path, p.visibility, p.extra_json, p.updated_at AS profile_updated_at
             FROM users u
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $profile = $stmt->fetch();

        if (!is_array($profile)) {
            return null;
        }

        return self::hydrateDefaults($profile);
    }

    public static function save(int $userId, array $input): void
    {
        self::ensureTables();
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $bio = trim((string) ($input['bio'] ?? ''));
        $visibility = (string) ($input['visibility'] ?? 'public');

        if ($displayName !== '' && strlen($displayName) > 120) {
            throw new RuntimeException('El nombre publico no puede superar 120 caracteres.');
        }

        if ($bio !== '' && strlen($bio) > 1000) {
            throw new RuntimeException('La bio no puede superar 1000 caracteres.');
        }

        if (!in_array($visibility, ['public', 'private'], true)) {
            throw new RuntimeException('Visibilidad de perfil invalida.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO public_profiles (user_id, display_name, bio, visibility, updated_at)
             VALUES (:user_id, :display_name, :bio, :visibility, NOW())
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                bio = VALUES(bio),
                visibility = VALUES(visibility),
                updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'display_name' => $displayName !== '' ? $displayName : null,
            'bio' => $bio !== '' ? $bio : null,
            'visibility' => $visibility,
        ]);

        if ($displayName !== '') {
            $stmt = Database::pdo()->prepare('UPDATE users SET display_name = :display_name, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'display_name' => $displayName,
                'id' => $userId,
            ]);
        }
    }

    public static function saveAvatar(int $userId, array $file): string
    {
        self::ensureTables();
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir la imagen.');
        }

        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('La imagen debe pesar hasta 2 MB.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Archivo subido invalido.');
        }

        $mime = self::detectMime($tmp);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Solo se permiten imagenes JPG, PNG o WEBP.');
        }

        $directory = PUBLIC_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }

        $path = '/uploads/avatars/' . $filename;
        $stmt = Database::pdo()->prepare(
            'INSERT INTO public_profiles (user_id, avatar_path, updated_at)
             VALUES (:user_id, :avatar_path, NOW())
             ON DUPLICATE KEY UPDATE avatar_path = VALUES(avatar_path), updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'avatar_path' => $path,
        ]);

        return $path;
    }

    public static function avatarUrl(?string $avatarPath): string
    {
        if ($avatarPath === null || trim($avatarPath) === '') {
            return '';
        }

        return \url($avatarPath);
    }

    private static function hydrateDefaults(array $profile): array
    {
        $profile['display_name'] = $profile['display_name'] ?: ($profile['account_display_name'] ?: $profile['username']);
        $profile['bio'] = $profile['bio'] ?? '';
        $profile['avatar_path'] = $profile['avatar_path'] ?? '';
        $profile['visibility'] = $profile['visibility'] ?: 'public';

        return $profile;
    }

    private static function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : '';
    }
}
