<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\records;

use craft\db\ActiveRecord;

/**
 * Stores Moneybird OAuth tokens for a Craft user.
 *
 * Access/refresh tokens are stored encrypted (see AuthService); the record
 * itself holds the encrypted strings verbatim.
 *
 * @property int $id
 * @property int $userId
 * @property string $moneybirdUserId
 * @property string $administrationId
 * @property string $accessToken
 * @property string|null $refreshToken
 * @property int|null $tokenExpiresAt
 */
class TokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%moneybird_tokens}}';
    }
}
