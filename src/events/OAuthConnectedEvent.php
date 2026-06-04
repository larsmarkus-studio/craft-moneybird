<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\events;

use craft\elements\User;
use larsmarkusstudio\moneybird\models\Identity;
use yii\base\Event;

/**
 * Fired after a Moneybird OAuth flow completes and tokens are stored.
 */
class OAuthConnectedEvent extends Event
{
    public User $user;
    public string $moneybirdUserId;
    public string $administrationId;
    public ?Identity $identity = null;

    /**
     * Whether the Craft user was created during this flow (vs. matched).
     */
    public bool $isNewUser = false;
}
