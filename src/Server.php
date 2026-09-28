<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp;

/**
 * Swiggy's public MCP servers. Tool names are unique across servers, so one
 * access token can drive all four.
 */
enum Server: string
{
    case Food = 'food';
    case Instamart = 'im';
    case Dineout = 'dineout';
    case Scenes = 'scenes';

    public function url(string $baseUrl = Swiggy::BASE_URL): string
    {
        return rtrim($baseUrl, '/').'/'.$this->value;
    }
}
