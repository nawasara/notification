# Nawasara Notification

Unified outbound notification for the Nawasara superapp framework. Build a Blade template once, then send it to anyone over any registered channel via a single fluent API. Every send is queued, retried with backoff on failure, and recorded in an audit log.

## Features

- **`Notify` facade** — fluent, chainable: `Notify::to($user)->template('ssl.expiry')->data([...])->send()`
- **Email channel (MVP)** — sends via Laravel's Mail facade. Credentials read from the `smtp` Vault group with a fallback to `.env` (`MAIL_*`)
- **Template manager** — Blade-rendered subject and body, multi-channel bodies (HTML / text / WhatsApp / Telegram / in-app), priority and active flag
- **Live preview** — render any template against arbitrary JSON variables in an iframe before saving
- **Test send** — kick off a real send to your own email from the template list to verify rendering and delivery end-to-end
- **Audit log** — every send is one row in `nawasara_notification_logs` with status (queued / sending / sent / delivered / failed / bounced), error trace, attempts, and rendered body
- **Retry from UI** — failed and bounced logs can be re-dispatched in one click
- **Future channels** — WhatsApp, Telegram, and in-app are stubbed in the schema and contract; only `EmailChannel` is implemented in the MVP

## Installation

```bash
composer require nawasara/notification
php artisan migrate
php artisan db:seed --class="Nawasara\Notification\Database\Seeders\PermissionSeeder" --force
```

Auto-discovered. The `Notify` facade is registered as an alias.

## SMTP credentials

The package reads SMTP credentials from the **`smtp` Vault group** at send time. Open `/nawasara-vault/credentials` → SMTP Email → fill in:

| Field | Example |
|-------|---------|
| Host | `smtp.gmail.com` |
| Port | `587` |
| Encryption | `tls` |
| Username | `noreply@kominfo.go.id` |
| Password | (Gmail app password if 2FA is on) |
| From Address | `noreply@kominfo.go.id` |
| From Name | `Nawasara Kominfo` |

Use **Test Connection** in the dropdown to verify the host is reachable. If Vault is empty, the channel falls back to whatever Laravel resolves from `.env` — useful in `local` where `MAIL_MAILER=log` writes the rendered email to `storage/logs/laravel.log`.

## Sending notifications

### Template-driven

```php
use Nawasara\Notification\Facades\Notify;

Notify::to($user)
    ->template('ssl.expiry.warning')
    ->data(['domain' => 'dinkes.ponorogo.go.id', 'days' => 7])
    ->send();
```

### Ad-hoc

```php
Notify::to('admin@kominfo.go.id')
    ->channel('email')
    ->subject('Test')
    ->body('<p>Hello world</p>')
    ->send();
```

### Synchronous (skip queue)

Useful for tests and admin actions that need immediate feedback:

```php
Notify::to($user)->template('welcome')->data([...])->sync()->send();
```

## Pages

| Route | Permission |
|-------|-----------|
| `/nawasara-notification/templates` | `notification.template.view` |
| `/nawasara-notification/logs` | `notification.log.view` |

## Permissions

| Permission | Description |
|---|---|
| `notification.template.view` | View template list |
| `notification.template.create` | Create template |
| `notification.template.update` | Update template |
| `notification.template.delete` | Delete template |
| `notification.log.view` | View notification log |
| `notification.log.retry` | Retry a failed/bounced notification |
| `notification.test.send` | Trigger a Test Send from the template UI |
| `notification.broadcast.send` | Broadcast (planned for future) |

## Roadmap

- WhatsApp channel via `nawasara/whatsapp-forwarder`
- Telegram bot channel
- In-app notification bell in topbar
- Webhook delivery callbacks (open/click tracking, bounce handling)
- User notification preferences (per-channel, quiet hours)
- Template broadcast (blast to role / OPD / custom audience)

See [`docs/todo-notification.md`](../../docs/todo-notification.md) for the full plan.

## Author

**Pringgo J. Saputro** &lt;odyinggo@gmail.com&gt;

## License

MIT
