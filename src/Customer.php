<?php

declare(strict_types=1);

namespace Monster;

/**
 * A customer who runs a tab (buys now, pays later).
 */
final class Customer
{
    public string $id = '';
    public string $name = '';
    public string $note = '';
    public int $createdAt = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $c = new self();
        $c->id = (string) ($row['id'] ?? '');
        $c->name = (string) ($row['name'] ?? '');
        $c->note = (string) ($row['note'] ?? '');
        $c->createdAt = (int) ($row['createdAt'] ?? time());
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'note' => $this->note,
            'createdAt' => $this->createdAt,
        ];
    }

    /** Outstanding balance: total charges minus total payments. */
    public function balance(TabPaymentRepository $payments, TransactionRepository $txns): float
    {
        $charges = 0.0;
        foreach ($txns->forCustomer($this->id) as $t) {
            if ($t->type === Transaction::TYPE_SALE) {
                $charges += $t->amount;
            }
        }
        $paid = 0.0;
        foreach ($payments->forCustomer($this->id) as $p) {
            $paid += $p->amount;
        }
        return round($charges - $paid, 2);
    }
}
