# Connect Core Spec

Status: release candidate

## Problem

Platform packages need one small, reviewed implementation of the connection protocol instead of rewriting OAuth, request signing, resource exposure, and payload safety rules.

## Goals

- Provide framework-free protocol primitives.
- Keep security-sensitive behavior centralized and tested.
- Expose no private server implementation details.
- Make empty resource declarations expose nothing.

## Non-Goals

- No Laravel, Filament, WordPress, Shopify, database, route, controller, or UI integration.
- No production endpoints or private server behavior.
- No token-paste credential setup.

## Domain Concepts

- PKCE pair: verifier and S256 challenge.
- OAuth state: one-time browser transaction state stored by hash with expiry.
- Redirect URI: exact callback URL expected by the setup flow.
- Signed request: canonical method, path, normalized query, timestamp, nonce, body hash, and installation id.
- Resource schema: explicit fields, grants, and named actions.
- Capability schema: source-neutral business operations derived from explicit resource grants.
- Public payload: browser-safe data with no secret-shaped keys.

## Public Contracts

The package exposes immutable value objects, request builders, a signed request verifier, a nonce-store port, resource schema rules, capability descriptors, and payload safety guards.

## Security Model

PKCE uses S256 only. OAuth state is compared by hash. Redirect URI comparison is exact. Request signatures cover query strings. Nonce replay protection is delegated to an atomic `NonceStore`. Public payloads fail closed when secret-shaped keys appear.

## Infrastructure Boundaries

Framework packages provide storage, HTTP clients, encrypted persistence, caches, and admin UI.

## Optional authenticated request context

`SignedRequestContext` authenticates an opaque non-empty payload of at most 4096
bytes without changing the original request body or main signature. This permits
an older receiver to continue validating the unchanged request. A receiver must
first verify the main signature, timestamp, origin when applicable, and nonce;
the extension alone is not a request verifier.

`X-Tropikal-Connect-Context` contains canonical unpadded base64url of the payload.
`X-Tropikal-Connect-Context-Signature` is lowercase hex HMAC-SHA256, using the
same trimmed signing secret, of `connect-context.v1`, the exact main signature,
and encoded context joined with two LF bytes. Verification accepts HTTP header
names case-insensitively. Both absent means no extension; partial, malformed,
oversized, noncanonical, or invalid proof is an error, never a downgrade.

The payload schema, sensitivity, authorization, and whether absence is permitted
belong to the consuming protocol. Consumers must redact both headers and must
not place private extensions in public/browser/cache responses. An authenticated
extension cannot grant authority by itself; each bounded context still enforces
its own ownership and capability checks. Removal of both headers must not grant
access to a resource that requires extension-derived authority.

Cross-language vector: secret `fixture-secret`, main signature 64 lowercase `a`
characters, payload `{"v":1}` gives context `eyJ2IjoxfQ` and proof
`9d86dde167a763b2f2e4d3a115c8dcdbbf77713882d599409ed6112d5c3012b4`.

## Test Plan

Unit tests cover OAuth helpers, token payloads, request signing, replay rejection, capability descriptors, resource projection, write validation, named action grants, payload safety, and framework-free boundaries.
