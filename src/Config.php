<?php

declare(strict_types=1);

namespace Aliifz\Kissflow;

use Aliifz\Kissflow\Exceptions\ConfigurationException;

/**
 * Immutable client settings.
 *
 * baseUrl     https://<subdomain>.kissflow.com
 * accountId   the Kissflow account id that appears in every API path (/process/2/{accountId}/...)
 */
final class Config
{
    public readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        public readonly string $accountId,
        public readonly string $accessKeyId,
        #[\SensitiveParameter] public readonly string $accessKeySecret,
        public readonly bool $verifySsl = true,
        public readonly float $timeout = 60.0,
        public readonly int $maxRetries = 2,
        public readonly int $retryDelayMs = 500,
        public readonly int $pageDelayMs = 100,
        public readonly bool $forceHttp11 = true,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');

        foreach (['baseUrl' => $this->baseUrl, 'accountId' => $accountId, 'accessKeyId' => $accessKeyId, 'accessKeySecret' => $accessKeySecret] as $name => $value) {
            if (trim($value) === '') {
                throw new ConfigurationException("Kissflow config '{$name}' is required.");
            }
        }

        if (! preg_match('#^https?://#i', $this->baseUrl)) {
            throw new ConfigurationException("Kissflow baseUrl must start with http(s)://, got '{$this->baseUrl}'.");
        }

        if ($maxRetries < 0 || $retryDelayMs < 0 || $pageDelayMs < 0) {
            throw new ConfigurationException('Kissflow retry/delay settings must be >= 0.');
        }
    }

    /**
     * Build from an array, e.g. a Laravel config file or parsed env.
     *
     * Keys: base_url, account_id, access_key_id, access_key_secret, verify_ssl, timeout,
     *       max_retries, retry_delay_ms, page_delay_ms, force_http11
     */
    public static function fromArray(array $c): self
    {
        return new self(
            baseUrl: (string) ($c['base_url'] ?? ''),
            accountId: (string) ($c['account_id'] ?? ''),
            accessKeyId: (string) ($c['access_key_id'] ?? ''),
            accessKeySecret: (string) ($c['access_key_secret'] ?? ''),
            verifySsl: (bool) ($c['verify_ssl'] ?? true),
            timeout: (float) ($c['timeout'] ?? 60.0),
            maxRetries: (int) ($c['max_retries'] ?? 2),
            retryDelayMs: (int) ($c['retry_delay_ms'] ?? 500),
            pageDelayMs: (int) ($c['page_delay_ms'] ?? 100),
            forceHttp11: (bool) ($c['force_http11'] ?? true),
        );
    }
}
