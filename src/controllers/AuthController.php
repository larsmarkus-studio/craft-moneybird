<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Response;
use larsmarkusstudio\moneybird\events\OAuthConnectedEvent;
use larsmarkusstudio\moneybird\models\Identity;
use larsmarkusstudio\moneybird\Plugin;
use League\OAuth2\Client\Token\AccessToken;
use yii\web\BadRequestHttpException;

/**
 * Drives the Moneybird OAuth flow: connect → callback → (optional pickers) →
 * create/match Craft user, store tokens, log in.
 */
class AuthController extends Controller
{
    public const EVENT_OAUTH_CONNECTED = 'oauthConnected';

    private const PENDING_SESSION_KEY = 'moneybird.pending';

    protected array|bool|int $allowAnonymous = ['connect', 'callback', 'select'];

    /**
     * Step 1: redirect the user to Moneybird's authorization screen.
     */
    public function actionConnect(): Response
    {
        return $this->redirect(Plugin::getInstance()->auth->getAuthorizationUrl());
    }

    /**
     * Step 2: Moneybird redirects back here with a code. Validate state, exchange
     * the code for tokens, then resolve administration + user identity.
     */
    public function actionCallback(): Response
    {
        $request = Craft::$app->getRequest();
        $auth = Plugin::getInstance()->auth;

        if ($request->getQueryParam('error')) {
            throw new BadRequestHttpException('Moneybird authorization was denied.');
        }

        $state = $request->getQueryParam('state');
        if (!$state || $state !== $auth->getStoredState()) {
            $auth->clearStoredState();
            throw new BadRequestHttpException('Invalid OAuth state.');
        }
        $auth->clearStoredState();

        $code = (string)$request->getRequiredQueryParam('code');
        $token = $auth->requestAccessToken($code);

        Craft::$app->getSession()->set(self::PENDING_SESSION_KEY, [
            'access_token' => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'expires' => $token->getExpires(),
        ]);

        return $this->resolve();
    }

    /**
     * Step 3 (only when ambiguous): the user picked an administration or themselves.
     */
    public function actionSelect(): Response
    {
        $this->requirePostRequest();

        $type = $this->request->getRequiredBodyParam('type');
        $selection = (string)$this->request->getRequiredBodyParam('selection');
        $session = Craft::$app->getSession();

        if (!$session->get(self::PENDING_SESSION_KEY)) {
            throw new BadRequestHttpException('No pending Moneybird connection.');
        }

        $session->set("moneybird.pending.{$type}", $selection);

        return $this->resolve();
    }

    /**
     * Disconnect the current user from Moneybird.
     */
    public function actionDisconnect(): Response
    {
        $this->requireLogin();
        $this->requirePostRequest();

        Plugin::getInstance()->auth->disconnect((int)Craft::$app->getUser()->getId());

        return $this->redirectToPostedUrl() ?? $this->redirect(UrlHelper::siteUrl());
    }

    /**
     * Resolve administration + user, showing a picker when either is ambiguous,
     * then finalise the connection.
     */
    private function resolve(): Response
    {
        $auth = Plugin::getInstance()->auth;
        $session = Craft::$app->getSession();
        $accessToken = (string)$session->get(self::PENDING_SESSION_KEY)['access_token'];

        // Administration
        $administrations = $auth->getAdministrations($accessToken);
        if ($administrations === []) {
            throw new BadRequestHttpException('No Moneybird administrations available.');
        }

        $administrationId = $session->get('moneybird.pending.administration')
            ?? (count($administrations) === 1 ? $administrations[0]->id : null);

        if ($administrationId === null) {
            return $this->renderPicker('administration', array_map(
                static fn($a) => ['id' => $a->id, 'label' => $a->name],
                $administrations,
            ));
        }

        // User within the administration
        $users = $auth->getUsers($accessToken, $administrationId);
        if ($users === []) {
            throw new BadRequestHttpException('No Moneybird users available.');
        }

        $moneybirdUserId = $session->get('moneybird.pending.user')
            ?? (count($users) === 1 ? $users[0]->id : null);

        if ($moneybirdUserId === null) {
            return $this->renderPicker('user', array_map(
                static fn($u) => ['id' => $u->id, 'label' => trim($u->name . ' ' . ($u->email ? "({$u->email})" : ''))],
                $users,
            ));
        }

        $selectedUser = null;
        foreach ($users as $u) {
            if ($u->id === $moneybirdUserId) {
                $selectedUser = $u;
                break;
            }
        }

        $identity = $auth->getIdentity($accessToken, $administrationId);

        return $this->finalize($administrationId, $moneybirdUserId, $selectedUser?->name, $selectedUser?->email, $identity);
    }

    private function finalize(
        string $administrationId,
        string $moneybirdUserId,
        ?string $name,
        ?string $email,
        ?Identity $identity,
    ): Response {
        $auth = Plugin::getInstance()->auth;
        $session = Craft::$app->getSession();
        $pending = $session->get(self::PENDING_SESSION_KEY);

        $effectiveEmail = $email ?: $identity?->email ?: "moneybird-{$moneybirdUserId}@moneybird.invalid";
        $isNewUser = false;

        // 1) Already linked via a stored token.
        $existingUserId = $auth->findUserIdByMoneybirdUserId($moneybirdUserId);
        $user = $existingUserId !== null
            ? Craft::$app->getUsers()->getUserById($existingUserId)
            : null;

        // 2) Otherwise match an existing Craft user by email (keeps the flow
        //    idempotent if a prior attempt created the user but not the token).
        $user ??= Craft::$app->getUsers()->getUserByUsernameOrEmail($effectiveEmail);

        // 3) Otherwise create one.
        if ($user === null) {
            $user = $this->createUser($name, $effectiveEmail);
            $isNewUser = true;
        }

        if ($user === null) {
            throw new BadRequestHttpException('Could not resolve a Craft user for this Moneybird account.');
        }

        $token = new AccessToken([
            'access_token' => $pending['access_token'],
            'refresh_token' => $pending['refresh_token'],
            'expires' => $pending['expires'],
        ]);
        $auth->saveTokens((int)$user->id, $moneybirdUserId, $administrationId, $token);

        $session->remove(self::PENDING_SESSION_KEY);
        $session->remove('moneybird.pending.administration');
        $session->remove('moneybird.pending.user');

        Craft::$app->getUser()->login($user);

        $event = new OAuthConnectedEvent();
        $event->user = $user;
        $event->moneybirdUserId = $moneybirdUserId;
        $event->administrationId = $administrationId;
        $event->identity = $identity;
        $event->isNewUser = $isNewUser;
        $this->trigger(self::EVENT_OAUTH_CONNECTED, $event);

        return $this->redirect(UrlHelper::siteUrl());
    }

    private function createUser(?string $name, string $email): ?User
    {
        $user = new User();
        $user->email = $email;
        $user->username = $email;
        $user->fullName = $name ?: '';

        if (!Craft::$app->getElements()->saveElement($user)) {
            Craft::error('Failed to create Craft user for Moneybird login: '
                . implode(', ', $user->getFirstErrors()), __METHOD__);
            return null;
        }

        Craft::$app->getUsers()->activateUser($user);

        return $user;
    }

    private function renderPicker(string $type, array $options): Response
    {
        return $this->renderTemplate('craft-moneybird/_picker', [
            'type' => $type,
            'options' => $options,
        ]);
    }
}
