<?php

declare(strict_types=1);

namespace Aliifz\Kissflow\Resources;

use Aliifz\Kissflow\KissflowClient;

/**
 * Process (workflow) items, via the admin endpoints:
 *
 *   GET /process/2/{account}/admin/{process}/item   list / search
 *   GET /process/2/{account}/admin/{process}/{id}   one item, including Table:: child rows
 */
class ProcessResource
{
    public function __construct(
        private readonly KissflowClient $client,
        private readonly string $processId,
    ) {
    }

    public function id(): string
    {
        return $this->processId;
    }

    /**
     * One page of items. $extraQuery is passed through (e.g. q / search_field / filters).
     *
     * @return array<int, array> rows
     */
    public function list(int $page = 1, int $pageSize = 50, array $extraQuery = []): array
    {
        return $this->client->request('GET', $this->path('item'), array_merge([
            'page_number' => $page,
            'page_size' => $pageSize,
        ], $extraQuery))->rows();
    }

    /**
     * Search items where $field matches $value.
     *
     * @return array<int, array> rows
     */
    public function search(string $field, string $value, int $pageSize = 50, int $page = 1): array
    {
        return $this->list($page, $pageSize, ['q' => $value, 'search_field' => $field]);
    }

    /**
     * First item where $field matches $value, or null.
     */
    public function findBy(string $field, string $value): ?array
    {
        return $this->search($field, $value, 1)[0] ?? null;
    }

    /**
     * The Kissflow _id of the first item where $field matches $value, or null.
     */
    public function findIdBy(string $field, string $value): ?string
    {
        $item = $this->findBy($field, $value);
        $id = $item['_id'] ?? $item['id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /**
     * Full item by Kissflow _id (child tables appear as "Table::<Model>" keys), or null on 404.
     */
    public function get(string $itemId): ?array
    {
        $response = $this->client->request('GET', $this->path(rawurlencode($itemId)), allowStatuses: [404]);
        if ($response->status === 404) {
            return null;
        }

        $data = $response->data();

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * Every item, following pages until a short page.
     *
     * @return \Generator<int, array>
     */
    public function each(int $pageSize = 100, array $extraQuery = []): \Generator
    {
        $page = 1;
        do {
            $rows = $this->list($page, $pageSize, $extraQuery);
            yield from $rows;
            $page++;
            if (count($rows) === $pageSize) {
                $this->client->pageDelay();
            }
        } while (count($rows) === $pageSize);
    }

    private function path(string $suffix): string
    {
        return sprintf('/process/2/%s/admin/%s/%s', rawurlencode($this->client->config()->accountId), rawurlencode($this->processId), $suffix);
    }
}
