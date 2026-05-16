# Contributing

Keep changes small, tested, framework-free, and aligned with `docs/specs/connect-core.md`.

## Development

```bash
composer validate --strict
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/phpunit --colors=never
```

## Rules

- Add or update tests before changing protocol behavior.
- Keep security-sensitive primitives centralized.
- Do not add Laravel, Filament, database, route, controller, view, storage, HTTP client, WordPress, or Shopify code.
- Do not add production URLs or private server behavior.
- Prefer explicit value objects and clear names over comments.
- Keep browser/public payload behavior fail-closed.
