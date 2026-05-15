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

## Test Plan

Unit tests cover OAuth helpers, token payloads, request signing, replay rejection, capability descriptors, resource projection, write validation, named action grants, payload safety, and framework-free boundaries.
