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

/**
 * Human-readable label for an inventory item, e.g. "Original 12-pack (ED-ORG)".
 * @param \Monster\InventoryItem|null $item
 */
function itemLabel(?\Monster\InventoryItem $item, string $fallback = ''): string
{
    if ($item === null) {
        return $fallback;
    }
    $name = trim($item->name . ' ' . $item->variant);
    $label = trim($name) !== '' ? trim($name) : $item->sku;
    if ($item->sku !== '') {
        $label .= ' (' . $item->sku . ')';
    }
    return $label;
}

/** Inline key glyph for password-reset affordances. Uses the Unicode key
 *  emoji forced to solid black via CSS (filter:brightness(0)) so it renders
 *  reliably on the green reset chip without depending on SVG support.
 *  @return string */
function keyIcon(): string
{
    return '<span class="icon-key" aria-hidden="true" focusable="false">🔑</span>';
}

/**
 * Emit hardened security headers for a login-protected app. Idempotent: a no-op
 * once headers have already been sent. Tuned to be safe with the app's own inline
 * scripts/styles (script-src/style-src allow 'unsafe-inline' so we don't have to
 * refactor every view) while blocking cross-origin script/style loads — the
 * realistic XSS vector here — and clickjacking/framing.
 */
function securityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('Strict-Transport-Security: max-age=31536000');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; object-src 'none'; base-uri 'self'");
}

/**
 * Inline trash-can icon for delete affordances. Solid black fill so it renders
 * reliably on the dark theme. @return string */
function trashIcon(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" '
        . 'class="icon-trash" aria-hidden="true" focusable="false" fill="#000">'
        . '<path d="M6 7h12l-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7zm3-3h6l1 2H8l1-2z'
        . 'M4 6h16v1H4V6zM9 10h1v8H9v-8zm5 0h1v8h-1v-8z"/></svg>';
}

/**
 * Build a dependency-free inline SVG line chart of cumulative net profit per
 * period. No external JS/CDN — renders entirely in markup so it works offline
 * on FrankenPHP and survives backup/restore unchanged.
 *
 * @param list<array{label: string, cumNet: float}> $points
 * @return string HTML <svg> fragment (empty string when there is nothing to plot)
 */
function roiChartSvg(array $points): string
{
    if (count($points) < 1) {
        return '';
    }

    $w = 720;
    $h = 280;
    $padL = 56;
    $padR = 16;
    $padT = 16;
    $padB = 36;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $vals = array_column($points, 'cumNet');
    $min = min($vals);
    $max = max($vals);
    // Always include 0 so the zero baseline is visible.
    $min = min($min, 0.0);
    $max = max($max, 0.0);
    if ($max === $min) {
        $max = $min + 1; // avoid divide-by-zero when flat
    }

    $n = count($points);
    $xAt = static function (int $i) use ($n, $padL, $plotW): float {
        return $n === 1 ? $padL + $plotW / 2 : $padL + ($plotW * $i) / ($n - 1);
    };
    $yAt = static function (float $v) use ($min, $max, $padT, $plotH): float {
        return $padT + $plotH * (1 - ($v - $min) / ($max - $min));
    };

    $zeroY = $yAt(0.0);

    // Gridlines: 0 (baseline) plus up to 3 value lines.
    $grid = '';
    $steps = [0.0, 0.5, 1.0];
    foreach ($steps as $s) {
        $v = $min + ($max - $min) * $s;
        $y = $yAt($v);
        $cls = abs($v) < 1e-6 ? 'axis' : 'grid';
        $grid .= sprintf('<line x1="%g" y1="%g" x2="%g" y2="%g" class="%s"/>', $padL, $y, $w - $padR, $y, $cls);
        $grid .= sprintf('<text x="%g" y="%g" class="ylabel">$%s</text>', 4, $y + 4, number_format($v, 0));
    }

    // Build the polyline path + dots + x labels.
    $coords = [];
    $dots = '';
    $xlabels = '';
    foreach ($points as $i => $p) {
        $x = $xAt($i);
        $y = $yAt($p['cumNet']);
        $coords[] = sprintf('%g,%g', $x, $y);
        $fill = $p['cumNet'] >= 0 ? 'pos' : 'neg';
        $dots .= sprintf('<circle cx="%g" cy="%g" r="3.5" class="dot %s"/>', $x, $y, $fill);
        $xlabels .= sprintf('<text x="%g" y="%g" class="xlabel">%s</text>', $x, $h - 12, e($p['label']));
    }
    $poly = implode(' ', $coords);

    return <<<SVG
<svg viewBox="0 0 $w $h" class="roi-chart" role="img" aria-label="Cumulative net profit over time">
    $grid
    <polyline points="$poly" class="roi-line"/>
    $dots
    $xlabels
</svg>
SVG;
}
