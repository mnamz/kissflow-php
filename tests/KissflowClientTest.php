<?php

declare(strict_types=1);

namespace Aliifz\Kissflow\Tests;

use Aliifz\Kissflow\Config;
use Aliifz\Kissflow\Exceptions\ApiException;
use Aliifz\Kissflow\Exceptions\ConfigurationException;
use Aliifz\Kissflow\KissflowClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class KissflowClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @var int[] */
    private array $sleeps = [];

    private function client(array $responses, array $config = []): KissflowClient
    {
        $this->history = [];
        $this->sleeps = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new KissflowClient(
            Config::fromArray($config + [
                'base_url' => 'https://acme.kissflow.com/',
                'account_id' => 'ACC1',
                'access_key_id' => 'kid',
                'access_key_secret' => 'secret',
            ]),
            new Client(['handler' => $stack]),
            null,
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }

    private static function json(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }

    private function sent(int $i = 0): RequestInterface
    {
        return $this->history[$i]['request'];
    }

    private function query(int $i = 0): array
    {
        parse_str($this->sent($i)->getUri()->getQuery(), $q);

        return $q;
    }

    private function body(int $i = 0): mixed
    {
        return json_decode((string) $this->sent($i)->getBody(), true);
    }

    // ---- config / auth -------------------------------------------------------

    public function test_missing_credentials_throw(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray(['base_url' => 'https://a.kissflow.com', 'account_id' => 'A', 'access_key_id' => 'x']);
    }

    public function test_sends_auth_headers_and_strips_trailing_slash(): void
    {
        $kf = $this->client([self::json(['Data' => []])]);
        $kf->users()->list();

        $req = $this->sent();
        $this->assertSame('kid', $req->getHeaderLine('X-Access-Key-Id'));
        $this->assertSame('secret', $req->getHeaderLine('X-Access-Key-Secret'));
        $this->assertSame('https://acme.kissflow.com/user/2/ACC1/list', (string) $req->getUri()->withQuery(''));
    }

    // ---- process ---------------------------------------------------------------

    public function test_process_find_by_field(): void
    {
        $kf = $this->client([self::json(['Data' => [['_id' => 'Pk1', 'Order_No' => 'ORD-001']]])]);

        $item = $kf->process('Proc_A')->findBy('Order_No', 'ORD-001');

        $this->assertSame('Pk1', $item['_id']);
        $this->assertSame('/process/2/ACC1/admin/Proc_A/item', $this->sent()->getUri()->getPath());
        $this->assertSame(['page_number' => '1', 'page_size' => '1', 'q' => 'ORD-001', 'search_field' => 'Order_No'], $this->query());
    }

    public function test_process_find_id_handles_lowercase_envelope_and_no_match(): void
    {
        $kf = $this->client([
            self::json(['data' => [['_id' => 'Pk2']]]),
            self::json(['Data' => []]),
        ]);
        $p = $kf->process('Proc_B');

        $this->assertSame('Pk2', $p->findIdBy('Ref_No', 'REF-1'));
        $this->assertNull($p->findIdBy('Ref_No', 'nope'));
    }

    public function test_process_get_unwraps_and_returns_null_on_404(): void
    {
        $kf = $this->client([
            self::json(['Data' => ['_id' => 'Pk1', 'Table::Model_X' => [['a' => 1]]]]),
            self::json(['_id' => 'Pk2']),
            new Response(404, [], '{"error":"not found"}'),
        ]);
        $p = $kf->process('Proc_B');

        $this->assertSame([['a' => 1]], $p->get('Pk1')['Table::Model_X']);
        $this->assertSame('/process/2/ACC1/admin/Proc_B/Pk1', $this->sent()->getUri()->getPath());
        $this->assertSame('Pk2', $p->get('Pk2')['_id']); // no envelope
        $this->assertNull($p->get('missing'));
    }

    // ---- dataset ---------------------------------------------------------------

    public function test_dataset_all_paginates_until_short_page(): void
    {
        $kf = $this->client([
            self::json(['Data' => [['Name' => 'A'], ['Name' => 'B']]]),
            self::json(['Data' => [['Name' => 'C'], ['Name' => 'D']]]),
            self::json(['Data' => [['Name' => 'E']]]),
        ], ['page_delay_ms' => 7]);

        $rows = $kf->dataset('DS_A')->all(pageSize: 2);

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_column($rows, 'Name'));
        $this->assertCount(3, $this->history);
        $this->assertSame('/dataset/2/ACC1/DS_A/list', $this->sent()->getUri()->getPath());
        $this->assertSame('3', $this->query(2)['page_number']);
        $this->assertSame([7, 7], $this->sleeps);
    }

    public function test_dataset_all_stops_on_empty_page(): void
    {
        $kf = $this->client([
            self::json(['Data' => [['Name' => 'A']]]),
            self::json(['Data' => []]),
        ]);

        $this->assertCount(1, $kf->dataset('X')->all(pageSize: 1));
        $this->assertCount(2, $this->history);
    }

    public function test_dataset_get_by_name(): void
    {
        $kf = $this->client([
            self::json(['Name' => 'ROW-1', 'Amount' => '10']),
            new Response(404, [], ''),
        ]);
        $ds = $kf->dataset('DS_A');

        $this->assertSame('10', $ds->get('ROW-1')['Amount']);
        $this->assertSame('/dataset/2/ACC1/DS_A', $this->sent()->getUri()->getPath());
        $this->assertSame(['Name' => 'ROW-1'], $this->query());
        $this->assertNull($ds->get('missing'));
    }

    public function test_dataset_update_puts_name_plus_fields(): void
    {
        $kf = $this->client([self::json(['_id' => 'x'])]);

        $kf->dataset('DS_A')->update('ROW-1', ['Amount' => '123.45']);

        $this->assertSame('PUT', $this->sent()->getMethod());
        $this->assertSame(['Name' => 'ROW-1', 'Amount' => '123.45'], $this->body());
    }

    public function test_dataset_batch_posts_list_and_honours_url_override(): void
    {
        $kf = $this->client([self::json(['ok' => 1]), self::json(['ok' => 2])]);
        $rows = ['k1' => ['_id' => '1', 'Name' => '1'], 'k2' => ['_id' => '2', 'Name' => '2']];

        $kf->dataset('DS_B')->batch($rows);
        $kf->dataset('DS_B')->withBatchUrl('https://other.kissflow.com/dataset/2/Z/DS_B_Sandbox/batch')->batch($rows);

        $this->assertSame('POST', $this->sent()->getMethod());
        $this->assertSame('/dataset/2/ACC1/DS_B/batch', $this->sent()->getUri()->getPath());
        $this->assertSame([['_id' => '1', 'Name' => '1'], ['_id' => '2', 'Name' => '2']], $this->body());
        $this->assertSame('https://other.kissflow.com/dataset/2/Z/DS_B_Sandbox/batch', (string) $this->sent(1)->getUri());
    }

    public function test_dataset_batch_chunked_and_empty_is_noop(): void
    {
        $kf = $this->client([self::json([]), self::json([])]);
        $ds = $kf->dataset('X');

        $this->assertSame([], $ds->batch([]));
        $this->assertCount(0, $this->history);

        $this->assertCount(2, $ds->batchChunked(array_fill(0, 3, ['Name' => 'a']), 2));
        $this->assertCount(1, $this->body(1));
    }

    public function test_dataset_delete(): void
    {
        $kf = $this->client([
            new Response(200, [], '{}'),
            new Response(404, [], '{"error_code":"DocumentNotFound"}'),
            new Response(404, [], '{"message":"Dataset missing"}'),
        ]);
        $ds = $kf->dataset('DS_B');

        $this->assertTrue($ds->delete('123'));
        $this->assertSame('DELETE', $this->sent()->getMethod());
        $this->assertSame(['Name' => '123'], $this->body());
        $this->assertFalse($ds->delete('123'));

        $this->expectException(ApiException::class);
        $ds->delete('123');
    }

    public function test_dataset_delete_401_reports_permission_hint(): void
    {
        $kf = $this->client([new Response(401, [], '{"error_code":"KISSFLOW_ERROR_00014"}')]);

        try {
            $kf->dataset('V')->delete('1');
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertTrue($e->isUnauthorized());
            $this->assertStringContainsString('delete permission', $e->getMessage());
            $this->assertSame('KISSFLOW_ERROR_00014', $e->getResponseJson()['error_code']);
        }
    }

    public function test_dataset_delete_sends_id_when_given(): void
    {
        $kf = $this->client([new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $ds = $kf->dataset('DS_C');

        $this->assertTrue($ds->delete('ROW-9', 'PkCT0u123'));
        $this->assertSame(['Name' => 'ROW-9', '_id' => 'PkCT0u123'], $this->body());

        $ds->delete('ROW-9', '');
        $this->assertSame(['Name' => 'ROW-9'], $this->body(1));
    }

    // ---- views -------------------------------------------------------------------

    public function test_view_scopes_list_and_batch_but_not_row_calls(): void
    {
        $kf = $this->client([
            self::json(['Data' => [['Name' => 'a'], ['Name' => 'b']]]),
            self::json(['ok' => 1]),
            self::json(['Name' => 'a']),
            self::json(['Data' => []]),
        ]);
        $base = $kf->dataset('DS_C');
        $scoped = $base->view('My View');

        $this->assertCount(2, $scoped->all(999));
        $this->assertSame('/dataset/2/ACC1/DS_C/view/My%20View/list', $this->sent()->getUri()->getPath());
        $this->assertSame(['page_number' => '1', 'page_size' => '999'], $this->query());

        $scoped->batch([['_id' => 'a', 'Name' => 'a']]);
        $this->assertSame('/dataset/2/ACC1/DS_C/view/My%20View/batch', $this->sent(1)->getUri()->getPath());

        $scoped->get('a');
        $this->assertSame('/dataset/2/ACC1/DS_C', $this->sent(2)->getUri()->getPath());

        // view() returns a clone: the base resource is unscoped.
        $this->assertNull($base->viewId());
        $base->list();
        $this->assertSame('/dataset/2/ACC1/DS_C/list', $this->sent(3)->getUri()->getPath());
    }

    public function test_view_rejects_empty_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->dataset('X')->view(' ');
    }

    // ---- create / upsert -------------------------------------------------------

    public function test_dataset_create_posts_fields(): void
    {
        $kf = $this->client([self::json(['_id' => 'E1', 'Name' => 'E1'])]);

        $out = $kf->dataset('DS_C')->create(['Name' => 'E1', 'Label' => 'Hello']);

        $this->assertSame('POST', $this->sent()->getMethod());
        $this->assertSame('/dataset/2/ACC1/DS_C', $this->sent()->getUri()->getPath());
        $this->assertSame(['Name' => 'E1', 'Label' => 'Hello'], $this->body());
        $this->assertSame('E1', $out['_id']);
    }

    public function test_dataset_create_requires_name(): void
    {
        $kf = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $kf->dataset('X')->create(['Name' => '  ', 'Other' => 1]);
    }

    public function test_upsert_creates_when_missing(): void
    {
        $kf = $this->client([new Response(404, [], ''), self::json(['_id' => 'E1'])]);

        $out = $kf->dataset('DS_C')->upsert(['Name' => 'E1', 'Code' => 'C-1']);

        $this->assertSame('created', $out['action']);
        $this->assertNull($out['existing']);
        $this->assertSame('GET', $this->sent()->getMethod());
        $this->assertSame('POST', $this->sent(1)->getMethod());
        $this->assertSame(['Name' => 'E1', 'Code' => 'C-1'], $this->body(1));
    }

    public function test_upsert_updates_with_existing_internal_id(): void
    {
        // A UI-created row: its _id is an internal id, not its Name.
        $kf = $this->client([
            self::json(['_id' => 'PkCT0uXYZ', 'Name' => 'E1', 'Code' => 'old']),
            self::json(['_id' => 'PkCT0uXYZ']),
        ]);

        $out = $kf->dataset('DS_C')->upsert(['_id' => 'E1', 'Name' => 'E1', 'Code' => 'new']);

        $this->assertSame('updated', $out['action']);
        $this->assertSame('old', $out['existing']['Code']);
        $this->assertSame('PUT', $this->sent(1)->getMethod());
        $this->assertSame(['Name' => 'E1', '_id' => 'PkCT0uXYZ', 'Code' => 'new'], $this->body(1));
    }

    public function test_upsert_requires_name(): void
    {
        $kf = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $kf->dataset('X')->upsert(['_id' => 'only-id']);
    }

    public function test_keyed_by_uses_custom_key_field_everywhere(): void
    {
        $kf = $this->client([
            new Response(404, [], ''),                       // upsert: get
            self::json(['_id' => 'x']),                      // upsert: create
            self::json(['_id' => 'x']),                      // update
            new Response(200, [], '{}'),                     // delete
        ]);
        $base = $kf->dataset('DS_A');
        $ds = $base->keyedBy('Code');

        $this->assertSame('Name', $base->keyField());
        $this->assertSame('Code', $ds->keyField());

        $this->assertSame('created', $ds->upsert(['Code' => ' C-1 ', 'F' => 1])['action']);
        $this->assertSame(['Code' => 'C-1'], $this->query(0));
        $this->assertSame(['Code' => 'C-1', 'F' => 1], $this->body(1));

        $ds->update('C-1', ['F' => 2]);
        $this->assertSame(['Code' => 'C-1', 'F' => 2], $this->body(2));

        $ds->delete('C-1', 'Pk1');
        $this->assertSame(['Code' => 'C-1', '_id' => 'Pk1'], $this->body(3));
    }

    public function test_keyed_by_requires_key_on_create_and_rejects_empty_field(): void
    {
        $ds = $this->client([])->dataset('X')->keyedBy('Code');

        try {
            $ds->create(['Name' => 'only-name']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"Code"', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $ds->keyedBy('');
    }

    // ---- images ------------------------------------------------------------------

    public function test_upload_image_sends_multipart(): void
    {
        $kf = $this->client([self::json(['ok' => true])]);

        $kf->dataset('DS_C')->uploadImage('PkCT0u/1', 'image_1', "\xFF\xD8jpegbytes", 'r1.jpg');

        $req = $this->sent();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/dataset/2/ACC1/DS_C/PkCT0u%2F1/image_1/image', $req->getUri()->getPath());
        $this->assertStringStartsWith('multipart/form-data; boundary=', $req->getHeaderLine('Content-Type'));
        $this->assertSame('kid', $req->getHeaderLine('X-Access-Key-Id'));

        $body = (string) $req->getBody();
        $this->assertStringContainsString('name="images"; filename="r1.jpg"', $body);
        $this->assertStringContainsString('Content-Type: image/jpeg', $body);
        $this->assertStringContainsString("\xFF\xD8jpegbytes", $body);
    }

    public function test_upload_image_is_retried_with_full_body(): void
    {
        $kf = $this->client([new Response(503), self::json(['ok' => true])]);

        $kf->dataset('U')->uploadImage('r1', 'image_1', 'bytes');

        $this->assertCount(2, $this->history);
        $this->assertStringContainsString('bytes', (string) $this->sent(1)->getBody());
    }

    public function test_upload_image_rejects_empty_contents(): void
    {
        $kf = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $kf->dataset('U')->uploadImage('r1', 'image_1', '');
    }

    public function test_request_rejects_json_plus_multipart(): void
    {
        $kf = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $kf->request('POST', '/x', json: ['a' => 1], multipart: [['name' => 'f', 'contents' => 'x']]);
    }

    // ---- users -----------------------------------------------------------------

    public function test_users_all_accepts_bare_list(): void
    {
        $kf = $this->client([self::json([['_id' => 'u1', 'Email' => 'A@x.com'], ['_id' => 'u2', 'Email' => 'b@x.com']])]);

        $map = $kf->users()->byEmail();

        $this->assertSame(['a@x.com', 'b@x.com'], array_keys($map));
        $this->assertCount(1, $this->history);
    }

    public function test_users_index_by_any_field(): void
    {
        $kf = $this->client([self::json(['Data' => [
            ['_id' => 'u1', 'EmpNo' => 'E7'],
            ['_id' => 'u2', 'EmpNo' => ''],
            ['_id' => 'u3', 'empno' => 'e8'],
        ]])]);

        $map = $kf->users()->indexBy('EmpNo');

        $this->assertSame(['E7', 'e8'], array_keys($map));
        $this->assertSame('u3', $map['e8']['_id']);
    }

    public function test_users_all_paginates_envelope(): void
    {
        $kf = $this->client([
            self::json(['Data' => [['_id' => 'u1'], ['_id' => 'u2']]]),
            self::json(['Data' => [['_id' => 'u3']]]),
        ]);

        $this->assertCount(3, $kf->users()->all(pageSize: 2));
    }

    // ---- errors / retry --------------------------------------------------------

    public function test_non_2xx_throws_api_exception_with_body(): void
    {
        $kf = $this->client([new Response(400, [], '{"message":"bad field"}')]);

        try {
            $kf->dataset('X')->update('k', ['F' => 1]);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('PUT', $e->getMethod());
            $this->assertStringContainsString('bad field', $e->getMessage());
        }
    }

    public function test_retries_429_with_retry_after_then_succeeds(): void
    {
        $kf = $this->client([
            new Response(429, ['Retry-After' => '2'], ''),
            new Response(503, [], ''),
            self::json(['Data' => []]),
        ], ['retry_delay_ms' => 100]);

        $this->assertSame([], $kf->users()->list());
        $this->assertCount(3, $this->history);
        $this->assertSame([2000, 200], $this->sleeps); // Retry-After honoured, then exponential
    }

    public function test_gives_up_after_max_retries(): void
    {
        $kf = $this->client([new Response(429), new Response(429)], ['max_retries' => 1, 'retry_delay_ms' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(429);
        $kf->users()->list();
    }

    public function test_connect_error_is_retried_then_wrapped(): void
    {
        $req = new Request('GET', 'https://acme.kissflow.com');
        $kf = $this->client([
            new ConnectException('timeout', $req),
            new ConnectException('timeout', $req),
        ], ['max_retries' => 1, 'retry_delay_ms' => 1]);

        try {
            $kf->users()->list();
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(0, $e->getStatus());
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }
}
