<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Support;

/**
 * @internal
 */
final class Uuid
{
    /**
     * A random RFC 4122 version 4 UUID, used as the JSON-RPC request id that
     * Swiggy asks for when a call is escalated.
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
