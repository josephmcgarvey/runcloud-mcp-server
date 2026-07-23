<?php

namespace Tests\Feature;

use App\Exceptions\RunCloudException;
use App\Services\RunCloud\RunCloudClient;
use Illuminate\Support\Facades\Http;
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
}
