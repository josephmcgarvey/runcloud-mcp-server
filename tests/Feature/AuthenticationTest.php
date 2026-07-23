<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $password = 'a-correct-horse-battery-staple'): User
    {
        return User::factory()->create([
            'email' => 'operator@example.com',
            'password' => Hash::make($password),
        ]);
    }

    /**
     * These pages deliberately avoid @vite. A consent screen that renders unstyled on a fresh
     * deployment is where someone is being asked to grant access to infrastructure.
     */
    public function test_the_login_page_renders_without_a_built_asset_bundle(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('/build/assets', false);
    }

    public function test_a_user_can_sign_in(): void
    {
        $user = $this->user();

        $this->post('/login', [
            'email' => 'operator@example.com',
            'password' => 'a-correct-horse-battery-staple',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        $this->user();

        $this->post('/login', [
            'email' => 'operator@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * This page is publicly reachable, so a message that distinguished the two would confirm which
     * accounts exist on the server.
     */
    public function test_it_does_not_reveal_whether_an_account_exists(): void
    {
        $this->user();

        $identical = ['email' => 'Those credentials do not match our records.'];

        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertSessionHasErrors($identical);

        $this->flushSession();

        $this->post('/login', ['email' => 'operator@example.com', 'password' => 'x'])
            ->assertSessionHasErrors($identical);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $this->user();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'operator@example.com', 'password' => 'wrong'])
                ->assertStatus(302);
        }

        $this->post('/login', ['email' => 'operator@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_an_authenticated_user_can_sign_out(): void
    {
        $this->actingAs($this->user())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_there_is_no_self_service_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    /**
     * Passport throws an authentication exception when the consent screen is reached without a
     * session. Before the login page existed that redirect had nowhere to go and produced a 500.
     */
    public function test_a_guest_reaching_a_web_route_is_sent_to_the_login_page(): void
    {
        $this->post('/logout')->assertRedirect(route('login'));
    }

    /**
     * The counterpart to the redirect above: MCP clients speak JSON-RPC and must keep receiving a
     * 401 rather than an HTML login page, which would read as a server fault.
     */
    public function test_the_mcp_endpoint_still_answers_guests_with_401_not_a_redirect(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], [
            'Accept' => 'application/json, text/event-stream',
        ])->assertStatus(401);
    }

    public function test_an_api_route_answers_guests_with_401_not_a_redirect(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_the_consent_view_is_registered_with_passport(): void
    {
        $this->assertTrue(
            view()->exists('oauth.authorize'),
            'Passport renders this view for the consent screen; without it the OAuth flow cannot complete.'
        );
    }
}
