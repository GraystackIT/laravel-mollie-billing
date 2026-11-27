<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\SandboxBrowser\Support;

use Illuminate\Support\Facades\Http;

/**
 * The renewal replay canary's view of its webhook receiver — the always-on service Mollie's
 * real deliveries go to (its own repository, `mollie-canary-receiver`),
 * read back over `GET /deliveries`.
 *
 * Configured by `CANARY_RECEIVER_URL` and `CANARY_RECEIVER_READ_TOKEN`. Without the URL the
 * canary works as before — webhook URLs on `https://example.com`, every delivery synthetic.
 */
final class CanaryReceiver
{
    private const PAGE = 500;

    /** @var array<string, mixed> the receiver's `config`, as its last listing reported it */
    public array $settings = [];

    public function __construct(
        public readonly string $url,
        private readonly string $token,
    ) {
    }

    public static function fromEnvironment(): ?self
    {
        $url = self::env('CANARY_RECEIVER_URL');

        if ($url === null) {
            return null;
        }

        $token = self::env('CANARY_RECEIVER_READ_TOKEN');

        if ($token === null) {
            throw new \RuntimeException('CANARY_RECEIVER_URL is set but CANARY_RECEIVER_READ_TOKEN is not: the canary cannot read what the receiver got.');
        }

        if (! str_starts_with($url, 'https://')) {
            throw new \RuntimeException('CANARY_RECEIVER_URL must be an https:// URL — Mollie posts webhooks to the outside world.');
        }

        return new self(rtrim($url, '/'), $token);
    }

    /**
     * Every delivery matching the filter, oldest first, all pages.
     *
     * @param  array{after_id?: int, payment_id?: string, path_prefix?: string, since?: string}  $filter
     * @return list<array<string, mixed>>
     */
    public function deliveries(array $filter = []): array
    {
        $all = [];

        do {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(30)
                ->retry(3, 3000)
                ->get($this->url.'/deliveries', $filter + ['limit' => self::PAGE])
                ->throw();

            $this->settings = (array) ($response->json('config') ?? []);
            $page = $response->json('deliveries');
            $page = is_array($page) ? array_values($page) : [];
            $all = array_merge($all, $page);

            if ($page !== []) {
                $filter['after_id'] = (int) end($page)['id'];
            }
        } while (count($page) === self::PAGE);

        return $all;
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? (getenv($name) ?: null);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
