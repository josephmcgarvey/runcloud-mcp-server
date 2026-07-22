<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

class RunCloudException extends Exception
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Build an exception from a failed RunCloud API response, surfacing the
     * validation details RunCloud returns so the agent can correct itself.
     */
    public static function fromResponse(Response $response): self
    {
        $body = $response->json() ?? [];
        $status = $response->status();

        $message = match ($status) {
            401 => 'RunCloud authentication failed. Check RUNCLOUD_API_TOKEN.',
            403 => 'RunCloud denied this request. The token lacks permission for this resource.',
            404 => 'The requested RunCloud resource does not exist.',
            429 => 'RunCloud rate limit exceeded. Retry after a short delay.',
            default => $body['message'] ?? "RunCloud API request failed with status {$status}.",
        };

        return new self($message, $status, $body['errors'] ?? []);
    }

    /**
     * A description safe to hand back to an MCP client.
     */
    public function toAgentMessage(): string
    {
        if ($this->errors === []) {
            return $this->getMessage();
        }

        $details = collect($this->errors)
            ->map(fn (mixed $messages, string $field): string => $field.': '.implode(' ', (array) $messages))
            ->implode('; ');

        return $this->getMessage().' ('.$details.')';
    }
}
