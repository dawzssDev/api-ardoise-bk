# api-ardoise-bk

Backend API puro en **Laravel 13** + **Sanctum** (tokens Bearer) + **Stripe** (`stripe/stripe-php`).
Pensado para ser consumido por un frontend React. Sin Blade de negocio, sin Livewire, sin Inertia, sin Cashier.

- Dominio de producción (ejemplo): `https://api-ardoise.dawzss.com`
- Respuestas JSON estándar: `{ success, message, data, errors }`
- Guía de publicación en Hostinger: ver [DEPLOY.md](./DEPLOY.md)

## Requisitos

- PHP **8.3+** (recomendado 8.4 en Hostinger)
- Extensiones: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`
- Composer 2
- MySQL 8
- Node/npm solo si vas a tocar assets Vite (esta API no los necesita en runtime)

## Arranque local

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configura DB_* en .env (MySQL local: api_ardoise)
php artisan migrate
php artisan serve
```

Health check: `GET http://localhost:8000/api/health`

## Clonar como base para un proyecto nuevo

1. Clona o copia este repositorio.
2. Ajusta identidad y entorno en `.env` / `.env.production.example`:
   - `APP_NAME`
   - `APP_URL` (URL pública de la API)
   - `FRONTEND_URL`
   - `CORS_ALLOWED_ORIGINS` (orígenes del frontend, separados por coma)
   - `DB_*` (credenciales MySQL del nuevo hosting)
   - `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CURRENCY`
3. Regenera la key: `php artisan key:generate`
4. Corre migraciones: `php artisan migrate`
5. Actualiza el endpoint del webhook en el Dashboard de Stripe.
6. Sigue [DEPLOY.md](./DEPLOY.md) si publicas en Hostinger.

## Autenticación (Bearer)

```js
fetch(url, {
  headers: {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  },
})
// Sin credentials: 'include' — esta API no usa cookies.
```

## Rate limits

Definidos en `app/Providers/RateLimitServiceProvider.php` (no en las rutas).

| Limiter | Uso | Límite | Llave |
| --- | --- | --- | --- |
| `auth` | `POST /api/auth/login` | 5 / min | email + IP |
| `register` | `POST /api/auth/register` | 5 / min | IP |
| `password-reset` | forgot / reset password | 5 / min | IP |
| `api` | rutas `auth:sanctum` (me, logout, payments, subscriptions…) | 60 / min | user id o IP |
| `webhooks` | `POST /api/stripe/webhook` | 120 / min | IP |

## Endpoints principales

| Método | Ruta | Auth |
| --- | --- | --- |
| GET | `/api/health` | pública |
| POST | `/api/auth/register` | pública |
| POST | `/api/auth/login` | pública |
| POST | `/api/auth/forgot-password` | pública |
| POST | `/api/auth/reset-password` | pública |
| POST | `/api/auth/logout` | Bearer |
| GET | `/api/auth/me` | Bearer |
| POST | `/api/payments/intent` | Bearer |
| GET | `/api/payments` | Bearer |
| GET | `/api/subscriptions/plans` | Bearer |
| POST | `/api/subscriptions` | Bearer |
| GET | `/api/subscriptions` | Bearer |
| DELETE | `/api/subscriptions/{stripeSubscriptionId}` | Bearer |
| POST | `/api/stripe/webhook` | firma Stripe |

## Tests

```bash
php artisan test
```

## Licencia

MIT (skeleton Laravel + código de aplicación del proyecto).
