<?php

namespace Nawasara\Notification\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nawasara\Notification\Contracts\NotificationChannelDriver;
use Nawasara\Notification\Jobs\SendNotificationJob;
use Nawasara\Notification\Models\NotificationLog;
use Nawasara\Notification\Models\NotificationTemplate;

/**
 * Entry point fluent untuk dispatch notification.
 *
 * Pakai via Facade `Notify::to(...)->template(...)->send()`.
 *
 * Lifecycle:
 *   1. Builder methods (to, channel, template, data, subject, body, priority)
 *      kumpulkan state.
 *   2. send() — render template + create NotificationLog row + dispatch
 *      SendNotificationJob ke queue.
 *   3. Job pick driver, panggil driver->send(), update log dengan
 *      sent_at / error.
 */
class NotificationService
{
    /** @var array<int, mixed> recipient inputs */
    protected array $recipients = [];

    /** @var array<int, string> channel names; empty = pakai default config */
    protected array $channels = [];

    protected ?string $templateKey = null;

    protected array $data = [];

    protected ?string $subject = null;

    protected ?string $body = null;

    protected string $priority = 'normal';

    protected array $context = [];

    protected bool $sync = false;

    public function __construct(
        protected TemplateRenderer $renderer,
    ) {}

    public function to(mixed ...$recipients): static
    {
        $clone = clone $this;
        $clone->recipients = array_merge($clone->recipients, array_values($recipients));

        return $clone;
    }

    public function channel(string|array $channel): static
    {
        $clone = clone $this;
        $clone->channels = array_values((array) $channel);

        return $clone;
    }

    public function template(string $key): static
    {
        $clone = clone $this;
        $clone->templateKey = $key;

        return $clone;
    }

    public function data(array $data): static
    {
        $clone = clone $this;
        $clone->data = $data;

        return $clone;
    }

    public function subject(string $subject): static
    {
        $clone = clone $this;
        $clone->subject = $subject;

        return $clone;
    }

    public function body(string $body): static
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function priority(string $priority): static
    {
        $clone = clone $this;
        $clone->priority = $priority;

        return $clone;
    }

    public function context(array $context): static
    {
        $clone = clone $this;
        $clone->context = array_merge($clone->context, $context);

        return $clone;
    }

    /**
     * Bypass queue, kirim sync — untuk testing atau action urgent.
     */
    public function sync(): static
    {
        $clone = clone $this;
        $clone->sync = true;

        return $clone;
    }

    /**
     * Resolve + dispatch. Return array of created NotificationLog records.
     *
     * @return array<int, NotificationLog>
     */
    public function send(): array
    {
        if (empty($this->recipients)) {
            throw new \InvalidArgumentException('No recipients specified — call ->to() first');
        }

        $channels = $this->channels ?: [config('nawasara-notification.default_channel', 'email')];

        $template = $this->templateKey
            ? NotificationTemplate::byKey($this->templateKey)->active()->first()
            : null;

        if ($this->templateKey && ! $template) {
            throw new \RuntimeException("Template '{$this->templateKey}' not found or inactive");
        }

        $logs = [];

        foreach ($this->recipients as $recipient) {
            foreach ($channels as $channelName) {
                // Skip kalau template specify channels dan ini tidak include
                if ($template && ! $template->supportsChannel($channelName)) {
                    continue;
                }

                $resolved = $this->resolveRecipient($recipient, $channelName);
                if (! $resolved) {
                    continue;
                }

                [$rendered, $userId] = $this->renderForChannel($template, $channelName, $resolved);

                $log = NotificationLog::create([
                    'uuid' => (string) Str::uuid(),
                    'template_id' => $template?->id,
                    'template_key' => $template?->key ?? $this->templateKey,
                    'channel' => $channelName,
                    'user_id' => $userId,
                    'recipient' => $resolved,
                    'subject' => $rendered['subject'],
                    'body' => $rendered['body'],
                    'status' => NotificationLog::STATUS_QUEUED,
                    'context' => $this->context,
                ]);

                $logs[] = $log;

                if ($this->sync) {
                    SendNotificationJob::dispatchSync($log->id);
                } else {
                    SendNotificationJob::dispatch($log->id)
                        ->onQueue(config('nawasara-notification.queue', 'notifications'));
                }
            }
        }

        return $logs;
    }

