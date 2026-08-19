<?php
declare(strict_types=1);

namespace Monster;

/**
 * Minimal, dependency-free PDF writer (PDF 1.4).
 *
 * Intentionally tiny: enough to lay out the P&L report — a title, headings,
 * key/value stat rows, simple tables, and a basic bar chart — without pulling
 * in a third-party library. Output is a single-page document with one content
 * stream. Text is encoded as WinAnsiEncoding (CP1252), which covers the Latin
 * characters and the $/–/* glyphs this app emits. Anything outside that range
 * is transliterated to ASCII so we never emit an invalid byte.
 */
final class Pdf
{
    private const PAGE_W = 612.0;   // US Letter, points
    private const PAGE_H = 792.0;
    private const MARGIN = 48.0;

    /** @var list<string> */
    private array $ops = [];
    private float $y;

    public function __construct()
    {
        $this->y = self::PAGE_H - self::MARGIN;
    }

    public static function pageWidth(): float
    {
        return self::PAGE_W;
    }

    public static function margin(): float
    {
        return self::MARGIN;
    }

    /** Convert a UTF-8 string to WinAnsi (CP1252) byte sequence. */
    private static function enc(string $s): string
    {
        // Common Unicode → CP1252 fixes, then transliterate the rest.
        $s = preg_replace_callback('/[^\x00-\x7F]/u', static function (array $m): string {
            $c = mb_ord($m[0], 'UTF-8');
            static $map = [
                0x2013 => "\xE2", 0x2014 => "\xE4", 0x2018 => "\x91", 0x2019 => "\x92",
                0x201C => "\x93", 0x201D => "\x94", 0x2022 => "\x95", 0x2026 => "\x85",
                0x20AC => "\x80", 0x00A0 => "\xA0",
            ];
            if (isset($map[$c])) {
                return $map[$c];
            }
            // Fall back: keep ASCII-ish letters, drop the rest.
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $m[0]);
            return ($ascii === false || $ascii === '') ? '' : $ascii;
        }, $s) ?? $s;
        return $s;
    }

    /** Escape a string for inclusion inside a PDF literal string (parentheses). */
    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /** Emit a line of text at the current cursor (font size in points). */
    public function text(string $s, float $x, ?float $size = null, bool $bold = false): void
    {
        $size ??= 11.0;
        $font = $bold ? 'F2' : 'F1';
        $this->ops[] = sprintf(
            "BT /%s %.1f Tf 1 0 0 1 %.1f %.1f Tm (%s) Tj ET",
            $font, $size, $x, $this->y, self::escape(self::enc($s))
        );
    }

    /** Move the cursor down by $dy points (positive = downward). */
    public function down(float $dy): void
    {
        $this->y -= $dy;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function setY(float $y): void
    {
        $this->y = $y;
    }

    /**
     * Draw a single table row from cells. Each cell is [$text, $width, $align]
     * where $align is 'L'|'R'|'C'. $rowH is the line height in points.
     *
     * @param list<array{0: string, 1: float, 2: string}> $cells
     */
    public function row(array $cells, float $rowH = 16.0, bool $header = false): void
    {
        $x = self::MARGIN;
        $size = $header ? 10.0 : 9.5;
        if ($header) {
            // Header background band.
            $w = 0.0;
            foreach ($cells as $c) {
                $w += $c[1];
            }
            $this->rect(self::MARGIN, $this->y - $rowH + 3.0, $w, $rowH, 0.92, 0.95, 0.98);
        }
        foreach ($cells as [$text, $w, $align]) {
            $pad = 4.0;
            if ($align === 'R') {
                $tx = $x + $w - $pad - $this->strWidth($text, $size);
            } elseif ($align === 'C') {
                $tx = $x + ($w - $this->strWidth($text, $size)) / 2;
            } else {
                $tx = $x + $pad;
            }
            $this->text($text, $tx, $size, $header);
            $x += $w;
        }
        $this->down($rowH);
    }

    /** Filled rectangle (for bars and header bands). */
    public function rect(float $x, float $y, float $w, float $h, float $r, float $g, float $b): void
    {
        $this->ops[] = sprintf(
            "%.3f %.3f %.3f rg %.1f %.1f %.1f %.1f re f",
            $r, $g, $b, $x, $y, $w, $h
        );
    }

    /**
     * Draw a horizontal bar chart of monthly cumulative net profit.
     *
     * @param list<array{label: string, cumNet: float}> $series
     */
    public function barChart(array $series, float $width): void
    {
        if ($series === []) {
            return;
        }
        $max = 0.0;
        foreach ($series as $s) {
            $max = max($max, abs($s['cumNet']));
        }
        $max = max($max, 1.0);
        $barH = 12.0;
        $gap = 6.0;
        $labelW = 70.0;
        $plotW = $width - $labelW;
        $zero = self::MARGIN + $labelW + ($plotW / 2); // center = zero line
        foreach ($series as $s) {
            $label = $s['label'];
            $this->text($label, self::MARGIN, 9.0);
            $val = $s['cumNet'];
            $len = ($plotW / 2) * (abs($val) / $max);
            if ($val >= 0) {
                $this->rect($zero, $this->y - $barH + 2, $len, $barH, 0.20, 0.55, 0.30);
            } else {
                $this->rect($zero - $len, $this->y - $barH + 2, $len, $barH, 0.70, 0.25, 0.25);
            }
            $this->text('$' . self::num($val), $zero + ($val >= 0 ? $len + 4 : -$len - 4 - $this->strWidth('$' . self::num($val), 9.0)), 9.0, true);
            $this->down($barH + $gap);
        }
    }

    /** Format a number like money() but without the leading $ (sign kept). */
    private static function num(float $v): string
    {
        return number_format($v, 2, '.', ',');
    }

    /** Rough string width estimate in points (Helvetica is ~0.5em avg). */
    private function strWidth(string $s, float $size): float
    {
        return mb_strlen(self::enc($s), '8bit') * $size * 0.5;
    }

    /** Render to a PDF document string. */
    public function output(): string
    {
        $content = implode("\n", $this->ops);
        $objects = [];

        // 1: Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // 2: Pages
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        // 3: Page
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_W . " " . self::PAGE_H . "] "
            . "/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
        // 4: Helvetica
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        // 5: Helvetica-Bold
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        // 6: Content stream
        $stream = "BT /F1 11 Tf ET\n" . $content;
        $objects[6] = $this->stream($stream);

        return $this->assemble($objects);
    }

    private function stream(string $data): string
    {
        $data = self::enc($data);
        return "<< /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    private function assemble(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $n = count($objects);
        for ($i = 1; $i <= $n; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($n + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }
}
