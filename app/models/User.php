<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class User
{
    public static function findByEmailOrUsername(string $identity): ?array
    {
        $pdo = Database::pdo();
        $identity = trim($identity);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email_identity OR username = :username_identity LIMIT 1');
        $stmt->execute([
            'email_identity' => $identity,
            'username_identity' => $identity,
        ]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    public static function findByIdWithRoles(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if (!is_array($user)) {
            return null;
        }

        $user['roles'] = self::rolesForUser($id);
        return $user;
    }

    public static function findByEmail(string $email): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => trim($email)]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    public static function rolesForUser(int $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT r.slug
             FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :id
             ORDER BY r.slug'
        );
        $stmt->execute(['id' => $id]);

        return array_map(static fn (array $row): string => (string) $row['slug'], $stmt->fetchAll());
    }

    public static function create(string $username, string $email, string $password, string $role = 'user'): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $emailVerifiedAt = PlatformSettings::emailVerificationEnabled() ? null : date('Y-m-d H:i:s');

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, status, display_name, email_verified_at, created_at, updated_at)
                 VALUES (:username, :email, :password_hash, "active", :display_name, :email_verified_at, NOW(), NOW())'
            );
            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => $username,
                'email_verified_at' => $emailVerifiedAt,
            ]);

            $userId = (int) $pdo->lastInsertId();
            $roleId = self::roleId($role);
            if ($roleId === null) {
                throw new RuntimeException('El rol indicado no existe.');
            }

            $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, created_at) VALUES (:user_id, :role_id, NOW())');
            $stmt->execute(['user_id' => $userId, 'role_id' => $roleId]);

            $pdo->commit();
            return $userId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function roleId(string $slug): ?int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public static function touchLastLogin(int $id): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function markEmailVerified(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()), updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function updatePassword(int $id, string $password): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        foreach ([
            'DELETE FROM auth_remember_tokens WHERE user_id = :user_id',
            'UPDATE sessions SET revoked_at = NOW() WHERE user_id = :user_id AND revoked_at IS NULL',
            'UPDATE client_sessions SET status = "revoked", revoked_at = NOW() WHERE user_id = :user_id AND status = "active"',
        ] as $sql) {
            try {
                $pdo->prepare($sql)->execute(['user_id' => $id]);
            } catch (\Throwable) {
            }
        }
    }

    public static function isEmailVerified(array $user): bool
    {
        return !empty($user['email_verified_at']);
    }
}
