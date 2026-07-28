<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Infrastructure\PublicChannels;

final class PublicAssets
{
    /**
     * Must match app.tropikal.ai/frontend/embed/core.js. Including the upstream
     * release in the connector hash invalidates every proxied embed asset URL
     * when the control-plane bundle changes.
     */
    private const EMBED_ASSET_VERSION = '20260726-1';

    private const FILES = [
        'public-channels.js' => 'application/javascript; charset=utf-8',
        'public-channels.css' => 'text/css; charset=utf-8',
    ];

    public static function version(): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, self::EMBED_ASSET_VERSION);
        foreach (array_keys(self::FILES) as $name) {
            hash_update($hash, self::contents($name));
        }

        return substr(hash_final($hash), 0, 16);
    }

    public static function contents(string $name): string
    {
        if (! isset(self::FILES[$name])) {
            throw new \InvalidArgumentException('Unknown public-channel asset.');
        }
        $path = dirname(__DIR__, 3).'/assets/'.$name;
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new \RuntimeException('The public-channel asset is unavailable.');
        }

        return $contents;
    }

    public static function contentType(string $name): string
    {
        return self::FILES[$name] ?? throw new \InvalidArgumentException('Unknown public-channel asset.');
    }
}
