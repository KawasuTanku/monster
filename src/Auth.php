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

    // Session lifetime: an absolute cap and a sliding idle window. Either expiry
    // logs the operator out (they just sign back in — no destructive action).
    private const SESSION_ABSOLUTE = 28800; // 8h hard cap from login
    private const SESSION_IDLE = 1800;      // 30m of inactivity before logout
    private const TS_LOGIN = 'monster_login';
    private const TS_LAST = 'monster_last';

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
                'id' => strtolower($legacyUser),
                'username' => strtolower($legacyUser),
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
        $username = strtolower(trim($username));
        foreach ($this->users() as $u) {
            $stored = strtolower(($u['username'] ?? ''));
            if ($stored !== '' && hash_equals($stored, $username)) {
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

    /**
     * Admin-initiated password reset for another user. Sets a new password and
     * clears any active login lock so the reset takes effect immediately.
     * @throws \InvalidArgumentException if the user does not exist or password too short.
     */
    public function adminResetPassword(string $username, string $password): void
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }
        $this->setPassword($username, $password);
        // Clear any brute-force lock on this user (and their IP bucket) so the
        // freshly set password isn't blocked by a stale lockout.
        $t = $this->throttle();
        $changed = false;
        foreach (['byUser' => strtolower(trim($username)), 'byIp' => $this->clientIp()] as $bucket => $name) {
            if (isset($t[$bucket][$name])) {
                unset($t[$bucket][$name]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveThrottle($t);
        }
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

    // ---- Brute-force protection (login rate limiting) ----
    private const MAX_ATTEMPTS = 5;     // allowed failures per window
    private const WINDOW = 900;         // 15 minutes
    private const LOCK = 900;           // lockout duration: 15 minutes

    /**
     * Best-effort client IP for the brute-force lockout.
     *
     * This app runs behind FrankenPHP/Caddy on the same host, with no untrusted
     * proxy in front, so REMOTE_ADDR is the trustworthy value. We deliberately do
     * NOT honor X-Forwarded-For here: a real client can spoof that header, which
     * would let an attacker rotate XFF each attempt and bypass the per-IP lockout.
     * If a trusted reverse proxy is ever added, restrict XFF to that proxy's
     * subnet instead of trusting it blindly.
     */
    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private function throttle(): array
    {
        $t = $this->storage->getSetting('throttle');
        if (!is_array($t)) {
            $t = ['byUser' => [], 'byIp' => []];
        }
        return $t;
    }

    private function saveThrottle(array $t): void
    {
        $this->storage->setSetting('throttle', $t);
    }

    /**
     * Returns the Unix timestamp until which the given username (or its IP) is
     * locked out, or null if not locked. Only an entry that carries an *expired
     * lock* (until <= now) is pruned here — an in-progress failure counter is
     * left untouched so attempts accumulate correctly.
     */
    public function isLocked(string $username): ?int
    {
        $username = strtolower(trim($username));
        $ip = $this->clientIp();
        $t = $this->throttle();
        $now = time();
        $changed = false;
        $lockedUntil = null;

        foreach (['byUser' => $username, 'byIp' => $ip] as $bucket => $key) {
            if (!isset($t[$bucket][$key])) {
                continue;
            }
            $entry = $t[$bucket][$key];
            // A real lock exists only when 'until' is set and still in the future.
            if (($entry['until'] ?? 0) > $now) {
                $lockedUntil = max($lockedUntil ?? 0, $entry['until']);
            } elseif (($entry['until'] ?? 0) > 0) {
                // Lock has expired -> forget the whole entry.
                unset($t[$bucket][$key]);
                $changed = true;
            }
            // else: plain counter in progress -> leave it alone.
        }
        if ($changed) {
            $this->saveThrottle($t);
        }
        return $lockedUntil;
    }

    private function registerFailure(string $username): void
    {
        $username = strtolower(trim($username));
        $ip = $this->clientIp();
        $t = $this->throttle();
        $now = time();

        foreach (['byUser' => $username, 'byIp' => $ip] as $bucket => $name) {
            $entry = $t[$bucket][$name] ?? ['count' => 0, 'window' => 0, 'until' => 0];
            // If the sliding window expired (and we're not in a hard lock), reset.
            if (($entry['until'] ?? 0) <= $now && ($entry['window'] ?? 0) > 0 && $now - $entry['window'] > self::WINDOW) {
                $entry = ['count' => 0, 'window' => 0, 'until' => 0];
            }
            $entry['count'] = ($entry['count'] ?? 0) + 1;
            $entry['window'] = $entry['window'] ?? $now;
            if ($entry['count'] >= self::MAX_ATTEMPTS) {
                $entry['until'] = $now + self::LOCK;
            }
            $t[$bucket][$name] = $entry;
        }
        $this->saveThrottle($t);
    }

    private function registerSuccess(string $username): void
    {
        $username = strtolower(trim($username));
        $ip = $this->clientIp();
        $t = $this->throttle();
        $changed = false;
        foreach (['byUser' => $username, 'byIp' => $ip] as $bucket => $name) {
            if (isset($t[$bucket][$name])) {
                unset($t[$bucket][$name]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveThrottle($t);
        }
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
        $username = strtolower(trim($username));
        // Refuse outright if this username or its IP is currently locked out.
        if ($this->isLocked($username) !== null) {
            return false;
        }
        if (!$this->verify($username, $password)) {
            $this->registerFailure($username);
            return false;
        }
        $this->registerSuccess($username);
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $username;
        $_SESSION[self::TS_LOGIN] = time();
        $_SESSION[self::TS_LAST] = time();
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
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        $now = time();
        $login = (int) ($_SESSION[self::TS_LOGIN] ?? 0);
        $last = (int) ($_SESSION[self::TS_LAST] ?? 0);
        // Either the absolute cap (since login) or the idle window (since last
        // activity) has elapsed -> expire the session and force re-auth.
        if (($login > 0 && $now - $login > self::SESSION_ABSOLUTE)
            || ($last > 0 && $now - $last > self::SESSION_IDLE)) {
            unset($_SESSION[self::SESSION_KEY], $_SESSION[self::TS_LOGIN], $_SESSION[self::TS_LAST]);
            return false;
        }
        // Sliding idle window: bump "last activity" on each authenticated request.
        $_SESSION[self::TS_LAST] = $now;
        return true;
    }

    public function user(): ?string
    {
        $this->startSession();
        return $_SESSION[self::SESSION_KEY] ?? null;
    }
}
