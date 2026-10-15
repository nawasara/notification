# Nawasara Notification

Unified outbound notification for the Nawasara superapp framework. Build a template once, then send it to anyone over any registered channel via a single fluent API. Every send is queued, retried with backoff on failure, and recorded in an audit log.

## Features

- **`Notify` facade**: fluent, chainable: `Notify::to($user)->template('ssl.expiry')->data([...])->send()`
- **Email channel (MVP)**: sends via Laravel's Mail facade. Credentials read from the `smtp` Vault group with a fallback to `.env` (`MAIL_*`)
- **Template manager**: placeholder-rendered subject and body (never compiled as Blade, see below), multi-channel bodies (HTML / text / WhatsApp / Telegram / in-app), priority and active flag
- **Live preview**: render any template against arbitrary JSON variables in an iframe before saving
- **Test send**: kick off a real send to your own email from the template list to verify rendering and delivery end-to-end
- **Audit log**: every send is one row in `nawasara_notification_logs` with status (queued / sending / sent / delivered / failed / bounced), error trace, attempts, and rendered body
- **Retry from UI**: failed and bounced logs can be re-dispatched in one click
- **Telegram channel**: posts to a group, split by **forum topic** (`message_thread_id`). Credentials and topic ids live in the `telegram` Vault group. Alerts route by severity first: `critical` gets its own topic, because in an inbox 4 critical alerts look exactly like the 405 warnings around them
- **Future channels**: WhatsApp and in-app are stubbed in the schema and contract

## Installation

```bash
composer require nawasara/notification
php artisan migrate
php artisan db:seed --class="Nawasara\Notification\Database\Seeders\PermissionSeeder" --force
```

Auto-discovered. The `Notify` facade is registered as an alias.

## SMTP credentials

The package reads SMTP credentials from the **`smtp` Vault group** at send time. Open `/nawasara-vault/credentials`, choose SMTP Email, then fill in:

| Field | Example |
|-------|---------|
| Host | `smtp.gmail.com` |
| Port | `587` |
| Encryption | `tls` |
| Username | `noreply@kominfo.go.id` |
| Password | (Gmail app password if 2FA is on) |
| From Address | `noreply@kominfo.go.id` |
| From Name | `Nawasara Kominfo` |

Use **Test Connection** in the dropdown to verify the host is reachable. If Vault is empty, the channel falls back to whatever Laravel resolves from `.env`, which is handy in `local` where `MAIL_MAILER=log` writes the rendered email to `storage/logs/laravel.log`.

## Recipients are resolved per channel

`resolveRecipient()` picks the field that matches the channel: `email` for
email, `telegram_chat_id` for telegram. **Pass the user object**, not a string,
and every enabled channel finds its own address.

A string that does not match the channel's format is **rejected and logged**,
not passed through. Before this was enforced, any string went to any channel, so
turning on a new channel would quietly send email addresses to Telegram as "chat
ids", failing at the provider and landing in a log nobody reads. A channel that
silently sends nothing is worse than one that plainly refuses, because
everything appears to be working.

## Push (FCM)

Channel `fcm` sends to the Ponorogo Hub app through Firebase Cloud Messaging,
HTTP v1. Credentials are the Firebase service account JSON in Vault group
`fcm`; the project id is read from inside it. The OAuth step is a JWT signed
with openssl and exchanged for an access token, cached ~55 minutes, so there is
no Google client library to keep up to date.

- **One recipient = one device token.** A citizen with two devices gets two
  log rows, which is what makes "this device stopped receiving" visible.
- **Dead tokens are not retried.** UNREGISTERED, SENDER_ID_MISMATCH and an
  invalid token throw `PermanentNotificationFailure`: the job marks the row
  failed and stops, instead of three attempts per notification forever.
  `PushTokenRejected` is dispatched so the token's owner deletes it
  (nawasara/citizen listens).
- An INVALID_ARGUMENT that blames our payload rather than the token is still
  permanent, but does **not** delete the device. Deleting a citizen's phone for
  our own bug would silently end their notifications.
- Context `push_data` becomes the FCM `data` map (all values cast to string, or
  FCM rejects the message); `push_channel` sets the Android channel id.

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

**Since v0.2.6, ad-hoc subject and body are sent verbatim, never compiled as Blade.** Before that they went through `Blade::render()`, and ad-hoc bodies often carry outside text (a hacked site's name in a secscan alert, a citizen's report title, a Zoom meeting topic). Blade executes `{{ ... }}` and `@php`, and `e()` does not neutralise either, so such text could run code on the server. Escape outside values with `e()` (that stops HTML injection) and build the string in PHP. Stored templates are not compiled as Blade either.

**Since the security audit of 15 October 2026 (S1), stored templates are rendered by a safe placeholder renderer, not `Blade::render()`.** Templates are edited in the panel, and the `notification.template.*` permissions can be granted to any role from the UI, so Blade meant PHP execution for whoever held them. The renderer supports `{{ $var }}` (escaped in HTML bodies), `{{ $a->b }}` / `{{ $a.b }}`, `{{ $var ?? 'default' }}`, `{!! $var !!}` (raw), `{{-- comments --}}`, `@{{ literal }}`, and `@if` / `@elseif` / `@else` / `@endif` with simple conditions (`$a`, `! empty($a)`, `isset($a)`, `$a <= 7`). Anything else, including function calls and `@php`, is left as literal text and never executed. The bundled default templates render byte-for-byte as they did under Blade (`tests/DefaultTemplatesRenderSameTest.php`). Saving and deleting a template now requires sudo.

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
- In-app notification bell in topbar
- Webhook delivery callbacks (open/click tracking, bounce handling)
- User notification preferences (per-channel, quiet hours)
- Template broadcast (blast to role / OPD / custom audience)

See [`docs/todo-notification.md`](../../docs/todo-notification.md) for the full plan.

## Author

**Pringgo J. Saputro** &lt;odyinggo@gmail.com&gt;

## License

MIT
