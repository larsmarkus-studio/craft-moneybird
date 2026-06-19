<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Moneybird plugin settings.
 *
 * Sensitive credentials are stored as environment-variable references
 * (e.g. `$MONEYBIRD_CLIENT_ID`) and resolved at runtime via the
 * env attribute parser — never persisted as plain values in project config.
 */
class Settings extends Model
{
    /**
     * OAuth client ID — env: `$MONEYBIRD_CLIENT_ID`.
     */
    public string $clientId = '';

    /**
     * OAuth client secret — env: `$MONEYBIRD_CLIENT_SECRET`.
     */
    public string $clientSecret = '';

    /**
     * OAuth redirect URI — env: `$MONEYBIRD_REDIRECT_URI`.
     */
    public string $redirectUri = '';

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['clientId', 'clientSecret', 'redirectUri'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['clientId', 'clientSecret', 'redirectUri'], 'string'],
            [['clientId', 'clientSecret', 'redirectUri'], 'trim'],
        ];
    }
}
