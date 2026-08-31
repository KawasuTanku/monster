<?php

declare(strict_types=1);

namespace Monster;

/**
 * A single profit & loss line item for the energy-drink side business.
 *
 * type: "sale" | "expense"
 *   - sale    => money IN  (revenue from energy-drink sales)
 *   - expense => money OUT (cost of goods, fees, shipping, etc.)
 *
 * amount is always stored as a positive number; the sign is implied by type.
 */
final class Transaction
{
    public const TYPE_SALE = 'sale';
    public const TYPE_EXPENSE = 'expense';

    public string $id = '';
    public string $type = '';
    public float $amount = 0.0;
    public string $category = '';
    public string $note = '';
    public string $date = ''; // YYYY-MM-DD
    public int $createdAt = 0;
    /** Linked inventory item id, or '' when this transaction is not item-linked. */
    public string $itemId = '';
    /** Customer id for tab charges, or '' when not a tab charge. */
    public string $customerId = '';
    /** Units moved for item-linked transactions (cans). Defaults to 1.0. */
    public float $qty = 1.0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $t = new self();
        $t->id = (string) ($row['id'] ?? '');
        $t->type = (string) ($row['type'] ?? self::TYPE_SALE);
        $t->amount = (float) ($row['amount'] ?? 0);
        $t->category = (string) ($row['category'] ?? '');
        $t->note = (string) ($row['note'] ?? '');
        $t->date = (string) ($row['date'] ?? date('Y-m-d'));
        $t->createdAt = (int) ($row['createdAt'] ?? time());
        $t->itemId = (string) ($row['itemId'] ?? '');
        $t->customerId = (string) ($row['customerId'] ?? '');
        $t->qty = (float) ($row['qty'] ?? 1);
        return $t;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => round($this->amount, 2),
            'category' => $this->category,
            'note' => $this->note,
            'date' => $this->date,
            'createdAt' => $this->createdAt,
            'itemId' => $this->itemId,
            'customerId' => $this->customerId,
            'qty' => round($this->qty, 4),
        ];
    }

    /** Signed effect on profit: sales add, expenses subtract. */
    public function signed(): float
    {
        return $this->type === self::TYPE_EXPENSE ? -abs($this->amount) : abs($this->amount);
    }
}
