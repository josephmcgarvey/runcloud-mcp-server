<?php

namespace App\Mcp\Tools\Concerns;

trait InteractsWithFirewallRules
{
    /**
     * The port SSH listens on. Rules covering it are what stand between an administrator and being
     * locked out of a server permanently.
     */
    protected const SSH_PORT = 22;

    /**
     * Read a server's firewall rules as a flat list.
     *
     * RunCloud returns these as a bare array rather than in a data envelope, and a single rule as a
     * bare object, so both shapes are normalised here.
     *
     * @return list<array<string, mixed>>
     */
    protected function firewallRules(int|string $serverId): array
    {
        $payload = $this->client->get("servers/{$serverId}/security/firewalls");

        if (array_key_exists('data', $payload) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        if (array_key_exists('id', $payload)) {
            return [$payload];
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /**
     * Whether a port specification covers a given port.
     *
     * RunCloud accepts ranges such as "8000-8999" and "0-99999" alongside single ports, so a string
     * comparison would miss a range that includes the port in question.
     */
    protected function portSpecCovers(string $spec, int $port): bool
    {
        $spec = trim($spec);

        if (str_contains($spec, '-')) {
            [$from, $to] = array_pad(explode('-', $spec, 2), 2, null);

            if (is_numeric($from) && is_numeric($to)) {
                return $port >= (int) $from && $port <= (int) $to;
            }

            return false;
        }

        return is_numeric($spec) && (int) $spec === $port;
    }

    /**
     * Whether the ruleset leaves any route in over SSH.
     *
     * A global rule on the port, or any rich accept rule covering it, is enough. Deploying a
     * ruleset without one removes the only means of fixing the ruleset.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    protected function permitsSsh(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (! $this->portSpecCovers((string) ($rule['port'] ?? ''), self::SSH_PORT)) {
                continue;
            }

            if (($rule['type'] ?? null) === 'global') {
                return true;
            }

            if (($rule['firewallAction'] ?? null) === 'accept') {
                return true;
            }
        }

        return false;
    }

    /**
     * Rules permitting SSH, for showing an operator what their way back in actually is.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<string>
     */
    protected function sshAllowList(array $rules): array
    {
        $allowed = [];

        foreach ($rules as $rule) {
            if (! $this->portSpecCovers((string) ($rule['port'] ?? ''), self::SSH_PORT)) {
                continue;
            }

            if (($rule['type'] ?? null) === 'global') {
                $allowed[] = 'anywhere (global rule, port '.($rule['port'] ?? '?').')';
            } elseif (($rule['firewallAction'] ?? null) === 'accept') {
                $allowed[] = (string) ($rule['ipAddress'] ?? 'unknown address');
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Whether a string is a usable IPv4/IPv6 address or CIDR range.
     *
     * RunCloud does not reject malformed addresses reliably, and a rule that never matches fails
     * silently — the operator believes they have access until they try to use it.
     */
    protected function isValidAddressOrCidr(string $value): bool
    {
        $value = trim($value);

        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $value, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (! ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $max;
    }
}
