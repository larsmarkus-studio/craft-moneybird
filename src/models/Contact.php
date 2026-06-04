<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\models;

use craft\base\Model;

/**
 * A Moneybird contact (customer/supplier).
 */
class Contact extends Model
{
    public string $id = '';
    public string $companyName = '';
    public ?string $email = null;
    public ?string $customerId = null;

    public static function fromApi(array $data): self
    {
        $model = new self();
        $model->id = (string)($data['id'] ?? '');
        $model->companyName = (string)($data['company_name'] ?? '');
        $model->email = $data['send_invoices_to_email'] ?? ($data['email'] ?? null);
        $model->customerId = isset($data['customer_id']) ? (string)$data['customer_id'] : null;

        return $model;
    }
}
