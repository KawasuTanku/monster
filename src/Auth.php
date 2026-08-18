<?php

declare(strict_types=1);

namespace Monster;

/**
 * Session-based authentication for a small side business with multiple operators.
 *
 * Users live in a `users` collection in the storage file, each with a bcrypt-hashed
 * password (PASSWORD_BCRYPT, cost 13) and a role:
 *   - "admin"  : full access, can manage other users
 *   - "member" : can view and record transactions
 *
 * The very first account created (via /setup) becomes the admin. A legacy
 * single-owner account stored under settings (user / password_hash) is migrated
 * into the users collection automatically so an existing install keeps working.
 */
final class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    private const SESSION_KEY = 'monster_user';
    private const ALGO = PASSWORD_BCRYPT;
    private const COST = 13;
    private const USERS_KEY = 'users';

    public function __construct(private Storage $storage) {}

    /**
     * Ensure backwards compatibility: if a legacy single-owner account exists
     * under settings but no users collection, migrate it as the admin.
     */
    private function migrateLegacy(): void
    {
        $users = $this->storage->getList(self::USERS_KEY);
        if ($users !== []) {
            return;
        }
        $legacyUser = $this->storage->getSetting('user');
        $legacyHash = $this->storage->getSetting('password_hash');
        if (is_string($legacyUser) && $legacyUser !== '' && is_string($legacyHash)) {
            $this->storage->put(self::USERS_KEY, [
                'id' => $legacyUser,
                'username' => $legacyUser,
                'password_hash' => $legacyHash,
                'role' => self::ROLE_ADMIN,
                'createdAt' => time(),
            ]);
            // Drop the now-redundant legacy keys.
            $this->storage->setSetting('user', null);
            $this->storage->setSetting('password_hash', null);
        }
    }

    /** @return list<array<string, mixed>> */
    public function users(): array
    {
        $this->migrateLegacy();
        return $this->storage->getList(self::USERS_KEY);
    }

    /** @return array<string, mixed>|null */
    public function findUser(string $username): ?array
    {
        $this->migrateLegacy();
        foreach ($this->users() as $u) {
            if (($u['username'] ?? null) !== null && hash_equals($u['username'], $username)) {
                return $u;
            }
        }
        return null;
    }

    /** Any user exists yet? */
    public function isConfigured(): bool
    {
        return $this->users() !== [];
    }

    /** Create a user. First user is always admin. */
    public function createUser(string $username, string $password, string $role = self::ROLE_MEMBER): void
    {
        $username = strtolower(trim($username));
        if ($username === '' || strlen($password) < 8) {
            throw new \InvalidArgumentException('Username required; password must be at least 8 characters.');
        }
        if ($this->findUser($username) !== null) {
            throw new \InvalidArgumentException('That username already exists.');
        }
        $isFirst = $this->users() === [];
        $this->storage->put(self::USERS_KEY, [
            'id' => $username,
            'username' => $username,
            'password_hash' => password_hash($password, self::ALGO, ['cost' => self::COST]),
            'role' => $isFirst ? self::ROLE_ADMIN : $role,
            'createdAt' => time(),
        ]);
    }

    /** Update a user's password (by username). */
    public function setPassword(string $username, string $password): void
    {
        $u = $this->findUser($username);
        if ($u === null) {
            throw new \InvalidArgumentException('No such user.');
        }
        $u['id'] = $u['username'] ?? $username;
        $u['password_hash'] = password_hash($password, self::ALGO, ['cost' => self::COST]);
        $this->storage->put(self::USERS_KEY, $u);
    }

    /** Change a user's role (admins only). Cannot demote the last remaining admin. */
    public function setRole(string $username, string $role): void
    {
        $u = $this->findUser($username);
        if ($u === null) {
            throw new \InvalidArgumentException('No such user.');
        }
        if (($u['role'] ?? '') === self::ROLE_ADMIN && $role !== self::ROLE_ADMIN) {
            $admins = array_filter($this->users(), static fn($x) => ($x['role'] ?? '') === self::ROLE_ADMIN);
            if (count($admins) <= 1) {
                throw new \InvalidArgumentException('Cannot remove the last admin.');
            }
        }
        $u['id'] = $u['username'] ?? $username;
        $u['role'] = $role;
        $this->storage->put(self::USERS_KEY, $u);
    }

    public function deleteUser(string $username): void
    {
        $u = $this->findUser($username);
        if ($u === null) {
            return;
        }
        if (($u['role'] ?? '') === self::ROLE_ADMIN) {
            $admins = array_filter($this->users(), static fn($x) => ($x['role'] ?? '') === self::ROLE_ADMIN);
            if (count($admins) <= 1) {
                throw new \InvalidArgumentException('Cannot delete the last admin.');
            }
        }
        $this->storage->delete(self::USERS_KEY, $username);
    }

    public function verify(string $username, string $password): bool
    {
        $u = $this->findUser($username);
        if ($u === null) {
            return false;
        }
        return password_verify($password, $u['password_hash'] ?? '');
    }

    public function isAdmin(string $username): bool
    {
        $u = $this->findUser($username);
        return $u !== null && ($u['role'] ?? '') === self::ROLE_ADMIN;
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Hardened cookie defaults for a login-protected app.
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public function login(string $username, string $password): bool
    {
        if (!$this->verify($username, $password)) {
            return false;
        }
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = strtolower(trim($username));
        return true;
    }

    public function logout(): void
    {
        $this->startSession();
        unset($_SESSION[self::SESSION_KEY]);
        session_destroy();
    }

    public function check(): bool
    {
        $this->startSession();
        return isset($_SESSION[self::SESSION_KEY]);
    }

    public function user(): ?string
    {
        $this->startSession();
        return $_SESSION[self::SESSION_KEY] ?? null;
    }
}
