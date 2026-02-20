<?php

declare(strict_types=1);

namespace Sentinel\Normalization;

class EndpointNormalizer
{
    /** @var array<string, string> */
    private array $customPatterns = [];

    /**
     * Add a custom regex pattern to normalization
     */
    public function addPattern(string $pattern, string $replacement): self
    {
        $this->customPatterns[$pattern] = $replacement;
        return $this;
    }

    /**
     * Normalize a given URI path string into a generic endpoint identifier.
     * Optionally strip query arguments.
     */
    public function normalize(string $method, string $uri, bool $stripQueries = true): string
    {
        $path = $uri;
        if ($stripQueries) {
            $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
        }

        // Apply custom developer patterns first
        foreach ($this->customPatterns as $pattern => $replacement) {
            $path = (string) (preg_replace($pattern, $replacement, $path) ?? $path);
        }

        // UUID segments
        $path = (string) (preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '/{uuid}', $path) ?? $path);
        
        // Pure numeric IDs
        $path = (string) (preg_replace('/\/[0-9]+\b/', '/{id}', $path) ?? $path);
        
        // Hash segments (hex strings >= 8 characters)
        $path = (string) (preg_replace('/\/[0-9a-fA-F]{8,}\b/', '/{hash}', $path) ?? $path);
        
        return strtoupper($method) . ' ' . $path;
    }
}
