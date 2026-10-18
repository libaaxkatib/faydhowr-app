<?php

namespace Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A tiny in-process S3 endpoint (path-style) that plugs into the AWS SDK as its `http_handler`.
 *
 * It lets the tests drive the REAL chain - Laravel s3 disk -> Flysystem AwsS3V3Adapter -> AWS SDK (request signing,
 * checksum headers, XML parsing, error mapping) - with fake credentials and no network at all. It understands the handful
 * of calls the adapter makes: HEAD/GET/PUT/DELETE on an object, and ListObjectsV2 on the bucket. Every request is recorded
 * (with its headers, minus the Authorization value) so a test can assert exactly what the SDK sends.
 *
 * It is a test double, not proof of how Cloudflare R2 behaves: R2-specific behaviour still needs the approved rehearsal.
 */
class FakeS3Server
{
    /** @var array<string, array{bytes: string, type: string, modified: int}> object key => object */
    public array $objects = [];

    /** @var list<array{method: string, key: string, headers: array<string, string>, bytes: int}> */
    public array $requests = [];

    /** @var list<string> keys removed through DeleteObject */
    public array $deleted = [];

    private int $failWrites = 0;

    private int $corruptWrites = 0;

    private int $truncateWrites = 0;

    private int $failReads = 0;

    private bool $down = false;

    public function __construct(public readonly string $bucket = 'fake-documents') {}

    public function failNextWrites(int $count = 1): void
    {
        $this->failWrites = $count;
    }

    /** The next writes are stored with different bytes of the same length (the size check cannot see it). */
    public function corruptNextWrites(int $count = 1): void
    {
        $this->corruptWrites = $count;
    }

    public function truncateNextWrites(int $count = 1): void
    {
        $this->truncateWrites = $count;
    }

    public function failNextReads(int $count = 1): void
    {
        $this->failReads = $count;
    }

    /** Every request fails as if the endpoint could not be reached. */
    public function down(bool $down = true): void
    {
        $this->down = $down;
    }

    public function seed(string $key, string $bytes): void
    {
        $this->objects[$key] = ['bytes' => $bytes, 'type' => 'application/octet-stream', 'modified' => time()];
    }

    /** @return list<array{method: string, key: string, headers: array<string, string>, bytes: int}> */
    public function requestsOf(string $method): array
    {
        return array_values(array_filter($this->requests, fn (array $r) => $r['method'] === $method));
    }

    /** The settings to merge into an s3 disk so that its client talks to this server. */
    public function diskConfig(): array
    {
        return [
            'driver' => 's3',
            'key' => 'FAKEACCESSKEYID',
            'secret' => 'fake-secret-access-key-not-real',
            'region' => 'auto',
            'bucket' => $this->bucket,
            'endpoint' => 'https://fake-r2.invalid',
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            'retries' => 0,
            'http_handler' => $this->handler(),
        ];
    }

    public function handler(): callable
    {
        return function (RequestInterface $request, array $options = []) {
            if ($this->down) {
                return Create::rejectionFor([
                    'exception' => new ConnectException('fake endpoint unreachable', $request),
                    'connection_error' => true,
                ]);
            }

            $response = $this->respond($request);

            // The SDK's own (Guzzle) handler rejects every 4xx/5xx in this shape, and only then turns it into an S3Exception.
            if ($response->getStatusCode() >= 400) {
                return Create::rejectionFor([
                    'exception' => new RequestException('fake endpoint answered '.$response->getStatusCode(), $request, $response),
                    'connection_error' => false,
                    'response' => $response,
                ]);
            }

            return Create::promiseFor($response);
        };
    }

