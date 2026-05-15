# TROPIKAL Connect

Framework-agnostic protocol primitives for platform packages that implement TROPIKAL Connect.

This package contains OAuth PKCE helpers, signed request verification, resource schema rules, and browser payload safety checks. It does not contain Laravel, Filament, WordPress, Shopify, storage, routes, controllers, views, or private server behavior.

## Install

```bash
composer require tropikal-ai/connect
```

## Security Model

- OAuth setup uses authorization code with PKCE S256.
- OAuth state is stored and compared by hash.
- Signed requests cover method, path, normalized query, timestamp, nonce, installation id, and body hash.
- Replay protection is provided by a host-owned atomic `NonceStore`.
- Resource reads and writes use explicitly declared fields only.
- Browser/public payloads reject secret-shaped keys.

## Example

```php
use TropikalAI\Connect\Domain\OAuth\PkcePair;

$pkce = PkcePair::generate();
```

Use `example.com` hosts in tests and docs. Platform packages own real endpoints and storage.
