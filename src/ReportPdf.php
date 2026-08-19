<?php
declare(strict_types=1);

namespace Monster;

/**
 * Render the P&L report as a dependency-free PDF.
 *
 * Mirrors the on-screen report.php layout: summary stat cards, a monthly
 * cumulative-net bar chart, a budget-vs-actual table, and the filtered
 * transaction list. Built on the tiny Pdf writer so no third-party library is
 * required.
 */
final class ReportPdf
{
    /**
     * @param array{revenue: float, expenses: float, net: float, by_category: array<string,float>} $summary
     * @param list<array{period: string, label: string, revenue: float, cost: float, net: float, roiPct: float, cumNet: float, count: int}> $roiSeries
     * @param array{revenue: float, cost: float, net: float, roiPct: float} $roiOverall
     * @param list<\Monster\Transaction> $txns
     * @param array<string,float> $budgets
     * @param array<string,float> $actualByCategory
     * @param array{type?: string, category?: string, from?: string, to?: string} $filters
     */
    public static function build(
        array $summary,
        array $roiSeries,
        array $roiOverall,
        array $txns,
        array $budgets,
        array $actualByCategory,
        array $filters,
        string $owner
    ): string {
        $pdf = new Pdf();
        $m = Pdf::margin();
        $w = Pdf::pageWidth() - 2 * $m;

        // ---- Title + meta (each line advances by its font size; no overlap) ----
        $pdf->line('Monster P&L Report', $m, 18.0, true, 8.0);
        $sub = 'Owner: ' . $owner;
        $filterBits = [];
        if (($filters['type'] ?? 'all') !== 'all') {
            $filterBits[] = 'type=' . $filters['type'];
        }
        if (!empty($filters['category'])) {
            $filterBits[] = 'category=' . $filters['category'];
        }
        if (!empty($filters['from'])) {
            $filterBits[] = 'from=' . $filters['from'];
        }
        if (!empty($filters['to'])) {
            $filterBits[] = 'to=' . $filters['to'];
        }
        if ($filterBits !== []) {
            $sub .= '  |  filters: ' . implode(', ', $filterBits);
        }
        $pdf->line($sub, $m, 9.0, false, 2.0);
        $pdf->line('Generated: ' . date('Y-m-d H:i'), $m, 9.0, false, 12.0);

        // ---- Summary stat cards (label above value, 4 columns) ----
        $pdf->line('Summary', $m, 13.0, true, 6.0);
        $statCols = [
            ['Revenue', '$' . number_format($summary['revenue'], 2)],
            ['Expenses', '$' . number_format($summary['expenses'], 2)],
            ['Net Profit', '$' . number_format($summary['net'], 2)],
            ['ROI', number_format($roiOverall['roiPct'], 2) . '%'],
        ];
        $colW = $w / 4;
        $cardTop = $pdf->getY();
        $ci = 0;
        foreach ($statCols as [$label, $val]) {
            $cx = $m + $ci * $colW;
            $pdf->setY($cardTop);
            $pdf->line($label, $cx, 9.0, false, 2.0);
            $pdf->line($val, $cx, 13.0, true, 0.0);
            $ci++;
        }
        // Move below the tallest card.
        $pdf->setY($cardTop - 26);
        $pdf->down(8);

        // ---- Monthly cumulative net chart ----
        if ($roiSeries !== []) {
            $pdf->line('Cumulative Net Profit by Month', $m, 13.0, true, 8.0);
            $chartSeries = array_map(static fn ($r) => ['label' => $r['label'], 'cumNet' => $r['cumNet']], $roiSeries);
            $pdf->barChart($chartSeries, $w);
            $pdf->down(8);
        }

        // ---- Budget vs actual ----
        $catKeys = array_unique(array_merge(array_keys($budgets), array_keys($actualByCategory)));
        sort($catKeys);
        if ($catKeys !== []) {
            $pdf->line('Budget vs Actual', $m, 13.0, true, 6.0);
            $pdf->row([
                ['Category', $w * 0.34, 'L'],
                ['Budget', $w * 0.22, 'R'],
                ['Actual', $w * 0.22, 'R'],
                ['Variance', $w * 0.22, 'R'],
            ], 16.0, true);
            foreach ($catKeys as $c) {
                $budget = (float) ($budgets[$c] ?? 0);
                $actual = (float) ($actualByCategory[$c] ?? 0);
                $var = round($budget - $actual, 2);
                $varStr = $var >= 0 ? '$' . number_format($var, 2) . ' under' : '-$' . number_format(abs($var), 2) . ' over';
                $pdf->row([
                    [$c, $w * 0.34, 'L'],
                    ['$' . number_format($budget, 2), $w * 0.22, 'R'],
                    ['$' . number_format($actual, 2), $w * 0.22, 'R'],
                    [$varStr, $w * 0.22, 'R'],
                ]);
            }
            $pdf->down(8);
        }

        // ---- Transactions ----
        $pdf->line('Transactions', $m, 13.0, true, 6.0);
        if ($txns === []) {
            $pdf->line('No transactions in the selected range.', $m, 10.0, false, 8.0);
        } else {
            $pdf->row([
                ['Date', $w * 0.16, 'L'],
                ['Type', $w * 0.12, 'L'],
                ['Category', $w * 0.20, 'L'],
                ['Amount', $w * 0.16, 'R'],
                ['Note', $w * 0.36, 'L'],
            ], 14.0, true);
            foreach ($txns as $t) {
                $pdf->row([
                    [$t->date, $w * 0.16, 'L'],
                    [ucfirst($t->type), $w * 0.12, 'L'],
                    [$t->category, $w * 0.20, 'L'],
                    ['$' . number_format($t->amount, 2), $w * 0.16, 'R'],
                    [mb_strlen($t->note) > 38 ? mb_substr($t->note, 0, 37) . '…' : $t->note, $w * 0.36, 'L'],
                ]);
            }
        }

        return $pdf->output();
    }
}
