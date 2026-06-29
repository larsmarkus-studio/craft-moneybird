<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use larsmarkusstudio\moneybird\models\Administration;
use larsmarkusstudio\moneybird\models\Identity;
use larsmarkusstudio\moneybird\models\MoneybirdUser;
use larsmarkusstudio\moneybird\models\Settings;
use larsmarkusstudio\moneybird\Plugin;
use larsmarkusstudio\moneybird\records\TokenRecord;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Handles the Moneybird OAuth flow, encrypted token storage, token refresh,
 * and the identity-resolution API calls used during onboarding.
 */
class AuthService extends Component
{
    // `settings` is required for the /users and /identities endpoints used in
    // identity resolution; `sales_invoices` + `documents` for receipts/attachments.
    public const SCOPES = 'sales_invoices documents settings';
    public const API_BASE = 'https://moneybird.com/api/v2/';

    private const AUTHORIZE_URL = 'https://moneybird.com/oauth/authorize';
    private const TOKEN_URL = 'https://moneybird.com/oauth/token';
    private const STATE_SESSION_KEY = 'moneybird.oauth2state';
    private const REFRESH_LEEWAY = 60;

    private ?GenericProvider $provider = null;

    // region OAuth provider

    public function getProvider(): GenericProvider
    {
        if ($this->provider === null) {
            /** @var Settings $settings */
            $settings = Plugin::getInstance()->getSettings();
            $this->provider = new GenericProvider([
                'clientId' => App::parseEnv($settings->clientId),
                'clientSecret' => App::parseEnv($settings->clientSecret),
                'redirectUri' => App::parseEnv($settings->redirectUri),
                'urlAuthorize' => self::AUTHORIZE_URL,
                'urlAccessToken' => self::TOKEN_URL,
                'urlResourceOwnerDetails' => '',
                'scopes' => self::SCOPES,
                'scopeSeparator' => ' ',
            ]);
        }

        return $this->provider;
    }

    /**
     * Build the authorization URL and stash the CSRF state token in the session.
     */
    public function getAuthorizationUrl(): string
    {
        $provider = $this->getProvider();
        $url = $provider->getAuthorizationUrl(['scope' => self::SCOPES]);
        Craft::$app->getSession()->set(self::STATE_SESSION_KEY, $provider->getState());

        return $url;
    }

    public function getStoredState(): ?string
    {
        return Craft::$app->getSession()->get(self::STATE_SESSION_KEY);
    }

    public function clearStoredState(): void
    {
        Craft::$app->getSession()->remove(self::STATE_SESSION_KEY);
    }

    /**
     * Exchange an authorization code for an access token (server-to-server).
     */
    public function requestAccessToken(string $code): AccessTokenInterface
    {
        return $this->getProvider()->getAccessToken('authorization_code', [
            'code' => $code,
        ]);
    }

    // endregion

    // region Identity resolution (pre-storage, using a raw token)

    /**
     * @return Administration[]
     */
    public function getAdministrations(string $accessToken): array
    {
        $data = $this->rawGet($accessToken, self::API_BASE . 'administrations.json');

        return array_map(static fn(array $row) => Administration::fromApi($row), $data);
    }

    /**
     * @return MoneybirdUser[]
     */
    public function getUsers(string $accessToken, string $administrationId): array
    {
        $data = $this->rawGet($accessToken, self::API_BASE . $administrationId . '/users.json');

        return array_map(static fn(array $row) => MoneybirdUser::fromApi($row), $data);
    }

    public function getIdentity(string $accessToken, string $administrationId): ?Identity
    {
        $data = $this->rawGet($accessToken, self::API_BASE . $administrationId . '/identities.json');
        $first = $data[0] ?? null;

        return $first ? Identity::fromApi($first, $administrationId) : null;
    }

    // endregion

    // region Token storage

    public function isConnected(?int $userId = null): bool
    {
        $userId ??= Craft::$app->getUser()->getId();

        return $userId !== null && $this->getRecord($userId) !== null;
    }

    /**
     * The user's active administration token, falling back to any of theirs
     * (covers rows predating the isActive flag).
     */
    public function getRecord(int $userId): ?TokenRecord
    {
        return TokenRecord::findOne(['userId' => $userId, 'isActive' => true])
            ?? TokenRecord::findOne(['userId' => $userId]);
    }

    /** All administrations the user has connected, active one first. */
    public function getConnectedAdministrations(int $userId): array
    {
        $records = TokenRecord::find()
            ->where(['userId' => $userId])
            ->orderBy(['isActive' => SORT_DESC, 'administrationName' => SORT_ASC])
            ->all();

        return array_map(static fn(TokenRecord $r) => [
            'id' => $r->administrationId,
            'name' => $r->administrationName ?: $r->administrationId,
            'active' => (bool)$r->isActive,
        ], $records);
    }

