<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mcp:token {email : The email of the user to issue the token for} {--name=mcp-client : A label identifying the client}')]
#[Description('Issue a Sanctum token for connecting an MCP client to this server')]
class IssueMcpToken extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user found with the email [{$email}].");

            return self::FAILURE;
        }

        $name = (string) $this->option('name');

        $token = $user->createToken($name)->plainTextToken;

        $this->components->info("Token issued for {$user->email} as [{$name}].");
        $this->newLine();
        $this->line($token);
        $this->newLine();
        $this->components->warn('This token grants access to RunCloud infrastructure. It is shown once — store it securely.');

        return self::SUCCESS;
    }
}
