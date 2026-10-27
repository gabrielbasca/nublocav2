# NuBloca

NuBloca is an Android-and-web service for contacting a vehicle owner by license plate using predefined messages and replies.

## Monorepo

- `backend/` — Laravel REST API, Sanctum authentication, MySQL migrations, and Filament admin panel.
- `mobile/` — Android Flutter application (to be initialized when Flutter tooling is available).
- `infrastructure/` — deployment configuration and operational setup.
- `docs/` — setup and implementation notes.

## Backend quick start

See [docs/backend-setup.md](docs/backend-setup.md) for requirements, local setup, API endpoints, and admin access.
