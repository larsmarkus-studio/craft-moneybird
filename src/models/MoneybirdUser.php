<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\models;

use craft\base\Model;

/**
 * A user within a Moneybird administration (from the `/users` endpoint).
 * Used to resolve identity on first login, since Moneybird has no `/me`.
 */
class MoneybirdUser extends Model
{
    public string $id = '';
    public string $name = '';
    public ?string $email = null;

    public static function fromApi(array $data): self
    {
        $model = new self();
        $model->id = (string)($data['id'] ?? '');
        $model->name = (string)($data['name'] ?? '');
        $model->email = $data['email'] ?? null;

        return $model;
    }
}
