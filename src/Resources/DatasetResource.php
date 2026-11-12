<?php

declare(strict_types=1);

namespace Aliifz\Kissflow\Resources;

use Aliifz\Kissflow\Exceptions\ApiException;
use Aliifz\Kissflow\KissflowClient;

/**
 * Dataset rows. Schema-agnostic: field names are whatever your dataset defines.
 *
 * Rows are identified by a key column, "Name" by default (Kissflow's built-in key field).
 * Use keyedBy('Other_Field') if your dataset's key column has a different field id.
 * In the paths and bodies below, "Name" stands for the key field.
 *
 *   GET    /dataset/2/{account}/{dataset}/list                      paginated list
 *   GET    /dataset/2/{account}/{dataset}/view/{view}/list          paginated list of a view
 *   GET    /dataset/2/{account}/{dataset}?Name={key}                one row
 *   POST   /dataset/2/{account}/{dataset}                           create one row  (body: {"Name": key, ...fields})
 *   PUT    /dataset/2/{account}/{dataset}                           update one row  (body: {"Name": key, ...fields})
 *   POST   /dataset/2/{account}/{dataset}/batch                     upsert many     (body: [{"_id": .., "Name": .., ...}])
 *   POST   /dataset/2/{account}/{dataset}/view/{view}/batch         batch through a view
 *   DELETE /dataset/2/{account}/{dataset}                           delete one row  (body: {"Name": key[, "_id": id]})
 *   POST   /dataset/2/{account}/{dataset}/{rowId}/{fieldId}/image   upload an image (multipart, part "images")
 *
 * Views: view('My_View') scopes list()/each()/all()/batch() to that view. Single-row calls
 * (get/create/update/upsert/delete/uploadImage) always go to the dataset itself.
 *
 * Gotchas:
 *  - Dropdown columns silently drop values that are not current dropdown options. Re-read
 *    after writing if that matters.
 *  - DELETE needs delete permission on the dataset for the API key, otherwise HTTP 401.
 *    Some datasets also need the row's "_id" in the DELETE body: pass it as delete($key, $id).
 *  - batch() is applied asynchronously: a 2xx means "accepted", the rows change minutes
 *    later. create()/update() are synchronous. Verify batch writes by reading back later.
 *  - A view's batch endpoint may be update-only (it ignores rows that do not exist yet).
 *    Use create()/upsert() for new rows.
 *  - JSON create/update ignore base64 image fields. Use uploadImage() for images.
 */
class DatasetResource
{
    private ?string $batchUrl = null;

    private ?string $viewId = null;

    private string $keyField = 'Name';

    public function __construct(
        private readonly KissflowClient $client,
        private readonly string $datasetId,
    ) {
    }

    public function id(): string
    {
        return $this->datasetId;
    }

    public function viewId(): ?string
    {
        return $this->viewId;
    }

    public function keyField(): string
    {
        return $this->keyField;
    }

    /**
     * Identify rows by a different key column (default "Name") for get/create/update/upsert/delete.
     */
    public function keyedBy(string $field): static
    {
        if (trim($field) === '') {
            throw new \InvalidArgumentException('Kissflow key field must not be empty.');
        }

        $clone = clone $this;
        $clone->keyField = $field;

        return $clone;
    }

    /**
     * Scope list/each/all/batch to a dataset view, e.g. dataset('My_Dataset')->view('My_View').
     */
    public function view(string $viewId): static
    {
        if (trim($viewId) === '') {
            throw new \InvalidArgumentException('Kissflow view id must not be empty.');
        }

        $clone = clone $this;
        $clone->viewId = $viewId;

        return $clone;
    }

    /**
     * Use a full batch URL instead of the derived one (for setups that store the
     * complete ".../batch" URL in config).
     */
    public function withBatchUrl(?string $url): static
    {
        $clone = clone $this;
        $clone->batchUrl = $url === '' ? null : $url;

        return $clone;
    }

    /**
     * One page of rows.
     *
     * @return array<int, array>
     */
    public function list(int $page = 1, int $pageSize = 1000, array $extraQuery = []): array
    {
        return $this->client->request('GET', $this->collectionPath('/list'), array_merge([
            'page_number' => $page,
            'page_size' => $pageSize,
        ], $extraQuery))->rows();
    }

    /**
     * Every row, lazily, following pages until a short page.
     *
     * @return \Generator<int, array>
     */
    public function each(int $pageSize = 1000, array $extraQuery = []): \Generator
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

    /**
     * Every row as one array.
     *
     * @return array<int, array>
     */
    public function all(int $pageSize = 1000, array $extraQuery = []): array
    {
        return iterator_to_array($this->each($pageSize, $extraQuery), false);
    }

