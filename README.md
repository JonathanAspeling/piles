# Piles!

A real-time multiplayer web adaptation of the fast-paced card game [Piles!](https://foxmind.co.il/) by Lost Boy Entertainment. 2–7 players race simultaneously to sort their piles into matching clothing sets.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.2 |
| Real-time | Laravel Reverb (WebSockets) |
| Frontend | Vue 3, Inertia.js v2, Pinia |
| Styling | Tailwind CSS v3 |
| Testing | Pest v3 |
| Build | Vite 6, TypeScript |

## Prerequisites

- PHP 8.2+
- Node.js 22+
- Composer (via [Laravel Herd](https://herd.laravel.com/) or standalone)
- Laravel Herd (recommended for local development — serves the app at `https://piles.test`)

## Local Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Start all services (Laravel, Vite, Reverb, queue worker):

```bash
composer run dev
```

Or start them individually:

```bash
php artisan serve          # Laravel
php artisan reverb:start   # WebSocket server
php artisan queue:work     # Queue worker
npm run dev                # Vite
```

## Running Tests

```bash
php artisan test --compact
```

Filter to a specific test:

```bash
php artisan test --compact --filter=GameSessionTest
```

## Deployment

The app is deployed via [Coolify](https://coolify.io/) to a Raspberry Pi.

### Required Environment Variables

```
APP_KEY=
APP_URL=

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=        # public hostname for the Reverb server
REVERB_PORT=8080
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Reverb Process

Reverb runs as a separate long-lived process alongside Laravel. Configure it as a separate service or worker in Coolify:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## Game Rules

See [DESIGN.md](DESIGN.md#game-rules) for a full rules reference.
