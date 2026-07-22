<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpHttpTransportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ];
    }

    public function test_it_rejects_an_unauthenticated_request_with_401(): void
    {
        $this->postJson('/mcp', $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ])->assertStatus(401);
    }

    /**
     * A guest hitting the endpoint without an Accept header must still get a 401. Laravel would
     * otherwise try to redirect to a nonexistent "login" route and return a 500, which reads as a
     * server fault rather than an auth failure.
     */
    public function test_it_returns_401_rather_than_redirecting_when_json_is_not_requested(): void
    {
        $this->call('POST', '/mcp', [], [], [], [], '{}')
            ->assertStatus(401);
    }

    public function test_it_advertises_oauth_resource_metadata_when_unauthenticated(): void
    {
        $response = $this->postJson('/mcp', $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        $this->assertStringContainsString(
            'resource_metadata=',
            (string) $response->headers->get('WWW-Authenticate'),
            'Claude Desktop reads this header to discover the authorization server.'
        );
    }

    /**
     * Guard order in routes/ai.php is load-bearing: Passport's guard aborts the chain when it
     * cannot resolve a token, so listing it before Sanctum rejects valid Sanctum tokens.
     */
    public function test_a_sanctum_token_authenticates_against_the_mcp_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->postJson('/mcp', $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();
    }

    public function test_it_exposes_oauth_discovery_documents(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonStructure([
                'issuer',
                'authorization_endpoint',
                'token_endpoint',
                'registration_endpoint',
                'code_challenge_methods_supported',
            ]);

        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonStructure(['resource', 'authorization_servers', 'scopes_supported']);
    }
}
