<?php

declare(strict_types=1);

namespace App\Service;

use App\Value\MaintenanceStoredFile;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MaintenanceAttachmentStorage
{
    private const MAX_SIZE = 8 * 1024 * 1024;

    /** @var array<string, string> */
    private const EXTENSIONS_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/maintenance')]
        private string $storageDirectory,
    ) {}

    public function store(UploadedFile $file): MaintenanceStoredFile
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Maintenance attachment upload is invalid.');
        }

        $size = $file->getSize();
        if (false === $size || $size <= 0 || $size > self::MAX_SIZE) {
            throw new InvalidArgumentException('Maintenance attachment must be between 1 byte and 8 MiB.');
        }

        $mimeType = $this->detectMimeType($file);
        if (!isset(self::EXTENSIONS_BY_MIME[$mimeType])) {
            throw new InvalidArgumentException('Only JPEG, PNG, WebP and PDF attachments are allowed.');
        }

        $directory = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create maintenance attachment storage directory.');
        }

        $storageName = bin2hex(random_bytes(16)).'.'.self::EXTENSIONS_BY_MIME[$mimeType];
        $moved = $file->move($directory, $storageName);

        return new MaintenanceStoredFile(
            $file->getClientOriginalName(),
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
            throw new RuntimeException('Cannot remove maintenance attachment file.');
        }
    }

    private function detectMimeType(UploadedFile $file): string
    {
        if (!class_exists(\finfo::class)) {
            throw new RuntimeException('PHP fileinfo extension is required for maintenance attachments.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (false === $mimeType || '' === $mimeType) {
            throw new InvalidArgumentException('Cannot determine maintenance attachment MIME type.');
        }

        return $mimeType;
    }

    private function assertStorageName(string $storageName): void
    {
        if (1 !== preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$/', $storageName)) {
            throw new InvalidArgumentException('Invalid maintenance attachment storage name.');
        }
    }
}
