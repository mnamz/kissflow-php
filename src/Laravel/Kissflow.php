<?php

declare(strict_types=1);

namespace Aliifz\Kissflow\Laravel;

use Aliifz\Kissflow\KissflowClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Aliifz\Kissflow\Resources\ProcessResource process(string $processId)
 * @method static \Aliifz\Kissflow\Resources\DatasetResource dataset(string $datasetId)
 * @method static \Aliifz\Kissflow\Resources\UserResource users()
 * @method static \Aliifz\Kissflow\Response request(string $method, string $path, array $query = [], ?array $json = null, array $allowStatuses = [], ?array $multipart = null)
 *
 * @see KissflowClient
 */
class Kissflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return KissflowClient::class;
    }
}
