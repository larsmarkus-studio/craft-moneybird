<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\models;

use craft\base\Model;

/**
 * A single line on a Moneybird receipt/document.
 */
class ReceiptLine extends Model
{
    public string $description = '';
    public string $price = '0';
    public ?string $ledgerAccountId = null;
    public ?string $taxRateId = null;

    /**
     * Serialise to the array shape Moneybird's documents endpoint expects.
     */
    public function toApi(): array
    {
        $line = [
            'description' => $this->description,
            'price' => $this->price,
        ];

        if ($this->ledgerAccountId !== null) {
            $line['ledger_account_id'] = $this->ledgerAccountId;
        }

        if ($this->taxRateId !== null) {
            $line['tax_rate_id'] = $this->taxRateId;
        }

        return $line;
    }
}
