# Personal Budget API (Laravel)

Laravel API for the Personal Budget application.

## Prerequisites

- PHP 8.2+
- Composer
- Node.js + npm (for Vite assets)

## Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
```

Notes:

- The default database connection in `.env.example` is SQLite.
- If you prefer MySQL/Postgres, update the `DB_*` values in `.env` before running migrations.

## Run (development)

Run everything (API server, queue, logs, Vite) with a single command:

```bash
composer run dev
```

Or run processes separately:

```bash
php artisan serve
npm run dev
```

## Tests

```bash
composer test
```

## Authentication

This API uses Laravel Sanctum personal access tokens (Bearer tokens).

- `POST /api/register` returns `{ token, token_type: "Bearer" }`
- `POST /api/login` returns `{ token, token_type: "Bearer" }`
- Send the token with: `Authorization: Bearer <token>`

Example:

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"password"}'
```

```bash
curl http://127.0.0.1:8000/api/profile \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer <token>'
```

## Routes (high level)

Base URL: `http://127.0.0.1:8000/api`

- Public auth
  - `POST /register`
  - `POST /login`
- Social auth
  - `GET /auth/google`
  - `POST /auth/google/callback`
  - `GET /auth/facebook`
  - `POST /auth/facebook/callback`
- Protected (requires `auth:sanctum`)
  - Profile & password
    - `GET /profile`
    - `PUT /profile`
    - `PUT /password`
    - `POST /logout`
    - `POST /logout-all`
  - Budgets
    - REST: `GET|POST /budgets`, `GET|PUT|PATCH|DELETE /budgets/{budget}`
    - Items: `POST /budgets/{budget}/items`, `PATCH|DELETE /budget-items/{budgetItem}`
  - Categories, expenses, incomes
    - REST: `GET|POST /categories|expenses|incomes`
    - REST: `GET|PUT|PATCH|DELETE /categories/{category}`
    - REST: `GET|PUT|PATCH|DELETE /expenses/{expense}`
    - REST: `GET|PUT|PATCH|DELETE /incomes/{income}`
  - Reports
    - `GET /reports/dashboard`
    - `GET /reports/current-month-budget-stats`
    - `GET /reports/monthly-summary`
    - `GET /reports/budget-vs-actual`
    - `GET /reports/spending-trends`

## API Collections & Examples

The repo root contains Postman collections and response examples:

- `API_Response_Samples.md`
- `*_API.postman_collection.json`

## OAuth (optional)

To enable Google/Facebook login, set these in `.env` (see `.env.example`):

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
- `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `FACEBOOK_REDIRECT_URI`
