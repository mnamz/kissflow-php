<?php

declare(strict_types=1);

namespace Aliifz\Kissflow\Resources;

use Aliifz\Kissflow\KissflowClient;

/**
 * Account users: GET /user/2/{account}/list  (rows carry _id, Name, Email, ...).
 */
class UserResource
{
    public function __construct(private readonly KissflowClient $client)
    {
    }

    /**
     * One page of users.
     *
     * @return array<int, array>
     */
    public function list(int $page = 1, int $pageSize = 1000): array
    {
        $response = $this->client->request('GET', $this->path(), [
            'page_number' => $page,
            'page_size' => $pageSize,
        ]);

        return $response->rows();
    }

    /**
     * Every user. Handles both the {"Data": [...]} envelope and a bare top-level list
     * (which the endpoint returns unpaginated).
     *
     * @return array<int, array>
     */
    public function all(int $pageSize = 1000): array
    {
        $all = [];
        $page = 1;

        while (true) {
            $response = $this->client->request('GET', $this->path(), [
                'page_number' => $page,
                'page_size' => $pageSize,
            ]);
            $json = $response->json();
            $rows = $response->rows();

            // Bare list = whole user set in one response; paging further would repeat it.
            if (is_array($json) && array_is_list($json)) {
                return array_merge($all, $rows);
            }

            $all = array_merge($all, $rows);
            if (count($rows) < $pageSize) {
                return $all;
            }

            $page++;
            $this->client->pageDelay();
        }
    }

    /**
     * Map of field value => user row, for resolving users by any column (e.g. "Email",
     * "EmployeeId"). Lookup tries $field, then its lowercase form. Users with an empty
     * value are skipped; on duplicate values the last row wins.
     *
     * @param  bool  $normalize  lowercase + trim the keys (case-insensitive lookup)
     * @return array<string, array>
     */
    public function indexBy(string $field, bool $normalize = false): array
    {
        $map = [];
        foreach ($this->all() as $user) {
            $value = $user[$field] ?? $user[strtolower($field)] ?? null;
            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }
            $key = $normalize ? strtolower(trim((string) $value)) : (string) $value;
            $map[$key] = $user;
        }

        return $map;
    }

    /**
     * Map of lowercased email => user row. Shortcut for indexBy('Email', true).
     *
     * @return array<string, array>
     */
    public function byEmail(): array
    {
        return $this->indexBy('Email', true);
    }

    private function path(): string
    {
        return sprintf('/user/2/%s/list', rawurlencode($this->client->config()->accountId));
    }
}
