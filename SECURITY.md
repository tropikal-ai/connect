# Security

`tropikal-ai/connect` is the framework-free protocol package. It does not contain production endpoints, storage, UI code, credentials, or private server behavior.

## Supported Versions

Security fixes target the latest released minor version. Before the first public tag, fixes target the default development branch.

## Reporting

Report vulnerabilities privately through the repository security advisory flow or the maintainer contact published with the package. Do not include production credentials, customer data, access tokens, refresh tokens, signing credentials, or private endpoint details in public reports.

## Security Expectations

- OAuth authorization code with PKCE S256 is the only setup primitive.
- Redirect URI comparison is exact.
- Signed requests include method, path, normalized query string, timestamp, nonce, installation id, and body hash.
- Nonce replay protection must be atomic in the host implementation.
- Resource declarations and browser/public payloads reject secret-shaped keys.
- Empty grants expose nothing.
- Reads and writes are limited to explicitly declared fields.
- Browser payloads must never contain credentials.
