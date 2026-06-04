<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\models;

use craft\base\Model;

/**
 * A Moneybird identity (company profile used on documents).
 */
class Identity extends Model
{
    public string $administrationId = '';
    public string $companyName = '';
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $address = null;
    public ?string $chamberOfCommerce = null;
    public ?string $taxNumber = null;

    public static function fromApi(array $data, string $administrationId = ''): self
    {
        $model = new self();
        $model->administrationId = $administrationId;
        $model->companyName = (string)($data['company_name'] ?? '');
        $model->email = $data['email'] ?? null;
        $model->phone = $data['phone'] ?? null;
        $model->address = $data['address1'] ?? null;
        $model->chamberOfCommerce = $data['chamber_of_commerce'] ?? null;
        $model->taxNumber = $data['tax_number'] ?? null;

        return $model;
    }
}
