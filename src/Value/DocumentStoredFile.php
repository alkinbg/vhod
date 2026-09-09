<?php

declare(strict_types=1);

namespace App\Value;

final readonly class DocumentStoredFile
{
    public function __construct(
        public string $originalName,
        public string $storageName,
        public string $mimeType,
        public int $sizeBytes,
        public string $absolutePath,
    ) {}
}