    /**
     * Resolve recipient input (User model, email string, etc) ke string yang
     * sesuai channel.
     */
    protected function resolveRecipient(mixed $recipient, string $channel): ?string
    {
        // Objek pengguna — ambil bidang yang sesuai KANALNYA.
        //
        // Dipindah ke atas supaya pemanggil dapat menyerahkan objek pengguna
        // apa adanya dan tiap kanal mengambil bagiannya sendiri: surel untuk
        // email, chat id untuk telegram. Itulah yang membuat satu penerima
        // dapat dijangkau lewat beberapa kanal tanpa pemanggil perlu tahu
        // kanal mana saja yang aktif.
        if (is_object($recipient)) {
            $nilai = match ($channel) {
                'email' => $recipient->email ?? null,
                'telegram' => $recipient->telegram_chat_id ?? null,
                'whatsapp' => $recipient->whatsapp_number ?? null,
                default => null,
            };

            return ($nilai !== null && $nilai !== '') ? (string) $nilai : null;
        }

        if (! is_string($recipient) || $recipient === '') {
            return null;
        }

        // ⚠️ String TIDAK lagi diloloskan begitu saja.
        //
        // Dulu string apa pun diteruskan ke kanal apa pun. Akibatnya, begitu
        // sebuah kanal baru dinyalakan, alamat surel dari pemanggil lama ikut
        // dikirim ke kanal itu sebagai "penerima" — gagal di sisi penyedia,
        // tercatat di log, dan tidak ada yang tahu peringatannya tak sampai.
        //
        // Kegagalan diam seperti itu yang paling mahal: semuanya tampak
        // berjalan. Jadi ketidakcocokan dibuat BERISIK, bukan disembunyikan.
        // Kanal yang belum terdaftar melempar; di sini itu bukan galat yang
        // perlu menggagalkan pengiriman ke kanal lain, jadi cukup dilewati.
        try {
            $driver = $this->driver($channel);
        } catch (\Throwable) {
            return $recipient;
        }

        if (! $driver->validateRecipient($recipient)) {
            Log::warning('notification: penerima tidak sesuai kanal — dilewati', [
                'channel' => $channel,
                'recipient' => $recipient,
                'hint' => "Nilai ini bukan format yang dikenali kanal '{$channel}'. "
                    .'Kirim objek pengguna agar tiap kanal mengambil bidangnya sendiri.',
            ]);

            return null;
        }

        return $recipient;
    }

    /**
     * Render body+subject untuk channel tertentu.
     *
     * @return array{0: array{subject: ?string, body: string}, 1: ?int}
     */
    protected function renderForChannel(?NotificationTemplate $template, string $channel, string $recipient): array
    {
        $userId = null;
        // Detect user_id kalau recipient adalah User-like
        // Phase 1 simple: kalau recipient ke-resolve dari object, ambil ->id

        if ($template) {
            $rendered = $this->renderer->render($template, $channel, $this->data);

            return [$rendered, $userId];
        }

        // Ad-hoc — pakai subject/body langsung
        if (! $this->body) {
            throw new \InvalidArgumentException('Either template() or body() must be set');
        }

        return [[
            'subject' => $this->subject ? $this->renderer->renderString($this->subject, $this->data) : null,
            'body' => $this->renderer->renderString($this->body, $this->data),
        ], $userId];
    }

    /**
     * Get registered channel driver instance.
     */
    public function driver(string $channel): NotificationChannelDriver
    {
        $class = config("nawasara-notification.channels.{$channel}");
        if (! $class) {
            throw new \InvalidArgumentException("Channel '{$channel}' tidak ter-register di config");
        }

        return app($class);
    }
}
