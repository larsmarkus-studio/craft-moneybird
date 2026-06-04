# Moneybird for Craft CMS

A Craft CMS 5 plugin providing [Moneybird](https://www.moneybird.com) OAuth
authentication and API service classes. Designed as a general foundation — other
developers can build different Moneybird integrations on top of it.

It is the open-source base layer for [Tripsheet](https://tripsheet.nl), but has
no dependency on it.

## Requirements

- Craft CMS 5.6.0 or later (Pro edition, for user accounts)
- PHP 8.2 or later

## Installation

```bash
composer require larsmarkus-studio/craft-moneybird
./craft plugin/install craft-moneybird
```

## Configuration

Set the following environment variables and reference them from the plugin
settings (Settings → Moneybird in the control panel):

```bash
MONEYBIRD_CLIENT_ID=
MONEYBIRD_CLIENT_SECRET=
MONEYBIRD_REDIRECT_URI=
```

The full OAuth flow is built directly on
[`league/oauth2-client`](https://oauth2-client.thephpleague.com) — no other
OAuth plugin is required.

## Services

| Service | Responsibility |
|---|---|
| `MoneybirdAuthService` | OAuth flow, token refresh, identity resolution |
| `MoneybirdContactsService` | Find / search / create Moneybird contacts |
| `MoneybirdDocumentsService` | Create receipts, attach PDFs |

## Licence

MIT — see [LICENSE.md](LICENSE.md).
