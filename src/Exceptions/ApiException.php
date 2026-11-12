<?php

declare(strict_types=1);

namespace Aliifz\Kissflow\Exceptions;

use Throwable;

/**
 * Kissflow answered with a non-2xx status (or a transport error occurred, status 0).
 */
class ApiException extends KissflowException
{
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $responseBody = '',
        private readonly string $method = '',
        private readonly string $url = '',
        ?Throwable $previous = null,
        private readonly array $responseHeaders = [],
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * @param  array<string, string[]>  $headers  response headers as Guzzle returns them
     */
    public static function fromResponse(string $method, string $url, int $status, string $body, array $headers = []): self
    {
        $hint = match (true) {
            $status === 401 => ' (check the access key; dataset DELETE also needs delete permission on the dataset)',
            $status === 403 && self::isChallengeHeader($headers) => ' (blocked by a security check before reaching Kissflow; cf-mitigated: challenge)',
            $status === 404 => ' (check account id, process/dataset id, and that the item exists)',
            $status === 429 => ' (rate limited)',
            default => '',
        };

        return new self(
            sprintf('Kissflow %s %s failed: HTTP %d%s - %s', $method, $url, $status, $hint, mb_substr($body, 0, 500)),
            $status,
            $body,
            $method,
            $url,
            null,
            $headers,
        );
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /**
     * The decoded JSON error body, or null when it is not JSON.
     */
    public function getResponseJson(): ?array
    {
        $decoded = json_decode($this->responseBody, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /**
     * @return array<string, string[]>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /**
     * First value of a response header (case-insensitive), or null.
     */
    public function getResponseHeader(string $name): ?string
    {
        return self::headerValue($this->responseHeaders, $name);
    }

    /**
     * True when a 403 came from the edge security check (header "cf-mitigated: challenge")
     * rather than from Kissflow's own permission check. Retrying from the same client or
     * network usually fails the same way.
     */
    public function isChallenged(): bool
    {
        return $this->status === 403 && self::isChallengeHeader($this->responseHeaders);
    }

    private static function isChallengeHeader(array $headers): bool
    {
        return strtolower(trim((string) self::headerValue($headers, 'cf-mitigated'))) === 'challenge';
    }

    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp((string) $key, $name) === 0) {
                $value = is_array($values) ? ($values[0] ?? null) : $values;

                return $value === null ? null : (string) $value;
            }
        }

        return null;
    }
}
