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
| `DELETE` | `/api/vehicles/{vehicle}` | Delete a vehicle without conversation history |
| `GET` | `/api/message-templates` | List active predefined message templates |
| `POST` | `/api/contact` | Start or continue a conversation using a contact template |
| `GET` | `/api/conversations` | List conversations for either participant |
| `GET` | `/api/conversations/{conversation}` | Read conversation history |
| `POST` | `/api/conversations/{conversation}/messages` | Send a predefined message or reply |
| `POST`, `DELETE` | `/api/device-tokens` | Register or remove an Android FCM device token |

Authenticated routes require `Authorization: Bearer \`<token>\``. Vehicle plates are uppercased, transliterated to ASCII, and stripped of spaces and punctuation before storage and lookup. Conversation messages store the selected template ID and a text snapshot; callers cannot submit arbitrary message text. Contact, message, and authentication endpoints have rate limits.

`php artisan db:seed` adds a small Romanian-language starter set of contact and reply templates. Admins can access the Filament panel at `/admin`; only users with `is_admin = true` can log in. To grant admin access to a deliberately selected registered account, run `php artisan tinker` and execute `$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail(); $user->forceFill(['is_admin' => true])->save();`. Never seed a default admin password.

## Boundaries for this setup

The API persists Android device tokens but does not yet send Firebase Cloud Messaging pushes. Nginx virtual hosts, TLS, FCM credentials, production secrets, and the Flutter application are not configured here.
