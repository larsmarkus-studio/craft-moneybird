<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\models;

use craft\base\Model;

/**
 * A Moneybird receipt/document returned by the API.
 */
class Receipt extends Model
{
    public string $id = '';
    public ?string $reference = null;
    public ?string $date = null;
    public ?string $state = null;
    public ?string $totalPrice = null;
    public ?string $moneybirdUrl = null;

    public static function fromApi(array $data): self
    {
        $model = new self();
        $model->id = (string)($data['id'] ?? '');
        $model->reference = $data['reference'] ?? null;
        $model->date = $data['date'] ?? null;
        $model->state = $data['state'] ?? null;
        $model->totalPrice = isset($data['total_price_incl_tax'])
            ? (string)$data['total_price_incl_tax']
            : null;
        $model->moneybirdUrl = $data['url'] ?? null;

        return $model;
    }
}
