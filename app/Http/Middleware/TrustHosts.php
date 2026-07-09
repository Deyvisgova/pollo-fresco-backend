<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))));
        $escaped = array_map(fn (string $host) => '^'.preg_quote($host, '/'). '$', $configured);

        return array_values(array_filter([
            $this->allSubdomainsOfApplicationUrl(),
            ...$escaped,
        ]));
    }
}