    private function respond(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = rawurldecode(ltrim($request->getUri()->getPath(), '/'));
        $prefix = $this->bucket.'/';
        parse_str($request->getUri()->getQuery(), $query);
        $key = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';

        $body = '';
        if ($method === 'PUT') {
            $request->getBody()->rewind();
            $body = (string) $request->getBody();
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = strtolower($name) === 'authorization' ? '[redacted]' : implode(',', $values);
        }
        $this->requests[] = ['method' => $method, 'key' => $key, 'headers' => $headers, 'bytes' => strlen($body)];

        if ($path !== rtrim($this->bucket, '/') && ! str_starts_with($path, $prefix)) {
            return $this->error(404, 'NoSuchBucket', $method);
        }

        if ($method === 'GET' && $key === '' && ($query['list-type'] ?? null) === '2') {
            return $this->list((string) ($query['prefix'] ?? ''));
        }

        return match ($method) {
            'PUT' => $this->put($key, $body, $headers),
            'HEAD' => $this->head($key),
            'GET' => $this->get($key),
            'DELETE' => $this->delete($key),
            default => $this->error(405, 'MethodNotAllowed', $method),
        };
    }

    private function put(string $key, string $body, array $headers): ResponseInterface
    {
        if ($this->failWrites > 0) {
            $this->failWrites--;

            return $this->error(403, 'AccessDenied', 'PUT');
        }

        if ($this->truncateWrites > 0) {
            $this->truncateWrites--;
            $body = substr($body, 0, max(0, intdiv(strlen($body), 2)));
        } elseif ($this->corruptWrites > 0) {
            $this->corruptWrites--;
            $body = strrev($body) === $body ? $body.'!' : strrev($body);
        }

        $this->objects[$key] = ['bytes' => $body, 'type' => $headers['content-type'] ?? 'application/octet-stream', 'modified' => time()];

        return new Response(200, ['ETag' => '"'.md5($body).'"']);
    }

    private function head(string $key): ResponseInterface
    {
        if (! isset($this->objects[$key])) {
            return new Response(404);
        }

        return new Response(200, $this->objectHeaders($key));
    }

    private function get(string $key): ResponseInterface
    {
        if ($this->failReads > 0) {
            $this->failReads--;

            return $this->error(500, 'InternalError', 'GET');
        }

        if (! isset($this->objects[$key])) {
            return $this->error(404, 'NoSuchKey', 'GET');
        }

        return new Response(200, $this->objectHeaders($key), $this->objects[$key]['bytes']);
    }

    private function delete(string $key): ResponseInterface
    {
        unset($this->objects[$key]);
        $this->deleted[] = $key;

        return new Response(204);
    }

    private function list(string $prefix): ResponseInterface
    {
        $items = '';
        foreach ($this->objects as $key => $object) {
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                $items .= '<Contents><Key>'.htmlspecialchars($key, ENT_XML1).'</Key><LastModified>'.gmdate('Y-m-d\TH:i:s.000\Z', $object['modified']).'</LastModified>'
                    .'<ETag>"'.md5($object['bytes']).'"</ETag><Size>'.strlen($object['bytes']).'</Size><StorageClass>STANDARD</StorageClass></Contents>';
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>'.$this->bucket.'</Name>'
            .'<Prefix>'.htmlspecialchars($prefix, ENT_XML1).'</Prefix><IsTruncated>false</IsTruncated>'.$items.'</ListBucketResult>';

        return new Response(200, ['Content-Type' => 'application/xml'], $xml);
    }

    /** @return array<string, string> */
    private function objectHeaders(string $key): array
    {
        $object = $this->objects[$key];

        return [
            'Content-Length' => (string) strlen($object['bytes']),
            'Content-Type' => $object['type'],
            'ETag' => '"'.md5($object['bytes']).'"',
            'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $object['modified']),
        ];
    }

    private function error(int $status, string $code, string $method): ResponseInterface
    {
        // A HEAD response never carries a body.
        $xml = $method === 'HEAD' ? '' : '<?xml version="1.0" encoding="UTF-8"?><Error><Code>'.$code.'</Code><Message>'.$code.'</Message></Error>';

        return new Response($status, $method === 'HEAD' ? [] : ['Content-Type' => 'application/xml'], $xml);
    }
}