    /**
     * One row by key, or null when it does not exist.
     */
    public function get(string $key): ?array
    {
        $response = $this->client->request('GET', $this->rowPath(''), [$this->keyField => $key], allowStatuses: [404]);
        if ($response->status === 404) {
            return null;
        }

        $data = $response->data();

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * Create one row. $fields must include the key field ("Name" unless keyedBy() was used).
     *
     * @return mixed decoded response payload (normally the created row, with its "_id")
     */
    public function create(array $fields): mixed
    {
        if (trim((string) ($fields[$this->keyField] ?? '')) === '') {
            throw new \InvalidArgumentException("Kissflow dataset create() needs a non-empty \"{$this->keyField}\".");
        }

        return $this->client->request('POST', $this->rowPath(''), json: $fields)->json();
    }

    /**
     * Update the row with key $key. Only the given fields change.
     *
     * @return mixed decoded response payload
     */
    public function update(string $key, array $fields): mixed
    {
        return $this->client->request('PUT', $this->rowPath(''), json: array_merge([$this->keyField => $key], $fields))->json();
    }

    /**
     * Update the row keyed by $fields[keyField] if it exists, otherwise create it.
     *
     * On update the existing row's "_id" is sent, overriding any "_id" in $fields, so a row
     * created in the Kissflow UI (which has an internal _id unequal to its key) is updated
     * in place and never renamed or duplicated. Costs one GET plus one write, all synchronous.
     *
     * @return array{action: 'created'|'updated', response: mixed, existing: ?array}
     */
    public function upsert(array $fields): array
    {
        $key = trim((string) ($fields[$this->keyField] ?? ''));
        if ($key === '') {
            throw new \InvalidArgumentException("Kissflow dataset upsert() needs a non-empty \"{$this->keyField}\".");
        }
        $fields[$this->keyField] = $key;

        $existing = $this->get($key);

        if ($existing === null) {
            return ['action' => 'created', 'response' => $this->create($fields), 'existing' => null];
        }

        if (! empty($existing['_id'])) {
            $fields['_id'] = $existing['_id'];
        }

        return ['action' => 'updated', 'response' => $this->update($key, $fields), 'existing' => $existing];
    }

    /**
     * Create-or-update many rows in one call. Each row should carry "_id" and/or the key field.
     * Applied asynchronously by Kissflow (see class notes).
     *
     * @param  array<int, array>  $rows
     * @return mixed decoded response payload
     */
    public function batch(array $rows): mixed
    {
        if ($rows === []) {
            return [];
        }

        $url = $this->batchUrl ?? $this->collectionPath('/batch');

        return $this->client->request('POST', $url, json: array_values($rows))->json();
    }

    /**
     * batch() in chunks; returns one response per chunk.
     *
     * @param  array<int, array>  $rows
     * @return array<int, mixed>
     */
    public function batchChunked(array $rows, int $chunkSize = 500): array
    {
        $responses = [];
        foreach (array_chunk(array_values($rows), max(1, $chunkSize)) as $chunk) {
            $responses[] = $this->batch($chunk);
        }

        return $responses;
    }

    /**
     * Delete the row with key $key. Pass $id (the row's "_id") for datasets that require it.
     *
     * @return bool true when deleted, false when it was already gone (404 DocumentNotFound)
     */
    public function delete(string $key, ?string $id = null): bool
    {
        $body = [$this->keyField => $key];
        if ($id !== null && $id !== '') {
            $body['_id'] = $id;
        }

        $response = $this->client->request('DELETE', $this->rowPath(''), json: $body, allowStatuses: [404]);

        if ($response->status === 404) {
            if (str_contains($response->body, 'DocumentNotFound')) {
                return false;
            }
            // A 404 that is not "row missing" means a wrong dataset/account: surface it.
            throw ApiException::fromResponse('DELETE', $this->rowPath(''), 404, $response->body);
        }

        return true;
    }

    /**
     * Upload an image into an image field of one row.
     *
     * @param  string  $rowId  the row's "_id"
     * @param  string  $fieldId  the image field id, e.g. "image_1"
     * @param  string  $contents  raw image bytes (base64-decode first if you hold base64)
     * @return mixed decoded response payload
     */
    public function uploadImage(string $rowId, string $fieldId, string $contents, string $filename = 'image.jpg', string $contentType = 'image/jpeg'): mixed
    {
        if ($rowId === '' || $fieldId === '') {
            throw new \InvalidArgumentException('Kissflow uploadImage() needs a row id and a field id.');
        }
        if ($contents === '') {
            throw new \InvalidArgumentException('Kissflow uploadImage() was given empty image contents.');
        }

        $path = $this->rowPath('/'.rawurlencode($rowId).'/'.rawurlencode($fieldId).'/image');

        return $this->client->request('POST', $path, multipart: [[
            'name' => 'images',
            'contents' => $contents,
            'filename' => $filename,
            'headers' => ['Content-Type' => $contentType],
        ]])->json();
    }

    /** Dataset-level path, used for single-row operations. */
    private function rowPath(string $suffix): string
    {
        return sprintf('/dataset/2/%s/%s%s', rawurlencode($this->client->config()->accountId), rawurlencode($this->datasetId), $suffix);
    }

    /** Collection path: goes through the view when one is set. */
    private function collectionPath(string $suffix): string
    {
        $view = $this->viewId === null ? '' : '/view/'.rawurlencode($this->viewId);

        return $this->rowPath($view.$suffix);
    }
}
