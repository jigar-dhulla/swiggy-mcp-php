<?php

declare(strict_types=1);

namespace JigarDhulla\SwiggyMcp\Tests\Fakes;

use Psr\Log\AbstractLogger;
use Stringable;

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public function named(string $message): array
    {
        return array_values(array_filter($this->records, fn (array $record): bool => $record['message'] === $message));
    }
}
