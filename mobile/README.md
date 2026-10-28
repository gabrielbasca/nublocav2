# NuBloca Android app

Phase 1 includes the Flutter app foundation, secure Sanctum authentication, persistent session restoration, login and registration screens, logout, and the authenticated home screen.

Phase 2 adds vehicle management and QR contact: list/create/edit/delete for the authenticated user's vehicles, vehicle details with a printable QR code, QR link sharing, and owner-only QR token rotation.

## Run and validate

From `mobile/`:

```sh
flutter pub get
flutter analyze
flutter test
```

The app continues to initialize Firebase in `lib/main.dart`. Authentication calls the existing Laravel API at `https://nubloca.clarfiscal.ro/api/`:

- `POST /login` and `POST /register` return `{ "token": "...", "user": { "id": 1, "name": "...", "email": "..." } }`.
- `GET /user` returns the authenticated user directly.
- `POST /logout` revokes the current Sanctum token and returns `204`.

The Sanctum bearer token is stored with `flutter_secure_storage`; it is never written to ordinary preferences or logged. A protected `401` clears the stored token and returns the user to login. The Android package ID and Firebase project configuration are unchanged.

## Vehicles and QR contact (Phase 2)

Vehicle screens live in `lib/features/vehicles/` (model, repository, Riverpod state, screens) and reuse the shared Dio client and auth session from `lib/core/providers.dart`. API endpoints used:

- `GET /vehicles` — list the authenticated user's vehicles.
- `POST /vehicles` — create (`license_plate`).
- `PUT /vehicles/{id}` — update (`license_plate`); the public QR token stays stable across edits.
- `DELETE /vehicles/{id}` — delete, with in-app confirmation.
- `POST /vehicles/{id}/rotate-public-token` — owner-only rotation for a compromised QR code; invalidates the old public URL.

Each vehicle carries a server-generated `public_token` (64 lowercase hex characters) and a `public_url` of the form `https://nubloca.clarfiscal.ro/v/{public_token}`. The app validates both strictly (HTTPS, exact host, exact `/v/{token}` path, token format) before rendering the QR code with `qr_flutter`; the details screen can also share the link via `share_plus`. The QR payload contains no user IDs, license plates, owner contact details, or auth tokens — only the public URL. Scanning it opens the Laravel Blade page at `/v/{public_token}`, where visitors can send the owner a predefined message without seeing any private data.

No APK build is run as part of this phase.
