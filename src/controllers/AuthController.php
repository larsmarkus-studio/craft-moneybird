<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use GuzzleHttp\Exception\GuzzleException;
use larsmarkusstudio\moneybird\events\OAuthConnectedEvent;
use larsmarkusstudio\moneybird\models\Identity;
use larsmarkusstudio\moneybird\Plugin;
use League\OAuth2\Client\Token\AccessToken;
use yii\web\BadRequestHttpException;
use yii\web\Response;

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
        // Drop anything left over from an abandoned attempt before starting fresh.
        $this->clearPending();

        // Only accept site-relative paths, never absolute URLs — otherwise a
        // crafted ?redirect= would turn the post-login redirect into an open
        // redirect (e.g. ?redirect=https://evil.com).
        $redirect = (string)Craft::$app->getRequest()->getQueryParam('redirect');
        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            Craft::$app->getSession()->set('moneybird.connectRedirect', $redirect);
        }

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

        try {
            $token = $auth->requestAccessToken($code);
        } catch (\Throwable $e) {
            Craft::error('Moneybird token exchange failed: ' . $e->getMessage(), __METHOD__);
            return $this->renderRetry();
        }

        Craft::$app->getSession()->set(self::PENDING_SESSION_KEY, [
            'access_token' => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'expires' => $token->getExpires(),
        ]);

        return $this->resolveOrRetry();
    }

    /**
     * Step 3 (only when ambiguous): the user picked an administration or themselves.
     */
    public function actionSelect(): Response
    {
        $this->requirePostRequest();

        $type = (string)$this->request->getRequiredBodyParam('type');
        $selection = (string)$this->request->getRequiredBodyParam('selection');
        $session = Craft::$app->getSession();

        // Only the two picker steps may write into the pending namespace.
        if (!in_array($type, ['administration', 'user'], true)) {
            throw new BadRequestHttpException('Invalid selection type.');
        }

        if (!$session->get(self::PENDING_SESSION_KEY)) {
            throw new BadRequestHttpException('No pending Moneybird connection.');
        }

        $session->set("moneybird.pending.{$type}", $selection);

        return $this->resolveOrRetry();
    }

    /**
     * Run identity resolution, falling back to a retry page if a Moneybird API
     * call fails transiently (network error, 5xx). Genuine 4xx logic errors
     * (no administrations, etc.) still surface as such.
     */
    private function resolveOrRetry(): Response
    {
        try {
            return $this->resolve();
        } catch (GuzzleException $e) {
            Craft::error('Moneybird connection failed: ' . $e->getMessage(), __METHOD__);
            return $this->renderRetry();
        }
    }

    /**
     * Disconnect the current user from Moneybird.
     */
    public function actionDisconnect(): Response
    {
        $this->requireLogin();
        $this->requirePostRequest();

        Plugin::getInstance()->auth->disconnect((int)Craft::$app->getUser()->getId());

        return $this->redirectToPostedUrl(null, UrlHelper::siteUrl());
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

        // Link strictly by Moneybird user ID — never by email. Matching on an
        // (unverified, partly free-form) Moneybird email would let a crafted
        // account take over an existing Craft user.
        $existingUserId = $auth->findUserIdByMoneybirdUserId($moneybirdUserId);
        $user = $existingUserId !== null
            ? Craft::$app->getUsers()->getUserById($existingUserId)
            : null;

        // Login-then-connect: an already signed-in user is linking a Moneybird
        // account to their existing Craft user. The Craft session proves the
        // human, the OAuth handshake proves Moneybird ownership — so we attach
        // to the current user rather than minting a duplicate. Still no email
        // matching (Moneybird emails are unverified); identity comes from the
        // live session.
        if ($user === null && !Craft::$app->getUser()->getIsGuest()) {
            $user = Craft::$app->getUser()->getIdentity();
        }

        // First-time connection: create a Craft user. The completed Moneybird
        // OAuth handshake is the gate here, so this works regardless of Craft's
        // global allowPublicRegistration setting (which only guards the
        // built-in front-end signup form, not programmatic creation).
        if ($user === null) {
            // Refuse up front if the email is already taken (incl. trashed users —
            // Craft's uniqueness check counts them). Otherwise createUser() saves
            // the element, then activateUser() throws on the duplicate, leaking a
            // half-created user on every retry. An existing account must link via
            // login-then-connect, not by minting a duplicate.
            $emailTaken = User::find()
                ->email($effectiveEmail)
                ->status(null)   // any status: suspended/pending/inactive included
                ->trashed(null)  // and trashed — Craft's uniqueness counts them too
                ->exists();
            if ($emailTaken) {
                throw new BadRequestHttpException(
                    "A Craft account already exists for {$effectiveEmail}. Log in to that account first, then connect Moneybird to link it."
                );
            }

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

        $redirectUrl = $session->get('moneybird.connectRedirect');
        $this->clearPending();

        Craft::$app->getUser()->login($user);

        $event = new OAuthConnectedEvent();
        $event->user = $user;
        $event->moneybirdUserId = $moneybirdUserId;
        $event->administrationId = $administrationId;
        $event->identity = $identity;
        $event->isNewUser = $isNewUser;
        $event->redirectUrl = $redirectUrl;
        $this->trigger(self::EVENT_OAUTH_CONNECTED, $event);

        return $this->redirect($event->redirectUrl ?? UrlHelper::siteUrl());
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

    /**
     * Wipe all transient OAuth-flow state from the session.
     */
    private function clearPending(): void
    {
        $session = Craft::$app->getSession();
        $session->remove(self::PENDING_SESSION_KEY);
        $session->remove('moneybird.pending.administration');
        $session->remove('moneybird.pending.user');
        $session->remove('moneybird.connectRedirect');
    }

    /**
     * Show a "try again" page after a transient failure. Re-links to connect,
     * preserving the original ?redirect= target if one was set.
     */
    private function renderRetry(): Response
    {
        $redirect = Craft::$app->getSession()->get('moneybird.connectRedirect');

        return $this->renderTemplate('craft-moneybird/_retry', [
            'retryUrl' => UrlHelper::actionUrl(
                'craft-moneybird/auth/connect',
                $redirect ? ['redirect' => $redirect] : [],
            ),
        ]);
    }
}
