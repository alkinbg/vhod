<?php

declare(strict_types=1);

namespace App\Service;

use App\Value\DocumentStoredFile;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class DocumentStorage
{
    private const MAX_SIZE = 16 * 1024 * 1024;

    /** @var array<string, string> */
    private const EXTENSIONS_BY_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/documents')]
        private string $storageDirectory,
    ) {}

    public function store(UploadedFile $file): DocumentStoredFile
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Document upload is invalid.');
        }

        $size = $file->getSize();
        if (false === $size || $size <= 0 || $size > self::MAX_SIZE) {
            throw new InvalidArgumentException('Document must be between 1 byte and 16 MiB.');
        }

        $mimeType = $this->detectMimeType($file);
        if (!isset(self::EXTENSIONS_BY_MIME[$mimeType])) {
            throw new InvalidArgumentException('Only PDF, JPEG, PNG and WebP documents are allowed.');
        }

        $directory = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create document storage directory.');
        }

        $originalName = $file->getClientOriginalName();
        $storageName = bin2hex(random_bytes(16)).'.'.self::EXTENSIONS_BY_MIME[$mimeType];
        $moved = $file->move($directory, $storageName);

        return new DocumentStoredFile(
            $originalName,
            $storageName,
            $mimeType,
            (int) $size,
            $moved->getPathname(),
        );
    }

    public function pathFor(string $storageName): string
    {
        $this->assertStorageName($storageName);

        return rtrim($this->storageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$storageName;
    }

    public function remove(string $storageName): void
    {
        $path = $this->pathFor($storageName);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Cannot remove document file.');
        }
    }

    private function detectMimeType(UploadedFile $file): string
    {
        if (!class_exists(\finfo::class)) {
            throw new RuntimeException('PHP fileinfo extension is required for document uploads.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (false === $mimeType || '' === $mimeType) {
            throw new InvalidArgumentException('Cannot determine document MIME type.');
        }

        return $mimeType;
    }

    private function assertStorageName(string $storageName): void
    {
        if (1 !== preg_match('/^[a-f0-9]{32}\.(?:pdf|jpg|png|webp)$/', $storageName)) {
            throw new InvalidArgumentException('Invalid document storage name.');
        }
    }
}
