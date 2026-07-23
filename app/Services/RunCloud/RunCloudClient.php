<?php

namespace App\Services\RunCloud;

use App\Exceptions\RunCloudException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin transport layer over the RunCloud v3 REST API.
 *
 * This is deliberately not a passthrough HTTP API of its own — MCP tools call
 * these methods directly. Resource-specific paths live in the tools; this class
 * owns only auth, retries, pagination defaults, and error translation.
 */
class RunCloudClient
{
    /**
     * RunCloud rejects any perPage above this value.
     */
    public const MAX_PER_PAGE = 40;

    /**
     * Credentials RunCloud embeds in ordinary resource payloads.
     *
     * Web application and deployment responses carry Git deploy keys inline. Nothing an MCP client
     * does needs them, and anything returned to a tool may be transcribed into a conversation or a
     * log, so they are stripped at the transport boundary rather than per tool. If a future feature
     * genuinely needs one, fetch it deliberately instead of widening this list.
     *
     * privateKey is accepted when installing a custom SSL certificate. RunCloud does not document
     * the shape of its SSL responses at all, so it is stripped defensively rather than on evidence
     * that it is echoed back — a leaked TLS private key is not a recoverable mistake. The
     * certificate itself is deliberately not redacted: it is public material served to every
     * browser, and seeing it is often the point of the request.
     *
     * @var list<string>
     */
    public const REDACTED_KEYS = ['pullKey1', 'pullKey2', 'privateKey', 'private_key'];

    public function __construct(
        protected readonly string $token,
        protected readonly string $baseUrl,
        protected readonly int $timeout = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws RunCloudException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RunCloudException
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RunCloudException
     */
    public function patch(string $path, array $payload = []): array
    {
        return $this->send('patch', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RunCloudException
     */
    public function put(string $path, array $payload = []): array
    {
        return $this->send('put', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RunCloudException
     */
    public function delete(string $path, array $payload = []): array
    {
        return $this->send('delete', $path, $payload);
    }

    /**
     * Fetch a paginated collection, clamping perPage to what RunCloud accepts.
     *
     * @return array<string, mixed>
     *
     * @throws RunCloudException
     */
    public function paginate(string $path, int $page = 1, int $perPage = self::MAX_PER_PAGE, array $query = []): array
    {
        return $this->get($path, array_merge($query, [
            'page' => max(1, $page),
            'perPage' => min(max(1, $perPage), self::MAX_PER_PAGE),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RunCloudException
     */
    protected function send(string $method, string $path, array $data): array
    {
        $response = $this->request()->{$method}(ltrim($path, '/'), $data);

        if ($response->failed()) {
            throw RunCloudException::fromResponse($response);
        }

        return $this->decode($response);
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry(2, 250, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $this->redact($decoded) : [];
    }

    /**
     * Recursively strip embedded credentials from a decoded payload.
     *
     * Applied to every response regardless of shape, because RunCloud nests resources
     * inconsistently — list endpoints wrap in "data" while single-resource and delete endpoints
     * return bare objects.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::REDACTED_KEYS, true)) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
