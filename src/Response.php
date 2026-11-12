<?php

declare(strict_types=1);

namespace Aliifz\Kissflow;

/**
 * A decoded Kissflow HTTP response.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decoded JSON body; null when the body is empty or not JSON.
     */
    public function json(): mixed
    {
        if ($this->body === '') {
            return null;
        }

        return json_decode($this->body, true);
    }

    /**
     * Kissflow wraps payloads as {"Data": ...} (sometimes "data"). Returns the inner
     * payload, or the whole decoded body when there is no envelope.
     */
    public function data(): mixed
    {
        $json = $this->json();

        if (is_array($json) && (array_key_exists('Data', $json) || array_key_exists('data', $json))) {
            return $json['Data'] ?? $json['data'];
        }

        return $json;
    }

    /**
     * The payload as a list of rows (empty list when absent).
     */
    public function rows(): array
    {
        $data = $this->data();

        return is_array($data) && array_is_list($data) ? $data : [];
    }
}
