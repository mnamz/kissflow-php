<?php

declare(strict_types=1);

namespace Aliifz\Kissflow;

use Aliifz\Kissflow\Exceptions\ApiException;
use Aliifz\Kissflow\Resources\DatasetResource;
use Aliifz\Kissflow\Resources\ProcessResource;
use Aliifz\Kissflow\Resources\UserResource;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Entry point for the Kissflow REST API.
 *
 *   $kf = KissflowClient::make([...]);
 *   $kf->process('My_Process')->findBy('Some_Field', 'value');
 *   $kf->dataset('My_Dataset')->all();
 *   $kf->users()->all();
 *
 * Auth is the X-Access-Key-Id / X-Access-Key-Secret header pair on every request.
 */
class KissflowClient
{
    /** Statuses worth retrying: rate limit and transient gateway errors. */
    private const RETRY_STATUSES = [429, 502, 503, 504];

    private ClientInterface $http;

    private LoggerInterface $logger;

    /** @var Closure(int):void sleeps for the given milliseconds */
    private Closure $sleeper;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $http = null,
        ?LoggerInterface $logger = null,
        ?Closure $sleeper = null,
    ) {
        $this->http = $http ?? new GuzzleClient($this->guzzleOptions());
        $this->logger = $logger ?? new NullLogger();
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    /**
     * Shortcut: KissflowClient::make(['base_url' => ..., 'account_id' => ..., ...]).
     */
    public static function make(array $config, ?ClientInterface $http = null, ?LoggerInterface $logger = null): self
    {
        return new self(Config::fromArray($config), $http, $logger);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function process(string $processId): ProcessResource
    {
        return new ProcessResource($this, $processId);
    }

    public function dataset(string $datasetId): DatasetResource
    {
        return new DatasetResource($this, $datasetId);
    }

    public function users(): UserResource
    {
        return new UserResource($this);
    }

    /**
     * Send a request and return the response. Non-2xx statuses throw ApiException unless
     * listed in $allowStatuses (then the Response is returned for the caller to inspect).
     *
     * @param  string  $path  path relative to baseUrl (e.g. "/dataset/2/acc/X/list") or an absolute URL
     * @param  array|null  $json  JSON body (a list or a map); null sends no body
     * @param  int[]  $allowStatuses  non-2xx statuses that should not throw
     * @param  array|null  $multipart  Guzzle multipart parts (for file uploads); cannot be combined with $json
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, array $allowStatuses = [], ?array $multipart = null): Response
    {
        if ($json !== null && $multipart !== null) {
            throw new \InvalidArgumentException('A Kissflow request cannot have both a JSON body and a multipart body.');
        }

        $method = strtoupper($method);
        $url = preg_match('#^https?://#i', $path) ? $path : $this->config->baseUrl.'/'.ltrim($path, '/');

        $options = [
            RequestOptions::HEADERS => [
                'X-Access-Key-Id' => $this->config->accessKeyId,
                'X-Access-Key-Secret' => $this->config->accessKeySecret,
                'Accept' => 'application/json',
            ],
            RequestOptions::HTTP_ERRORS => false,
        ];
        if ($query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }
        if ($json !== null) {
            $options[RequestOptions::JSON] = $json;
        }
        if ($multipart !== null) {
            $options[RequestOptions::MULTIPART] = $multipart;
        }

        $attempt = 0;
        while (true) {
            try {
                $psr = $this->http->request($method, $url, $options);
                $response = new Response($psr->getStatusCode(), (string) $psr->getBody(), $psr->getHeaders());
            } catch (ConnectException $e) {
                if ($attempt < $this->config->maxRetries) {
                    $this->backoff($attempt++, null, $method, $url, 'connect error: '.$e->getMessage());

                    continue;
                }
                throw new ApiException("Kissflow {$method} {$url} failed: ".$e->getMessage(), 0, '', $method, $url, $e);
            }

            if (in_array($response->status, self::RETRY_STATUSES, true) && $attempt < $this->config->maxRetries) {
                $retryAfter = $response->headers['Retry-After'][0] ?? $response->headers['retry-after'][0] ?? null;
                $this->backoff($attempt++, $retryAfter, $method, $url, 'HTTP '.$response->status);

                continue;
            }

            break;
        }

        $this->logger->debug('Kissflow request', ['method' => $method, 'url' => $url, 'status' => $response->status]);

        if (! $response->successful() && ! in_array($response->status, $allowStatuses, true)) {
            $this->logger->error('Kissflow API error', [
                'method' => $method,
                'url' => $url,
                'status' => $response->status,
                'response' => mb_substr($response->body, 0, 1000),
            ]);
            throw ApiException::fromResponse($method, $url, $response->status, $response->body);
        }

        return $response;
    }

    /**
     * Pause between pages of a paginated listing (rate-limit courtesy).
     */
    public function pageDelay(): void
    {
        ($this->sleeper)($this->config->pageDelayMs);
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    private function backoff(int $attempt, ?string $retryAfter, string $method, string $url, string $reason): void
    {
        $ms = $retryAfter !== null && ctype_digit(trim($retryAfter))
            ? (int) $retryAfter * 1000
            : $this->config->retryDelayMs * (2 ** $attempt);

        $this->logger->warning('Kissflow request retry', [
            'method' => $method,
            'url' => $url,
            'reason' => $reason,
            'attempt' => $attempt + 1,
            'delay_ms' => $ms,
        ]);

        ($this->sleeper)($ms);
    }

    private function guzzleOptions(): array
    {
        $options = [
            RequestOptions::VERIFY => $this->config->verifySsl,
            RequestOptions::TIMEOUT => $this->config->timeout,
        ];

        // Some hosts' Kissflow connections stall on HTTP/2 negotiation; pin HTTP/1.1 + TLS 1.2.
        if ($this->config->forceHttp11 && defined('CURLOPT_HTTP_VERSION')) {
            $options['curl'] = [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ];
        }

        return $options;
    }
}
