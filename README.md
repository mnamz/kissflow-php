# aliifz/kissflow-php

A small PHP client for the [Kissflow](https://kissflow.com) REST API. It works with any framework and has optional Laravel integration.

It is data-agnostic: it knows Kissflow's endpoints, not your schema. Process ids, dataset ids, view ids and field names are always passed in by your code, and rows come back as plain arrays exactly as Kissflow returns them. Nothing about a particular account, process or dataset is built in.

It covers these endpoints:

| Area | Endpoint | Method |
|---|---|---|
| Process | `GET /process/2/{acc}/admin/{process}/item` | `process($id)->list() / search() / findBy() / findIdBy() / each()` |
| Process | `GET /process/2/{acc}/admin/{process}/{itemId}` | `process($id)->get($itemId)` |
| Dataset | `GET /dataset/2/{acc}/{dataset}/list` | `dataset($id)->list() / each() / all()` |
| Dataset view | `GET /dataset/2/{acc}/{dataset}/view/{view}/list` | `dataset($id)->view($view)->list() / each() / all()` |
| Dataset | `GET /dataset/2/{acc}/{dataset}?{key}=` | `dataset($id)->get($key)` |
| Dataset | `POST /dataset/2/{acc}/{dataset}` | `dataset($id)->create($fields)` |
| Dataset | `PUT /dataset/2/{acc}/{dataset}` | `dataset($id)->update($key, $fields)` |
| Dataset | `GET` then `PUT` or `POST` | `dataset($id)->upsert($fields)` |
| Dataset | `POST /dataset/2/{acc}/{dataset}/batch` | `dataset($id)->batch($rows) / batchChunked()` |
| Dataset view | `POST /dataset/2/{acc}/{dataset}/view/{view}/batch` | `dataset($id)->view($view)->batch($rows)` |
| Dataset | `DELETE /dataset/2/{acc}/{dataset}` | `dataset($id)->delete($key, $rowId = null)` |
| Dataset | `POST /dataset/2/{acc}/{dataset}/{rowId}/{fieldId}/image` | `dataset($id)->uploadImage($rowId, $fieldId, $bytes)` |
| Users | `GET /user/2/{acc}/list` | `users()->list() / all() / indexBy($field) / byEmail()` |

You can call any other endpoint with `$kf->request($method, $path, $query, $json)`.

## Install

The package is not on Packagist. Add it as a path or VCS repository:

```jsonc
// composer.json of the consuming app
"repositories": [
    { "type": "path", "url": "../kissflow-php" }          // local checkout
    // or: { "type": "vcs", "url": "git@github.com:aliifz/kissflow-php.git" }
],
"require": { "aliifz/kissflow-php": "*" }
```

```bash
composer require aliifz/kissflow-php:@dev
```

Requires PHP 8.1 or later, Guzzle 7 and PSR-3 (`psr/log`).

## Usage (plain PHP)

```php
use Aliifz\Kissflow\KissflowClient;

$kf = KissflowClient::make([
    'base_url'          => 'https://your-subdomain.kissflow.com',
    'account_id'        => 'AcXXXXXXXXXX',
    'access_key_id'     => getenv('KISSFLOW_ACCESS_KEY_ID'),
    'access_key_secret' => getenv('KISSFLOW_ACCESS_KEY_SECRET'),
], logger: $psr3Logger); // logger is optional

// Process items (ids and field names are whatever your process defines)
$proc = $kf->process('My_Process');
$item = $proc->findBy('Some_Field', 'some-value');      // first match or null
$full = $proc->get($item['_id']);                       // includes Table::<Model> child rows

// Datasets (rows keyed by "Name" by default)
$ds = $kf->dataset('My_Dataset');
foreach ($ds->each() as $row) { /* streams page by page */ }
$ds->update('ROW-1', ['Some_Field' => 'new value']);
$ds->batch([['_id' => $rowId, 'Name' => 'ROW-1', 'Some_Field' => 'new value']]);
$deleted = $ds->delete('OLD-ROW');                      // false = it was already gone

// A different key column
$ds->keyedBy('Code')->get('C-42');                      // get/create/update/upsert/delete use "Code"

// Views, single-row create/upsert, images
$rows = $ds->view('My_View')->all();                    // list/each/all/batch go through the view
$ds->create(['Name' => 'ROW-2', 'Some_Field' => 'x']);
$res  = $ds->upsert(['Name' => 'ROW-2', 'Some_Field' => 'y']); // ['action' => 'created'|'updated', ...]
$ds->uploadImage($rowId, 'image_1', $jpegBytes, 'photo.jpg');
$ds->delete('ROW-2', $rowId);                           // _id as well, for datasets that need it

// Users
$byEmail = $kf->users()->byEmail();                     // ['someone@x.com' => [...user row...]]
$byField = $kf->users()->indexBy('Some_User_Field');    // index by any user column
```

### Errors

- A non-2xx response throws `Aliifz\Kissflow\Exceptions\ApiException`. It has `getStatus()`, `getResponseBody()`, `getResponseJson()`, `isNotFound()` and `isUnauthorized()`.
- A network failure throws `ApiException` with status `0`. The Guzzle exception is available as `getPrevious()`.
- Bad configuration throws `ConfigurationException`.
- `get()` returns `null` on a 404 instead of throwing.
- `delete()` returns `false` when the row was already gone (`DocumentNotFound`).

### Retries and pacing

HTTP 429, 502, 503, 504 and connection errors are retried up to `max_retries` times (default 2). The wait doubles each time, starting at `retry_delay_ms` (default 500). A `Retry-After` header overrides that wait.

Paginated listings pause for `page_delay_ms` (default 100) between pages.

## Laravel

The service provider and the `Kissflow` facade are auto-discovered.

```bash
php artisan vendor:publish --tag=kissflow-config   # optional
```

```dotenv
KISSFLOW_BASE_URL=https://your-subdomain.kissflow.com
KISSFLOW_ACCOUNT_ID=AcXXXXXXXXXX
KISSFLOW_ACCESS_KEY_ID=...
KISSFLOW_ACCESS_KEY_SECRET=...
# optional: KISSFLOW_VERIFY_SSL, KISSFLOW_TIMEOUT, KISSFLOW_MAX_RETRIES,
#           KISSFLOW_RETRY_DELAY_MS, KISSFLOW_PAGE_DELAY_MS, KISSFLOW_FORCE_HTTP11, KISSFLOW_LOG_CHANNEL
```

```php
use Aliifz\Kissflow\KissflowClient;
use Aliifz\Kissflow\Laravel\Kissflow;

Kissflow::dataset('My_Dataset')->all();
app(KissflowClient::class)->users()->all();   // or inject KissflowClient in a constructor
```

## Kissflow gotchas (learned in production)

- Dataset dropdown columns silently drop values that are not current dropdown options. If it matters, read the row back after writing.
- Dataset `DELETE` needs *delete* permission on that dataset for the API key. Without it you get HTTP 401 (`KISSFLOW_ERROR_00014`).
- `PUT` / `GET` / `DELETE` on a dataset identify the row by its key column (`Name` by default, see `keyedBy()`), not `_id`.
- The users endpoint may return a bare JSON list instead of the `{"Data": [...]}` envelope. `users()->all()` handles both.
- Some datasets need the row's `_id` as well as the key in the `DELETE` body. Use `delete($key, $rowId)`.
- `batch()` is **asynchronous**: a 2xx means the batch was accepted, and the rows change minutes later. `create()` / `update()` / `upsert()` apply at once. Do not check a batch write by reading the row back immediately.
- Rows created in the Kissflow UI get an internal `_id` that differs from their `Name`. A batch row whose `_id` equals its `Name` will not match them, and forcing `Name = _id` renames them. `upsert()` looks the row up by its key and sends its real `_id`.
- A view's batch endpoint can be update-only: rows that do not exist yet are skipped. Create new rows with `create()` / `upsert()` instead.
- JSON create/update ignore base64 image fields. Upload images with `uploadImage()` (multipart, part name `images`).

## Migrating from an app-specific service

See [docs/migrating-netsuite-middleware.md](docs/migrating-netsuite-middleware.md) for a worked example of replacing a hand-written service with this client.

## Tests

```bash
composer install && composer test
```
