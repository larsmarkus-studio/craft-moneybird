<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\models;

use craft\base\Model;

/**
 * A Moneybird administration (the account/bookkeeping a user has access to).
 */
class Administration extends Model
{
    public string $id = '';
    public string $name = '';
    public ?string $language = null;
    public ?string $currency = null;
    public ?string $country = null;

    public static function fromApi(array $data): self
    {
        $model = new self();
        $model->id = (string)($data['id'] ?? '');
        $model->name = (string)($data['name'] ?? '');
        $model->language = $data['language'] ?? null;
        $model->currency = $data['currency'] ?? null;
        $model->country = $data['country'] ?? null;

        return $model;
    }
}
