# TROPIKAL Connect

Framework-agnostic protocol and public-channel application core for packages
that implement TROPIKAL Connect.

`tropikal-ai/connect` contains OAuth PKCE helpers, signed request creation and
verification, resource and capability schema rules, browser payload safety
checks, and the shared Website Chat/Booking application service. It has no
Laravel, Filament, WordPress, n2n, database, controller, view, or host storage
implementation.

## Requirements

- PHP 8.2 or newer
- Composer 2

## Install

```bash
composer require tropikal-ai/connect
```

For clone-based development, add a path repository to the application that consumes the package:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "shared/connect",
            "options": {
                "symlink": true,
                "versions": {
                    "tropikal-ai/connect": "0.1.0"
                }
            }
        }
    ],
    "require": {
        "tropikal-ai/connect": "^0.1"
    }
}
```

## What It Provides

- PKCE S256 verifier and challenge generation.
- OAuth state generation, hashing, expiry, and validation.
- Exact redirect URI matching.
- OAuth public client registration payloads.
- Authorization and token request payload builders.
- Token response validation and safe redaction.
- Canonical signed server-to-server requests.
- Constant-time signature verification.
- Atomic nonce replay protection through a host-owned port.
- Resource, field, operation, and capability descriptors.
- Explicit read projection and write validation.
- Named action grant validation.
- Recursive browser/public payload safety checks.
- Same-origin Chat and Booking application use cases and ports.
- Live Job-contract preflight for availability and booking payloads.
- Signed control-plane gateway and centralized human-verification adapter.
- Versioned public-channel JavaScript and CSS.

## Security Model

- OAuth authorization code with PKCE S256 is the only setup primitive.
- OAuth state is stored and compared by hash.
- Redirect URIs must match exactly.
- Signed requests cover method, path, normalized query string, timestamp, nonce, installation id, and body hash.
- Replay protection must be backed by an atomic `NonceStore` implementation in the host package.
- Empty resource grants expose nothing.
- Reads project declared fields only.
- Writes accept declared writable fields only.
- Write grants do not imply delete.
- Destructive operations must be explicit and confirmation-aware in host packages.
- Named actions require explicit grants.
- Secret-shaped keys are rejected in declarations and browser/public payloads.
- Browser payloads never include secrets by contract.

There is no token-paste setup path, no copied-secret setup path, and no browser-visible credential path in this package.

See [`docs/security/threat-model.md`](docs/security/threat-model.md) for the release-candidate threat model.

## Capability Example

```php
use TropikalAI\Connect\Domain\Resource\FieldDescriptor;
use TropikalAI\Connect\Domain\Resource\ResourceSchema;

$schema = new ResourceSchema(
    key: 'research_posts',
    label: 'Research Posts',
    identifier: 'id',
    fields: [
        new FieldDescriptor('title', 'Title', readable: true, writable: true),
        new FieldDescriptor('status', 'Status', readable: true, writable: true),
        new FieldDescriptor('published_at', 'Published at', readable: true, writable: false),
    ],
    grants: ['read', 'write'],
);

$publicRecord = $schema->project([
    'id' => 123,
    'title' => 'Example',
    'status' => 'draft',
    'internal_notes' => 'not exposed',
]);

$unknownFields = $schema->unknownWriteFields([
    'title' => 'Updated',
    'internal_notes' => 'rejected',
]);
```

## Private Server Boundary

Server and provider internals are intentionally absent. Host adapters provide
storage, encrypted persistence, HTTP transport, rate limiting, admin UI, route
registration, logging, and presentation placement.

Use `example.com` hosts in public documentation and tests.

## Troubleshooting

- Redirect validation failed: compare the configured callback URL byte-for-byte with the callback URL used during authorization.
- Signature validation failed: verify method, path, normalized query string, body hash, timestamp, nonce, installation id, and signing key.
- A replay error occurred: make sure the host `NonceStore` claims nonces atomically.
- A field is missing from output: only explicitly declared readable fields are projected.
- A write field is rejected: only explicitly declared writable fields are accepted.
