<?php

declare(strict_types=1);

namespace Monster;

/**
 * Session-based authentication for a single-owner side business.
 *
 * Passwords are stored hashed with PASSWORD_BCRYPT (13 rounds). The credentials
 * live in the storage file under settings, so there is no separate user table — a
 * small business has exactly one operator. Swapping to multi-user later is localized.
 */
final class Auth
{
    private const SESSION_KEY = 'monster_user';
    private const ALGO = PASSWORD_BCRYPT;
    private const COST = 13;

    public function __construct(private Storage $storage) {}

    /** @return array{user: string, hash: string}|null */
    private function credentials(): ?array
    {
        $user = $this->storage->getSetting('user');
        $hash = $this->storage->getSetting('password_hash');
        if (is_string($user) && is_string($hash) && $user !== '') {
            return ['user' => $user, 'hash' => $hash];
        }
        return null;
    }

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /** Set/change the owner account. */
    public function setCredentials(string $user, string $password): void
    {
        $this->storage->setSetting('user', $user);
        $this->storage->setSetting('password_hash', password_hash($password, self::ALGO, ['cost' => self::COST]));
    }

    public function verify(string $user, string $password): bool
    {
        $cred = $this->credentials();
        if ($cred === null) {
            return false;
        }
        if (!hash_equals($cred['user'], $user)) {
            return false;
        }
        return password_verify($password, $cred['hash']);
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

    public function login(string $user, string $password): bool
    {
        if (!$this->verify($user, $password)) {
            return false;
        }
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $user;
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
