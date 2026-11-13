<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class WebhookUrlPolicy
{
    public function validate(string $url): void
    {
        $this->resolvePublicAddresses($url);
    }

    /**
     * @return array{host: string, port: int, addresses: list<string>, is_ip: bool}
     */
    public function resolvePublicAddresses(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            $this->rejectUrl('The webhook URL is invalid.');
        }

        $host = strtolower($parts['host'] ?? '');
        $hostname = trim($host, '[]');
        $port = $parts['port'] ?? 443;

        if (
            strtolower($parts['scheme'] ?? '') !== 'https'
            || $hostname === ''
            || preg_match('/[^\x20-\x7E]/', $hostname) === 1
            || str_ends_with($hostname, '.')
            || isset($parts['user'])
            || isset($parts['pass'])
            || $port !== 443
            || $this->isLocalHostname($hostname)
        ) {
            $this->rejectUrl('Webhook URLs must use HTTPS on a public host and cannot include credentials or custom ports.');
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            if (!$this->isPublicIp($hostname)) {
                $this->rejectUrl();
            }

            return ['host' => $hostname, 'port' => $port, 'addresses' => [$hostname], 'is_ip' => true];
        }

        if (
            preg_match('/^(?:[0-9.]+|0x[0-9a-f.]+)$/i', $hostname) === 1
            || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            $this->rejectUrl();
        }

        $records = dns_get_record($hostname, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw ValidationException::withMessages([
                'url' => ['The webhook host must resolve to a public IP address.'],
            ]);
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip)) {
                continue;
            }
            if (!$this->isPublicIp($ip)) {
                $this->rejectUrl();
            }
            $addresses[] = $ip;
        }

        if ($addresses === []) {
            throw ValidationException::withMessages([
                'url' => ['The webhook host must resolve to a public IP address.'],
            ]);
        }

        return [
            'host' => $hostname,
            'port' => $port,
            'addresses' => array_values(array_unique($addresses)),
            'is_ip' => false,
        ];
    }

    private function isLocalHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.test');
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function rejectUrl(string $message = 'Webhook URLs cannot resolve to private or reserved IP addresses.'): never
    {
        throw ValidationException::withMessages([
            'url' => [$message],
        ]);
    }
}
