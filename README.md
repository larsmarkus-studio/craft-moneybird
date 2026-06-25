# Moneybird for Craft CMS

A Craft CMS 5 plugin that adds [Moneybird](https://www.moneybird.com) OAuth
login and a small set of typed API service classes. It is built as a
**foundation**: it handles the awkward parts — the OAuth handshake, encrypted
token storage, automatic token refresh — and exposes clean services so you can
build your own Moneybird integration on top without touching any of that.

It was extracted from [Flexsheet](https://flexsheet.eu), a business-mileage app
that turns trips into Moneybird receipts.

## Features

- **OAuth login** — connect a Craft user to a Moneybird account using the
  standard OAuth 2.0 authorization-code flow.
- **Encrypted token storage** — access and refresh tokens are encrypted at rest
  with Craft's own security key.
- **Automatic refresh** — expired tokens are refreshed transparently on the
  next API call; you never handle expiry yourself.
- **Onboarding pickers** — when a user has access to more than one
  administration (or more than one Moneybird user), the plugin asks which one to
  use. Single-option cases are resolved automatically.
- **Typed services** — `auth`, `api`, `contacts` and `documents`, returning
  plain models instead of raw arrays.
- **Events** — hook into `OAuthConnected` and `ReceiptCreated` to extend the
  flow without forking the plugin.

## Requirements

- Craft CMS 5.6.0 or later (Pro edition — the flow creates and logs in Craft users)
- PHP 8.2 or later

## Installation

```bash
composer require larsmarkus-studio/craft-moneybird
./craft plugin/install craft-moneybird
```

## Setup

### 1. Register a Moneybird OAuth application

In Moneybird, go to **Settings → Developers → OAuth applications** and create an
application. Set the redirect URI to a URL on your own site, for example:

```
https://your-site.test/actions/craft-moneybird/auth/callback
```

Moneybird gives you a **client ID** and **client secret**.

### 2. Add your credentials as environment variables

Credentials are never stored in project config — they live in environment
variables and are referenced from the plugin settings.

```bash
MONEYBIRD_CLIENT_ID=
MONEYBIRD_CLIENT_SECRET=
MONEYBIRD_REDIRECT_URI=https://your-site.test/actions/craft-moneybird/auth/callback
```

### 3. Reference them in the control panel

Go to **Settings → Moneybird** and point each field at its environment variable
(`$MONEYBIRD_CLIENT_ID`, and so on).

The OAuth flow is built directly on
[`league/oauth2-client`](https://oauth2-client.thephpleague.com) — no other
OAuth plugin is required.

## Connecting a user

Send the user to the connect action. They authorize on Moneybird, get redirected
back, and the plugin logs them in.

Users are linked **only** by their Moneybird user ID — never by email — so a
returning user always maps back to the same Craft account. On a first-time
connection a new Craft user is created, but only if Craft's **public
registration** setting is enabled; otherwise the flow stops with an error.

If a Moneybird call fails transiently (network blip, token exchange error), the
user sees a simple "try again" page rather than a 500.

```twig
<a href="{{ actionUrl('craft-moneybird/auth/connect') }}">Connect Moneybird</a>
```

Pass `?redirect=` to control where the user lands afterwards. For safety this
must be a **site-relative path** (e.g. `/dashboard`) — absolute URLs are ignored
to prevent open redirects, and the user falls back to the site root.

```twig
<a href="{{ actionUrl('craft-moneybird/auth/connect', { redirect: '/dashboard' }) }}">
  Connect Moneybird
</a>
```

To disconnect, POST to the disconnect action (requires a logged-in user):

```twig
<form method="post" action="{{ actionUrl('craft-moneybird/auth/disconnect') }}">
  {{ csrfInput() }}
  <button type="submit">Disconnect Moneybird</button>
</form>
```

## Using the API

Once a user is connected, call the services with their Craft user ID. Token
resolution and refresh happen for you.

```php
use larsmarkusstudio\moneybird\Plugin;

$moneybird = Plugin::getInstance();
$userId = Craft::$app->getUser()->getId();

// Find or create a contact
$contact = $moneybird->contacts->findOrCreate($userId, 'Acme BV');

// Create a receipt with one line
use larsmarkusstudio\moneybird\models\ReceiptLine;

$line = new ReceiptLine();
$line->description = 'Mileage — June';
$line->price = '42.00';

$receipt = $moneybird->documents->createReceipt(
    userId: $userId,
    contactId: $contact->id,
    date: '2026-06-25',
    reference: 'INV-001',
    lines: [$line],
);

// Attach a PDF
$moneybird->documents->attachPdf($userId, $receipt->id, $pdfBytes, 'receipt.pdf');
```

### Services

| Service | Access | Responsibility |
|---|---|---|
| `AuthService` | `$plugin->auth` | OAuth flow, encrypted token storage, refresh, identity resolution |
| `ApiClient` | `$plugin->api` | Authenticated, administration-scoped requests with automatic 401 retry |
| `ContactsService` | `$plugin->contacts` | Find / search / create Moneybird contacts |
| `DocumentsService` | `$plugin->documents` | Create receipts and attach PDFs |

> Moneybird's API is rate-limited to 150 requests per 5 minutes per
> administration. Keep calls minimal and cache where you can.

## Events

```php
use yii\base\Event;
use larsmarkusstudio\moneybird\controllers\AuthController;
use larsmarkusstudio\moneybird\events\OAuthConnectedEvent;

Event::on(
    AuthController::class,
    AuthController::EVENT_OAUTH_CONNECTED,
    function (OAuthConnectedEvent $event) {
        // $event->user, $event->administrationId, $event->isNewUser, ...
    },
);
```

| Event | Fired when |
|---|---|
| `AuthController::EVENT_OAUTH_CONNECTED` | A user finishes connecting and tokens are stored |
| `DocumentsService::EVENT_RECEIPT_CREATED` | A receipt is successfully created in Moneybird |

## Support

Found a bug or have a question? Open an issue on
[GitHub](https://github.com/larsmarkus-studio/craft-moneybird/issues).

## Licence

MIT — see [LICENSE.md](LICENSE.md). Built by [Lars Markus](https://larsmarkus.studio)
for [Flexsheet](https://flexsheet.eu).
