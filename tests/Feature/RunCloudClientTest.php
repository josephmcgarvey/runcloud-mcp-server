<?php

namespace Tests\Feature;

use App\Exceptions\RunCloudException;
use App\Services\RunCloud\RunCloudClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RunCloudClientTest extends TestCase
{
    protected function client(): RunCloudClient
    {
        return new RunCloudClient(
            token: 'test-token',
            baseUrl: 'https://manage.runcloud.io/api/v3',
            timeout: 5,
        );
    }

    public function test_it_sends_a_bearer_token_and_json_headers(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->get('servers');

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request->url() === 'https://manage.runcloud.io/api/v3/servers'
        );
    }

    public function test_it_clamps_per_page_to_the_runcloud_maximum(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->paginate('servers', page: 2, perPage: 500);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'perPage=40')
            && str_contains($request->url(), 'page=2')
        );
    }

    public function test_it_raises_the_floor_on_invalid_pagination_input(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->paginate('servers', page: 0, perPage: 0);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'perPage=1')
            && str_contains($request->url(), 'page=1')
        );
    }

    public function test_it_translates_an_unauthenticated_response(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        try {
            $this->client()->get('servers');
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('RUNCLOUD_API_TOKEN', $e->getMessage());
        }
    }

    public function test_it_surfaces_validation_errors_to_the_agent(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['name' => ['The name field is required.']],
        ], 422)]);

        try {
            $this->client()->post('servers', []);
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('name: The name field is required.', $e->toAgentMessage());
        }
    }

    /**
     * RunCloud scopes API keys per resource type, so a 403 usually means the key is missing a
     * permission rather than that the caller got the request wrong. The message has to say so, or
     * the failure reads as a bug in the tool.
     */
    public function test_it_points_at_api_key_permissions_on_a_forbidden_response(): void
    {
        Http::fake(['*' => Http::response(['message' => 'This action is unauthorized.'], 403)]);

        try {
            $this->client()->get('servers/1/webapps/1/git');
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString('API Key', $e->getMessage());
        }
    }

    public function test_it_reports_rate_limiting_distinctly(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

        try {
            $this->client()->get('servers');
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertStringContainsString('rate limit', $e->getMessage());
        }
    }

    public function test_it_strips_deploy_keys_from_a_wrapped_list_response(): void
    {
        Http::fake(['*' => Http::response(['data' => [[
            'id' => 646,
            'name' => 'app-schimmel',
            'pullKey1' => 'rlNDkqV0UpEISUgM526HUEJJ1720508533',
            'pullKey2' => 'Zs3lqh0HymHDA6cs5uLHRZNVJPMVJTox',
        ]]])]);

        $result = $this->client()->get('servers/1903/webapps');

        $this->assertSame('app-schimmel', $result['data'][0]['name']);
        $this->assertArrayNotHasKey('pullKey1', $result['data'][0]);
        $this->assertArrayNotHasKey('pullKey2', $result['data'][0]);
    }

    public function test_it_strips_deploy_keys_from_a_bare_object_response(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 657,
            'name' => 'webappalias2',
            'pullKey1' => 'pJopomQpRYEtE5i9EnoPot8W1721183122',
            'pullKey2' => 'OKwYifh4tJyVajZIMV3PmZCcTEl4joWN',
        ])]);

        $result = $this->client()->delete('servers/1903/webapps/657');

        $this->assertSame('webappalias2', $result['name']);
        $this->assertArrayNotHasKey('pullKey1', $result);
        $this->assertArrayNotHasKey('pullKey2', $result);
    }

    public function test_it_strips_deploy_keys_nested_at_any_depth(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['deployment' => ['webapp' => ['pullKey1' => 'secret', 'name' => 'nested']]],
        ])]);

        $result = $this->client()->get('anything');

        $this->assertSame('nested', $result['data']['deployment']['webapp']['name']);
        $this->assertArrayNotHasKey('pullKey1', $result['data']['deployment']['webapp']);
    }

    public function test_it_leaves_unrelated_fields_untouched(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 646,
            'rootPath' => '/home/runcloud/webapps/app-schimmel',
            'phpVersion' => 'php81rc',
            'meta' => ['pagination' => ['total' => 2, 'links' => []]],
        ])]);

        $result = $this->client()->get('servers/1903/webapps/646');

        $this->assertSame('/home/runcloud/webapps/app-schimmel', $result['rootPath']);
        $this->assertSame('php81rc', $result['phpVersion']);
        $this->assertSame(2, $result['meta']['pagination']['total']);
    }

    public function test_it_returns_an_empty_array_when_the_body_is_not_json(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertSame([], $this->client()->get('servers'));
    }

    public function test_it_retries_a_get_after_a_server_error(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Server Error'], 503)
            ->push(['data' => [['id' => 1]]], 200),
        ]);

        $result = $this->client()->get('servers');

        $this->assertSame(1, $result['data'][0]['id']);
        Http::assertSentCount(2);
    }

    public function test_it_retries_a_get_after_a_connection_failure(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['data' => []], 200),
        ]);

        $this->assertSame(['data' => []], $this->client()->get('servers'));
    }

    public function test_it_stops_retrying_a_get_after_the_maximum_attempts(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        try {
            $this->client()->get('servers');
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertSame(500, $e->status);
        }

        Http::assertSentCount(RunCloudClient::MAX_ATTEMPTS);
    }

    public function test_it_does_not_retry_a_write_after_a_server_error(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        try {
            $this->client()->post('servers/1/webapps/custom', ['name' => 'app']);
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertSame(500, $e->status);
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_it_does_not_retry_a_write_after_a_connection_failure(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::failedConnection()]);

        try {
            $this->client()->delete('servers/1/webapps/2');
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException) {
            Http::assertSentCount(1);
        }
    }

    public function test_it_does_not_retry_a_client_error(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        try {
            $this->client()->get('servers/999');
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertSame(404, $e->status);
        }

        Http::assertSentCount(1);
    }

    public function test_it_retries_a_rate_limited_write_after_the_retry_after_delay(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '3'])
            ->push(['id' => 7], 200),
        ]);

        $result = $this->client()->post('servers/1/cronjobs', ['label' => 'backup']);

        $this->assertSame(7, $result['id']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(3000)->milliseconds()]);
    }

    public function test_it_waits_one_second_on_a_rate_limit_without_retry_after(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Too Many Attempts.'], 429)
            ->push(['data' => []], 200),
        ]);

        $this->client()->get('servers');

        Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
    }

    public function test_it_fails_fast_when_the_rate_limit_wait_is_too_long(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response(
            ['message' => 'Too Many Attempts.'],
            429,
            ['Retry-After' => '60', 'X-RateLimit-Limit' => '30'],
        )]);

        try {
            $this->client()->get('servers');
            $this->fail('Expected a RunCloudException.');
        } catch (RunCloudException $e) {
            $this->assertSame(429, $e->status);
            $this->assertStringContainsString('allows 30 requests', $e->getMessage());
            $this->assertStringContainsString('Retry after 60 seconds', $e->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }
}
