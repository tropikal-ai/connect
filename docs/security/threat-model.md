# TROPIKAL Connect Core Threat Model

Status: release-candidate review

## Scope

This document covers `tropikal-ai/connect`, the framework-free protocol package. It does not cover Laravel persistence, Filament UI, private server implementation, customer infrastructure, or production operations.

## Assets

- OAuth state values and PKCE verifiers.
- OAuth access and refresh credentials handled by host packages.
- Server-to-server signing credentials handled by host packages.
- Installation identifiers.
- Resource and capability schemas.
- Browser/public payloads derived from schemas.

## Trust Boundaries

- Browser to platform package: untrusted.
- Platform package to authorization server: trusted only over HTTPS, with local HTTP allowed for localhost development.
- Platform package to private control plane: trusted only over HTTPS, with local HTTP allowed for localhost development.
- Private control plane to platform package resource API: trusted only after signed request verification.
- Host application models and databases: trusted as application-owned infrastructure, but exposed fields are untrusted until explicitly declared safe.

## Threats And Mitigations

| Threat | Mitigation |
| --- | --- |
| OAuth callback forgery | State is random, stored as a hash, expires, and must match exactly. |
| Authorization code interception | PKCE S256 is required for authorization-code setup. |
| Redirect URI confusion | Redirect URI comparison is exact. |
| Request replay | Signed requests include timestamp and nonce; host `NonceStore` must claim nonces atomically. |
| Query tampering | Signatures include the normalized query string. |
| Body tampering | Signatures include a SHA-256 body hash. |
| Installation confusion | Signatures include installation id and verifier checks expected id. |
| Secret exposure in schemas | Resource and capability descriptors reject secret-shaped keys. |
| Secret exposure in browser payloads | Public payload guard rejects secret-shaped keys recursively and server-only bearer-shaped values. |
| Overbroad reads | Read projection returns only declared readable fields plus the identifier. |
| Overbroad writes | Write validation accepts only declared writable fields. |
| Unexpected destructive access | Destructive operations require explicit descriptors and grants; write does not imply delete. |

## Security Invariants

- Empty grants expose nothing.
- Write does not imply delete.
- Destructive operations must be explicit and confirmation-aware in host packages.
- Named actions require explicit grants.
- Host packages must keep credentials server-side.
- Browser/public payloads must pass `SensitiveData::assertPublicPayload`.
- Host nonce stores must be atomic; non-atomic stores are insecure.

## Residual Risks

- The core cannot prove a host nonce store is atomic; host packages must test that behavior.
- The core cannot verify OAuth server identity beyond exact URLs supplied by the host.
- Schema safety depends on platform packages using descriptors before reading or writing application data.
- Public release should still receive external security review before a stable tag.
