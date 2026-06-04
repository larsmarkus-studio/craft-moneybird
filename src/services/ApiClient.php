<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use GuzzleHttp\Exception\ClientException;
use larsmarkusstudio\moneybird\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Authenticated client for the administration-scoped Moneybird API.
 *
 * Resolves the user's stored token + administration ID, prepends the API base,
 * and transparently refreshes the token once on a 401.
 *
 * Moneybird's rate limit is 150 requests / 5 minutes platform-wide — keep calls
 * minimal and prefer the local caches built on top of this client.
 */
class ApiClient extends Component
{
    public function get(int $userId, string $path): array
    {
        return $this->request($userId, 'GET', $path);
    }

    public function post(int $userId, string $path, array $body): array
    {
        return $this->request($userId, 'POST', $path, $body);
    }

    public function request(int $userId, string $method, string $path, ?array $body = null): array
    {
        $auth = Plugin::getInstance()->auth;
        $administrationId = $auth->getAdministrationId($userId);

        if ($administrationId === null) {
            throw new InvalidArgumentException("No Moneybird connection for user {$userId}.");
        }

        $url = AuthService::API_BASE . $administrationId . '/' . ltrim($path, '/');

        try {
            return $this->send($auth->getValidAccessToken($userId), $method, $url, $body);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 401 || !$auth->refreshAccessToken($userId)) {
                throw $e;
            }

            // Retry once with a freshly refreshed token.
            return $this->send($auth->getValidAccessToken($userId), $method, $url, $body);
        }
    }

    private function send(?string $accessToken, string $method, string $url, ?array $body): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = Craft::createGuzzleClient()->request($method, $url, $options);
        $contents = (string)$response->getBody();

        return $contents === '' ? [] : (Json::decode($contents) ?? []);
    }
}
