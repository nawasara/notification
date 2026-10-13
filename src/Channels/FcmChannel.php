<?php

namespace Nawasara\Notification\Channels;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nawasara\Notification\Events\PushTokenRejected;
use Nawasara\Notification\Exceptions\PermanentNotificationFailure;
use Nawasara\Notification\Services\NotificationPayload;
use Nawasara\Vault\Facades\Vault;

/**
 * Push notifications to the citizen app through Firebase Cloud Messaging,
 * HTTP v1 API.
 *
 * ## Recipient = one device token
 *
 * A citizen with a phone and a tablet has two tokens, and the caller passes
 * each as its own recipient. One log row per device is what makes "this
 * device stopped receiving" visible at all. Email addresses are rejected by
 * validateRecipient(), so a caller that still passes an email to this
 * channel fails loudly instead of sending nowhere.
 *
 * ## Credentials
 *
 * The service account JSON from Firebase (Project settings, Service
 * accounts, Generate private key) sits in Vault group `fcm`. The project id is
 * read from inside that JSON rather than typed separately: two fields that
 * must agree are two fields that eventually do not.
 *
 * No Google client library. The OAuth step is one signed JWT exchanged for an
 * access token, done here with openssl, and cached for most of its hour.
 *
 * ## Dead tokens
 *
 * UNREGISTERED (app uninstalled, data cleared) and tokens from another
 * project can never succeed. Those throw PermanentNotificationFailure, so the
 * job stops instead of retrying three times, and PushTokenRejected tells the
 * token's owner to delete it. Rate limits and outages throw normally and are
 * retried.
 */
class FcmChannel extends AbstractChannel
{
    protected const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    protected const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** FCM caps a message at 4 KB; leave room for the title and data. */
    protected const MAX_BODY = 1000;

    /** FCM errorCodes that mean this token will never work. */
    protected const DEAD_TOKEN_CODES = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    public function name(): string
    {
        return 'fcm';
    }

    public function isReady(): bool
    {
        return $this->serviceAccount() !== null;
    }

    /**
     * FCM registration tokens: long, URL-safe, often with a colon. Never an
     * email address and never a Telegram chat id.
     */
    public function validateRecipient(string $recipient): bool
    {
        return preg_match('/^[A-Za-z0-9_\-:]{32,4096}$/', $recipient) === 1
            && ! ctype_digit(ltrim($recipient, '-'));
    }

    public function send(NotificationPayload $payload): ?string
    {
        $account = $this->serviceAccount();
        if ($account === null) {
            throw new \RuntimeException('FCM belum dikonfigurasi di Vault (grup: fcm).');
        }

        $response = Http::timeout(15)
            ->withToken($this->accessToken($account))
            ->post(
                "https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send",
                ['message' => self::buildMessage($payload)]
            );

        if ($response->successful()) {
            return (string) $response->json('name');
        }

        $error = self::classifyError($response->status(), (array) $response->json());

        if ($error['dead_token']) {
            PushTokenRejected::dispatch($payload->recipient, $error['code']);
        }

        if ($error['permanent']) {
            throw new PermanentNotificationFailure('FCM menolak: '.$error['message']);
        }

        throw new \RuntimeException('FCM gagal: '.$error['message']);
    }

    /**
     * For the Vault test button: proves the key is valid by getting an access
     * token. Nothing is sent to any device.
     *
     * Returns both `success` (what the Vault panel reads) and `ok` (the
     * channel contract), so either caller reads it correctly.
     */
    public function testConnection(): array
    {
        try {
            $account = $this->serviceAccount();
            if ($account === null) {
                return ['success' => false, 'ok' => false,
                    'message' => 'Service account belum diisi, atau isinya bukan JSON service account Firebase.'];
            }

            Cache::forget($this->cacheKey($account));
            $this->accessToken($account);

            $message = "Terhubung ke project Firebase {$account['project_id']}.";

            return ['success' => true, 'ok' => true, 'message' => $message];
        } catch (\Throwable $e) {
            return ['success' => false, 'ok' => false, 'message' => 'Gagal: '.$e->getMessage()];
        }
    }

