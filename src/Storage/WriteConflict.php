<?php

declare(strict_types=1);

namespace Segara\Storage;

use RuntimeException;

final class WriteConflict extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $currentRevision)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self('Page no longer exists; reload the page list before saving.', null);
    }

    public static function stale(int $currentRevision): self
    {
        return new self(
            sprintf('Page has a newer revision (%d); reload before saving to avoid overwriting it.', $currentRevision),
            $currentRevision
        );
    }

    public function isMissing(): bool
    {
        return $this->currentRevision === null;
    }

    public function isStale(): bool
    {
        return $this->currentRevision !== null;
    }
}
