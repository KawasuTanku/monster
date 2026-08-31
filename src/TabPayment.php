<?php

declare(strict_types=1);

namespace Monster;

/**
 * A payment received from a customer against their tab.
 */
final class TabPayment
{
    public string $id = '';
    public string $customerId = '';
    public float $amount = 0.0;
    public string $date = '';
    public string $note = '';
    public int $createdAt = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $p = new self();
        $p->id = (string) ($row['id'] ?? '');
        $p->customerId = (string) ($row['customerId'] ?? '');
        $p->amount = (float) ($row['amount'] ?? 0);
        $p->date = (string) ($row['date'] ?? date('Y-m-d'));
        $p->note = (string) ($row['note'] ?? '');
        $p->createdAt = (int) ($row['createdAt'] ?? time());
        return $p;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customerId' => $this->customerId,
            'amount' => round($this->amount, 2),
            'date' => $this->date,
            'note' => $this->note,
            'createdAt' => $this->createdAt,
        ];
    }
}
