# Release Notes for Moneybird

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