    /**
     * The FCM v1 message for one device.
     *
     * `data` values must be strings, or FCM rejects the whole message, so
     * every value is cast. The app reads `data` to decide which screen a tap
     * opens; the notification block is what the system tray shows while the
     * app is closed.
     *
     * Context keys read:
     *   push_data     array  shipped as `data`, e.g. ['type' => 'report', 'code' => 'LB-...']
     *   push_channel  string Android notification channel id the app created
     */
    public static function buildMessage(NotificationPayload $payload): array
    {
        $body = trim(preg_replace('/\s+/u', ' ', strip_tags($payload->body)) ?? '');
        if (mb_strlen($body) > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY - 1).'…';
        }

        $data = [];
        foreach ((array) ($payload->context['push_data'] ?? []) as $key => $value) {
            if (is_scalar($value)) {
                $data[(string) $key] = (string) $value;
            }
        }

        $android = ['priority' => 'high'];
        if (! empty($payload->context['push_channel'])) {
            $android['notification'] = ['channel_id' => (string) $payload->context['push_channel']];
        }

        $message = [
            'token' => $payload->recipient,
            'notification' => array_filter([
                'title' => $payload->subject,
                'body' => $body,
            ], fn ($v) => $v !== null && $v !== ''),
            'android' => $android,
        ];

        if ($data !== []) {
            $message['data'] = $data;
        }

        return $message;
    }

    /**
     * Sort an FCM error into dead token, permanent, or worth retrying.
     *
     * INVALID_ARGUMENT is permanent (resending the same message gets the same
     * answer), but counts as a dead token only when FCM says the TOKEN is the
     * invalid part. Otherwise it is our payload, and deleting the citizen's
     * device for our own mistake would silently stop their notifications.
     *
     * @return array{code: string, message: string, dead_token: bool, permanent: bool}
     */
    public static function classifyError(int $status, array $body): array
    {
        $error = (array) ($body['error'] ?? []);
        $message = (string) ($error['message'] ?? "HTTP {$status}");

        $code = (string) ($error['status'] ?? '');
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (is_array($detail) && ! empty($detail['errorCode'])) {
                $code = (string) $detail['errorCode'];
                break;
            }
        }

        $deadToken = in_array($code, self::DEAD_TOKEN_CODES, true)
            || ($code === 'INVALID_ARGUMENT' && stripos($message, 'registration token') !== false);

        return [
            'code' => $code !== '' ? $code : "HTTP_{$status}",
            'message' => $code !== '' ? "{$code}: {$message}" : $message,
            'dead_token' => $deadToken,
            'permanent' => $deadToken || $code === 'INVALID_ARGUMENT',
        ];
    }

    /**
     * Self-signed JWT asserting the service account, as Google's OAuth
     * endpoint expects for the jwt-bearer grant.
     */
    public static function signedAssertion(array $account, int $now): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $account['token_uri'] ?? self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $key = openssl_pkey_get_private($account['private_key']);
        if ($key === false || ! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Kunci privat service account tidak dapat dipakai menandatangani.');
        }

        return "{$header}.{$claims}.".$b64($signature);
    }

    /** OAuth access token, cached until shortly before its hour runs out. */
    protected function accessToken(array $account): string
    {
        return Cache::remember($this->cacheKey($account), 3300, function () use ($account) {
            $response = Http::asForm()->timeout(15)->post($account['token_uri'] ?? self::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => self::signedAssertion($account, time()),
            ]);

            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new \RuntimeException('Google menolak service account: '
                    .($response->json('error_description') ?? $response->json('error') ?? $response->body()));
            }

            return $token;
        });
    }

    protected function cacheKey(array $account): string
    {
        return 'nawasara-notification:fcm-access-token:'.sha1($account['client_email'].$account['project_id']);
    }

    /**
     * The service account from Vault, or null when absent or not one.
     *
     * @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}|null
     */
    protected function serviceAccount(): ?array
    {
        try {
            if (! Vault::isConfigured('fcm')) {
                return null;
            }
            $json = json_decode((string) Vault::get('fcm', 'service_account_json'), true);
        } catch (\Throwable) {
            return null;
        }

        return self::parseServiceAccount($json);
    }

    /** Accept only a real service account; anything else counts as not configured. */
    public static function parseServiceAccount(mixed $json): ?array
    {
        if (! is_array($json) || ($json['type'] ?? null) !== 'service_account') {
            return null;
        }

        foreach (['project_id', 'client_email', 'private_key'] as $field) {
            if (empty($json[$field]) || ! is_string($json[$field])) {
                return null;
            }
        }

        return $json;
    }
}
