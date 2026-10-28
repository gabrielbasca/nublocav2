# Backend setup

## Requirements

- PHP 8.3 with `intl`, `mbstring`, `pdo_mysql`, `xml`, and `zip`
- Composer
- MySQL 8

## Local setup

From `nubloca/backend`:

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Create a dedicated MySQL database and application user, then set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Do not use the MySQL root account for the application.

```sh
php artisan migrate --seed
php artisan test
php artisan serve
```

The `.env.example` database settings are placeholders. Keep real credentials out of source control.

## API

All API routes are under `/api` and return JSON.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/register` | Register and issue a Sanctum bearer token |
| `POST` | `/api/login` | Authenticate and issue a Sanctum bearer token |
| `POST` | `/api/logout` | Revoke the current bearer token |
| `GET` | `/api/user` | Return the authenticated user |
| `GET`, `POST` | `/api/vehicles` | List or add the current user's vehicles |
| `PUT`, `PATCH` | `/api/vehicles/{vehicle}` | Update an owned vehicle's license plate |
| `DELETE` | `/api/vehicles/{vehicle}` | Delete a vehicle without conversation history |
| `POST` | `/api/vehicles/{vehicle}/rotate-public-token` | Revoke and replace an owned vehicle's public QR token |
| `GET` | `/api/message-templates` | List active predefined message templates |
| `POST` | `/api/contact` | Start or continue a conversation using a contact template |
| `GET` | `/api/conversations` | List conversations for either participant |
| `GET` | `/api/conversations/{conversation}` | Read conversation history |
| `POST` | `/api/conversations/{conversation}/messages` | Send a predefined message or reply |
| `POST`, `DELETE` | `/api/device-tokens` | Register or remove an Android FCM device token |

Authenticated routes require `Authorization: Bearer \`<token>\``. Vehicle plates are uppercased, transliterated to ASCII, and stripped of spaces and punctuation before storage and lookup. Conversation messages store the selected template ID and a text snapshot; callers cannot submit arbitrary message text. Contact, message, and authentication endpoints have rate limits.

`php artisan db:seed` adds a small Romanian-language starter set of contact and reply templates. Admins can access the Filament panel at `/admin`; only users with `is_admin = true` can log in. To grant admin access to a deliberately selected registered account, run `php artisan tinker` and execute `$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail(); $user->forceFill(['is_admin' => true])->save();`. Never seed a default admin password.

## Firebase Cloud Messaging

`kreait/laravel-firebase` delivers Android FCM messages from the queued `SendPushNotification` job. Keep the existing Firebase service-account configuration intact and configured for project `nubloca-app`; no credentials are stored in this repository. Set `FIREBASE_HTTP_CLIENT_TIMEOUT=15` in the application environment. The job timeout is 30 seconds; ensure the persistent worker uses a timeout below the database queue's `retry_after` (90 seconds by default).

Contact requests and subsequent conversation messages dispatch one unique job per registered recipient device only after the message transaction commits. The sender's devices are excluded. Payload `data` always includes `type`, `title`, `body`, `route`, `notification_id`, and JSON `navigation_data`; conversation notifications also include `conversation_id`. Types are `contact_request`, `message_received`, and `account_event`. Account code can call `FirebaseNotificationService::queueAccountEvent()` with a stable event ID, a title/body, and navigation data.

The stable `notification_id` lets Android clients suppress duplicate presentation when a provider or queue retry creates an ambiguous delivery outcome. Clients should retain processed IDs for at least 24 hours. Queue jobs are unique per notification/device, retry transient provider failures up to five times with backoff, and remove invalid, unregistered, or sender-mismatched device tokens. Logs use token hashes and IDs only; raw tokens and Firebase credentials are never logged.

After changing queue or Firebase configuration, refresh the persistent worker (for example, `php artisan queue:restart`) so it reloads the environment. Tests mock the Firebase Messaging contract and do not contact Firebase.

## Boundaries for this setup

Production Nginx virtual hosts, TLS, and Firebase credentials are deployment-specific and are not configured here.

## Public vehicle QR contact

Each vehicle receives a cryptographically random 64-character hexadecimal `public_token`. The migration backfills existing vehicles before enforcing a unique, non-null token. The token remains stable during plate edits; an authenticated owner can rotate it using the API route above. Set `PUBLIC_VEHICLE_URL_PREFIX=https://nubloca.clarfiscal.ro/v` when deploying the backend. The API returns the resulting `public_url`, such as `https://nubloca.clarfiscal.ro/v/{public_token}`. Flutter renders and shares only that server-provided URL.

The public page is `GET /v/{public_token}`; its CSRF-protected form posts to the same URL. It offers active predefined contact templates only and does not display the plate or owner identity/contact details. Public submissions are limited by IP, use a honeypot field, and create guest conversations/messages with null sender IDs. The nullable sender foreign keys are required for unauthenticated messages in the existing conversation schema; existing authenticated flows remain unchanged. Owners receive the existing queued contact-request notification. Guest-started conversations are currently read-only through the authenticated reply API.
