<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\services;

use craft\base\Component;
use larsmarkusstudio\moneybird\models\Contact;
use larsmarkusstudio\moneybird\Plugin;

/**
 * Find, search and create Moneybird contacts.
 */
class ContactsService extends Component
{
    /**
     * @return Contact[]
     */
    public function search(int $userId, string $query): array
    {
        $data = Plugin::getInstance()->api->get(
            $userId,
            'contacts.json?query=' . rawurlencode($query),
        );

        return array_map(static fn(array $row) => Contact::fromApi($row), $data);
    }

    public function findById(int $userId, string $id): ?Contact
    {
        $data = Plugin::getInstance()->api->get($userId, "contacts/{$id}.json");

        return $data === [] ? null : Contact::fromApi($data);
    }

    public function findByName(int $userId, string $companyName): ?Contact
    {
        foreach ($this->search($userId, $companyName) as $contact) {
            if (strcasecmp($contact->companyName, $companyName) === 0) {
                return $contact;
            }
        }

        return null;
    }

    public function create(int $userId, string $companyName): Contact
    {
        $data = Plugin::getInstance()->api->post($userId, 'contacts.json', [
            'contact' => ['company_name' => $companyName],
        ]);

        return Contact::fromApi($data);
    }

    public function findOrCreate(int $userId, string $companyName): Contact
    {
        return $this->findByName($userId, $companyName)
            ?? $this->create($userId, $companyName);
    }
}
