# Release Notes for Moneybird

## 0.2.2

### Fixed
- `AuthService::setActiveAdministration()` no longer leaves the user with no active administration
  when asked to activate the one that is already active.

## 0.2.1

### Fixed
- The OAuth flow now logs the user in for `rememberedUserSessionDuration` (falling back to
  `userSessionDuration`) instead of the current PHP session only, so an identity cookie is
  issued and returning users are no longer sent through Moneybird's login screen every session.

## Unreleased

### Added
- Moneybird OAuth 2.0 login flow (connect, callback, administration/user pickers, disconnect).
- Encrypted storage of access and refresh tokens, with automatic refresh on expiry.
- `AuthService`, `ApiClient`, `ContactsService` and `DocumentsService`.
- Typed models: `Administration`, `Contact`, `Identity`, `MoneybirdUser`, `Receipt`, `ReceiptLine`.
- `OAuthConnected` and `ReceiptCreated` events.
- `?redirect=` parameter on the connect action to control the post-flow landing URL.
- Plugin settings screen for OAuth credentials (referenced from environment variables).
- Multiple administrations per Craft user: one token row per administration, with
  `AuthService::getConnectedAdministrations()` / `setActiveAdministration()` to switch the active one.