    /** Make a connected administration the active one. False if not connected. */
    public function setActiveAdministration(int $userId, string $administrationId): bool
    {
        $target = TokenRecord::findOne(['userId' => $userId, 'administrationId' => $administrationId]);
        if ($target === null) {
            return false;
        }

        TokenRecord::updateAll(['isActive' => false], ['userId' => $userId]);
        $target->isActive = true;

        return $target->save();
    }

    public function findUserIdByMoneybirdUserId(string $moneybirdUserId): ?int
    {
        $record = TokenRecord::findOne(['moneybirdUserId' => $moneybirdUserId]);

        return $record?->userId;
    }

    public function getAdministrationId(int $userId): ?string
    {
        return $this->getRecord($userId)?->administrationId;
    }

    public function saveTokens(
        int $userId,
        string $moneybirdUserId,
        string $administrationId,
        AccessTokenInterface $token,
        ?string $administrationName = null,
    ): void {
        // One token row per (userId, administrationId): a Moneybird token is
        // scoped to the single administration it was granted for, so connecting
        // another administration adds a row rather than overwriting. The freshly
        // connected administration becomes active.
        $record = TokenRecord::findOne(['userId' => $userId, 'administrationId' => $administrationId])
            ?? new TokenRecord(['userId' => $userId]);

        $record->moneybirdUserId = $moneybirdUserId;
        $record->administrationId = $administrationId;
        if ($administrationName !== null) {
            $record->administrationName = $administrationName;
        }
        $record->isActive = true;
        $record->accessToken = $this->encrypt((string)$token->getToken());

        $refreshToken = $token->getRefreshToken();
        if ($refreshToken !== null) {
            $record->refreshToken = $this->encrypt($refreshToken);
        }

        $record->tokenExpiresAt = $token->getExpires();

        // Don't swallow a failed write: a silently-unsaved token means the next
        // login can't find the link and re-runs account creation.
        if (!$record->save()) {
            Craft::error(
                'Failed to save Moneybird tokens for user ' . $userId . ': '
                . implode(', ', $record->getFirstErrors()),
                __METHOD__,
            );
            throw new \RuntimeException('Could not persist Moneybird tokens.');
        }

        // Exactly one active administration per user.
        TokenRecord::updateAll(
            ['isActive' => false],
            ['and', ['userId' => $userId], ['not', ['id' => $record->id]]],
        );
    }

    /**
     * Return a usable access token for the user, refreshing it first if expired.
     */
    public function getValidAccessToken(int $userId): ?string
    {
        $record = $this->getRecord($userId);
        if ($record === null) {
            return null;
        }

        if ($this->isExpired($record) && $record->refreshToken !== null) {
            if (!$this->refreshAccessToken($userId)) {
                return null;
            }
            $record = $this->getRecord($userId);
        }

        return $record !== null ? $this->decrypt($record->accessToken) : null;
    }

    public function refreshAccessToken(int $userId): bool
    {
        $record = $this->getRecord($userId);
        if ($record === null || $record->refreshToken === null) {
            return false;
        }

        $refreshToken = $this->decrypt($record->refreshToken);

        try {
            $token = $this->getProvider()->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Moneybird token refresh failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }

        $record->accessToken = $this->encrypt((string)$token->getToken());
        if ($token->getRefreshToken() !== null) {
            $record->refreshToken = $this->encrypt($token->getRefreshToken());
        }
        $record->tokenExpiresAt = $token->getExpires();

        return $record->save();
    }

    public function disconnect(int $userId): void
    {
        Db::delete('{{%moneybird_tokens}}', ['userId' => $userId]);
    }

    // endregion

    private function isExpired(TokenRecord $record): bool
    {
        return $record->tokenExpiresAt !== null
            && time() >= ($record->tokenExpiresAt - self::REFRESH_LEEWAY);
    }

    /**
     * Encrypt a value for storage. encryptByKey() returns raw binary, so we
     * base64-encode it to keep it safe for a utf8 text column.
     */
    private function encrypt(string $value): string
    {
        return base64_encode(Craft::$app->getSecurity()->encryptByKey($value));
    }

    private function decrypt(string $value): string
    {
        return Craft::$app->getSecurity()->decryptByKey(base64_decode($value));
    }

    /**
     * Perform an authenticated GET with a raw bearer token and decode the JSON body.
     */
    private function rawGet(string $accessToken, string $url): array
    {
        $client = Craft::createGuzzleClient();
        $response = $client->get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ]);

        return Json::decode((string)$response->getBody()) ?? [];
    }
}
