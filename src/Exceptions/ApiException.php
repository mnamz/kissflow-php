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
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromResponse(string $method, string $url, int $status, string $body): self
    {
        $hint = match ($status) {
            401 => ' (check the access key; dataset DELETE also needs delete permission on the dataset)',
            404 => ' (check account id, process/dataset id, and that the item exists)',
            429 => ' (rate limited)',
            default => '',
        };

        return new self(
            sprintf('Kissflow %s %s failed: HTTP %d%s - %s', $method, $url, $status, $hint, mb_substr($body, 0, 500)),
            $status,
            $body,
            $method,
            $url,
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
}
