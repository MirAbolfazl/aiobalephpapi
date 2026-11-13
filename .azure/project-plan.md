# aiobalephpapi project plan

## Status

**Approved by the user on 2026-11-13.** Application scaffolding may proceed.

## Goal

Create a production-oriented Laravel HTTP API in
`/Users/abolfazlebrahimi/Documents/aiobalephpapi` that exposes the supported
operations of the existing `aiobalephp` Bale client. Persist API users, API
credentials, linked Bale accounts, webhook subscriptions/deliveries, and media
metadata in MySQL. Keep the existing PHP client as the protocol implementation;
do not duplicate or rewrite it.

## Approved requirements

- Language and framework: PHP with Laravel.
- Database: MySQL.
- Caller authentication: Bearer tokens.
- Bale operation coverage: all operations registered by the existing client.
- Local-first development and execution; no cloud deployment or IaC in this scope.
- Include file/media handling, webhooks, and an admin dashboard.
- Publish an OpenAPI specification and Swagger UI.
- No OpenAI-compatible endpoints.

## Architecture

- Laravel application with versioned `/api/v1` routes and consistent JSON error
  responses.
- Use Laravel Sanctum personal access tokens for API callers. Hash tokens at rest,
  support revocation and abilities, and rate-limit authentication and mutation
  endpoints.
- Support multiple Bale accounts per API user. Persist Bale session tokens
  encrypted at rest in MySQL; never return or log these tokens.
- Integrate the existing `aiobalephp` package through a local Composer path
  repository so the API does not fork or duplicate its protocol code. Provide a
  token-scoped gateway for database-backed sessions; add the smallest required
  client integration so an API request can execute with an account token without
  writing session tokens to per-account files or opening an unnecessary WebSocket.
- Generate generic Bale-operation routes only from the existing
  `MethodRegistry`. Validate method names and payloads against registered method
  schemas; never instantiate arbitrary classes from HTTP input. Implement
  authentication lifecycle routes separately so phone challenges, codes, and
  passwords are validated and short-lived.
- Add an account event worker using the client's WebSocket/router support. Queue
  webhook deliveries in MySQL, sign outgoing requests with per-webhook secrets,
  retry transient failures with bounded backoff, and expose delivery status to
  the owner.
- Store uploaded media in Laravel's private local filesystem by default and keep
  metadata, ownership, and retention state in MySQL. Enforce size/type limits;
  support streaming downloads through authenticated routes.
- Use a Filament admin panel for API users, linked Bale accounts, tokens,
  webhooks/delivery attempts, and media metadata. Make the dashboard responsive
  and Persian/RTL-first, with English localization available.
- Provide OpenAPI documentation and an interactive Swagger UI for all public
  endpoints.

## Initial API surface

- `POST /api/v1/auth/register`, `POST /api/v1/auth/login`,
  `POST /api/v1/auth/logout`, and `GET /api/v1/auth/me`.
- `POST /api/v1/bale/auth/start`, `POST /api/v1/bale/auth/verify-code`,
  `POST /api/v1/bale/auth/verify-password`, and `POST /api/v1/bale/auth/sign-up`;
  link a resulting Bale session to the authenticated API user without exposing
  the session token. Preserve the upstream sign-up response contract rather than
  assuming that it returns a JWT.
- `POST /api/v1/bale/accounts/{account}/sign-out` revokes the linked Bale
  session and removes its encrypted credential after successful upstream
  sign-out.
- `GET /api/v1/bale/methods` lists every supported operation and its schema;
  authentication lifecycle operations map to their dedicated routes below
  rather than being invoked through the generic route.
- `POST /api/v1/bale/accounts/{account}/methods/{method}` invokes a registered
  non-authentication operation for an account owned by the caller.
- Account CRUD/listing, webhook subscription CRUD, authenticated media
  upload/download, health/readiness endpoints, and webhook-delivery history.
- Document any upstream operation whose response shape or behavior is not
  supported reliably by the source client rather than inventing a success
  response.

## Data model

- `users`, Sanctum `personal_access_tokens`.
- `bale_accounts` with encrypted credentials and owner association.
- `bale_auth_challenges` with encrypted short-lived transaction hashes and
  expiration.
- `webhook_endpoints`, `webhook_events`/deliveries, and retry metadata.
- `media_assets` with owner, disk/path, MIME type, size, and lifecycle metadata.
- `audit_logs` with redaction for credentials, OTPs, and message content.

## Local developer experience

- Include `.env.example`, MySQL setup instructions, migrations, safe local
  bootstrap steps, and commands for serving the API, running database-backed
  queue workers, and starting the Bale event worker.
- Persian README with English CLI output and documented cURL examples.
- Keep secrets out of source control; never seed a default administrator
  password or reusable API token.

## Acceptance criteria

- A clean local install can migrate MySQL and start the HTTP API, admin panel,
  queue worker, and Bale event worker using documented commands.
- Unauthenticated requests cannot access protected routes; token revocation,
  ownership boundaries, and per-method validation are covered by tests.
- API callers can add a Bale account using the OTP/two-step flow and invoke every
  registered supported operation through the generated method catalog.
- Webhook payloads are signed, retried on transient failures, and visible in the
  owner's delivery history.
- Upload/download flows enforce ownership and limits and do not expose private
  files through public paths.
- OpenAPI output validates and accurately describes authentication, request
  schemas, and error responses.
- Automated tests cover auth, account isolation, method validation/invocation,
  media access, webhook signatures/retries, and worker event routing.

## Out of scope

- Azure provisioning, cloud deployment, or a public production release.
- OpenAI-compatible endpoints.
- Reimplementing the Bale protocol or claiming support for operations absent from
  the current PHP client's registry.
