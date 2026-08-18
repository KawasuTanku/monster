<?php

declare(strict_types=1);

namespace Monster;

use function htmlspecialchars;

/** Context-aware HTML escaping for output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Format a float as currency with 2 decimals, no symbol (UI adds it). */
function money(float $value): string
{
    return number_format($value, 2, '.', ',');
}

/** Color class for a signed value (positive => good, negative => bad). */
function moneyClass(float $value): string
{
    if ($value > 0) return 'pos';
    if ($value < 0) return 'neg';
    return 'zero';
}

/** Get or create a CSRF token for the session. */
function csrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

/** Verify a submitted CSRF token. */
function csrfValid(?string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return is_string($token) && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

/** Render a flash message stored in the session, then clear it. */
function takeFlash(): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($msg) ? $msg : null;
}

function setFlash(string $msg): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = $msg;
}
