# Deploy — api-ardoise.dawzss.com (Hostinger Cloud Startup)

Guía para publicar esta API Laravel en Hostinger (hPanel + SSH + cron).
**No hay supervisor/daemon de colas**: la cola se drena con `schedule:run` cada minuto.

Dominio API: `https://api-ardoise.dawzss.com`

---

## a. PHP Configuration (hPanel)

1. Entra a **hPanel → Advanced → PHP Configuration** (o *PHP Settings*).
2. Selecciona **PHP 8.4** (recomendado) o **PHP 8.3** como mínimo.
3. Activa estas extensiones:
   - `pdo_mysql`
   - `mbstring`
   - `openssl`
   - `curl`
   - `fileinfo`
   - `gd`
4. Guarda y aplica al dominio/subdominio `api-ardoise.dawzss.com`.

> Si el hosting solo ofrece PHP 8.3 y Composer arrastra Symfony 8 (PHP 8.4),
> usa `composer.json` con `"platform": { "php": "8.3.30" }` (ya incluido) o
> fija dependencias compatibles. Ver nota del proyecto sobre Laravel 13 + PHP 8.3.

---

## b. Base de datos MySQL

1. **hPanel → Databases → MySQL Databases**.
2. Crea una base y un usuario (Hostinger usa prefijos tipo `u123456789_ardoise`).
3. Anota:
   - Host (normalmente `127.0.0.1` o `localhost`)
   - Database name
   - Username
   - Password
4. Esas credenciales van en `.env` (`DB_*`).

---

## c. Estructura de archivos y document root

Sube el proyecto **completo** a:

```text
/home/USUARIO/domains/api-ardoise.dawzss.com/
```

(vía **hPanel → Git** o `git clone` por SSH). Idealmente **fuera** de un `public_html` suelto, con la carpeta `public/` de Laravel como document root.

### OPCIÓN A (preferida)

Si el plan permite cambiar el document root del subdominio:

1. En hPanel → **Domains / Subdomains** → `api-ardoise.dawzss.com`.
2. Apunta el document root a:

```text
/home/USUARIO/domains/api-ardoise.dawzss.com/public
```

3. Listo: Laravel sirve desde `public/` y el `.htaccess` de la raíz niega acceso si alguien apunta mal.

### OPCIÓN B (si no puedes cambiar el document root)

Cuando el subdominio solo puede servir desde `public_html`:

1. Clona/sube la app en una carpeta hermana, por ejemplo:

```text
/home/USUARIO/domains/api-ardoise.dawzss.com/app-ardoise/   ← código Laravel
/home/USUARIO/domains/api-ardoise.dawzss.com/public_html/   ← document root
```

2. Vacía `public_html/` (backup si hace falta).
3. Copia el **contenido** de `app-ardoise/public/` dentro de `public_html/`.
4. Edita `public_html/index.php` para apuntar a la carpeta de la app:

```php
require __DIR__.'/../app-ardoise/vendor/autoload.php';

$app = require_once __DIR__.'/../app-ardoise/bootstrap/app.php';
```

(Ajusta `app-ardoise` al nombre real de la carpeta.)

5. Asegura permisos de escritura en `storage/` y `bootstrap/cache/` de la app.

---

## d. Comandos por SSH

Conéctate por SSH y sitúate en la raíz del proyecto Laravel (donde está `artisan`):

```bash
cd ~/domains/api-ardoise.dawzss.com
# o: cd ~/domains/api-ardoise.dawzss.com/app-ardoise   (opción B)

composer install --no-dev --optimize-autoloader

cp .env.production.example .env
# Edita .env con nano/vim: APP_KEY vacío, DB_*, Stripe live, CORS, etc.

php artisan key:generate
php artisan migrate --force
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Permisos típicos (ajusta usuario/grupo si Hostinger lo indica):

```bash
chmod -R ug+rwx storage bootstrap/cache
```

Tras cambiar `.env` o rutas, vuelve a cachear:

```bash
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

---

## e. Cron jobs (hPanel)

**SIN supervisor.** Un solo cron cada minuto ejecuta el scheduler; el schedule
drena la cola `database` con `queue:work --stop-when-empty`.

En **hPanel → Advanced → Cron Jobs**:

| Campo | Valor |
| --- | --- |
| Schedule | Every minute (`* * * * *`) |
| Command | ver abajo |

```bash
php /home/USUARIO/domains/api-ardoise.dawzss.com/artisan schedule:run >> /dev/null 2>&1
```

Sustituye `USUARIO` por tu usuario de Hostinger. En opción B, usa la ruta
completa hasta el `artisan` de la carpeta de la app.

Registrado en `routes/console.php`:

```php
Schedule::command('queue:work database --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
```

Verifica:

```bash
php artisan schedule:list
```

---

## f. Webhook de Stripe (live)

1. [Stripe Dashboard](https://dashboard.stripe.com) → modo **Live**.
2. **Developers → Webhooks → Add endpoint**.
3. URL:

```text
https://api-ardoise.dawzss.com/api/stripe/webhook
```

4. Eventos mínimos a seleccionar:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
5. Copia el **Signing secret** (`whsec_...`) a `.env` → `STRIPE_WEBHOOK_SECRET`.
6. Completa también `STRIPE_KEY` (`pk_live_...`) y `STRIPE_SECRET` (`sk_live_...`).
7. Recachea config:

```bash
php artisan config:cache
```

---

## g. Checklist post-deploy

- [ ] `GET https://api-ardoise.dawzss.com/api/health` → **200** JSON `success: true`
- [ ] `POST /api/auth/login` con usuario válido → **200** + `token` Bearer
- [ ] 6to login fallido con mismo email → **429** (`Too many requests...`)
- [ ] Stripe → “Send test webhook” al endpoint → **200**
- [ ] En el servidor: `grep APP_DEBUG .env` → `APP_DEBUG=false`
- [ ] CORS: el frontend en `https://ardoise.dawzss.com` puede llamar la API (preflight OK)
- [ ] `php artisan schedule:list` muestra el `queue:work` cada minuto

---

## Notas rápidas

- Auth: solo **Bearer tokens** (Sanctum). Sin cookies / sin `statefulApi`.
- Colas: `QUEUE_CONNECTION=database` + cron. Jobs como side effects de Stripe
  se procesan en el minuto siguiente como máximo.
- Logs: `storage/logs/laravel-YYYY-MM-DD.log` (`LOG_CHANNEL=daily`, `LOG_LEVEL=error`).
- Tras deploy, no dejes `APP_DEBUG=true` ni llaves `pk_test_` / `sk_test_` en producción.
