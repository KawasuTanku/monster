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

        // ---- Title + meta ----
        $pdf->text('Monster P&L Report', $m, 18.0, true);
        $pdf->down(6);
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
        $pdf->text($sub, $m, 9.0);
        $pdf->down(4);
        $pdf->text('Generated: ' . date('Y-m-d H:i'), $m, 9.0);
        $pdf->down(16);

        // ---- Summary stat cards ----
        $pdf->text('Summary', $m, 13.0, true);
        $pdf->down(16);
        $statCols = [
            ['Revenue', '$' . number_format($summary['revenue'], 2)],
            ['Expenses', '$' . number_format($summary['expenses'], 2)],
            ['Net Profit', '$' . number_format($summary['net'], 2)],
            ['ROI', number_format($roiOverall['roiPct'], 2) . '%'],
        ];
        // Lay out the four cards left-to-right.
        $colW = $w / 4;
        $x0 = $m;
        foreach ($statCols as [$label, $val]) {
            $pdf->text($label, $x0, 9.0);
            $pdf->text($val, $x0, 13.0, true);
            $x0 += $colW;
        }
        $pdf->down(26);

        // ---- Monthly cumulative net chart ----
        if ($roiSeries !== []) {
            $pdf->text('Cumulative Net Profit by Month', $m, 13.0, true);
            $pdf->down(18);
            $chartSeries = array_map(static fn ($r) => ['label' => $r['label'], 'cumNet' => $r['cumNet']], $roiSeries);
            $pdf->barChart($chartSeries, $w);
            $pdf->down(10);
        }

        // ---- Budget vs actual ----
        $catKeys = array_unique(array_merge(array_keys($budgets), array_keys($actualByCategory)));
        sort($catKeys);
        if ($catKeys !== []) {
            $pdf->text('Budget vs Actual', $m, 13.0, true);
            $pdf->down(16);
            $pdf->row([
                ['Category', $colW = $w * 0.34, 'L'],
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
            $pdf->down(10);
        }

        // ---- Transactions ----
        $pdf->text('Transactions', $m, 13.0, true);
        $pdf->down(16);
        if ($txns === []) {
            $pdf->text('No transactions in the selected range.', $m, 10.0);
            $pdf->down(14);
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
