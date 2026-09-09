<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DocumentStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentStorageTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $sourcePaths = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/vhod-documents-'.bin2hex(random_bytes(8));
        $this->sourcePaths = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);

        foreach ($this->sourcePaths as $path) {
            @unlink($path);
        }
    }

    public function testStoresPdfWithRandomServerName(): void
    {
        $stored = $this->storage()->store($this->upload("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF", 'rules.exe'));

        self::assertSame('rules.exe', $stored->originalName);
        self::assertSame('application/pdf', $stored->mimeType);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $stored->storageName);
        self::assertFileExists($stored->absolutePath);
    }

    public function testStoresGeneratedPdfBytesPrivately(): void
    {
        self::assertTrue(method_exists(DocumentStorage::class, 'storeGeneratedPdf'), 'Generated PDF storage API has not been implemented yet.');

        $stored = $this->storage()->storeGeneratedPdf('meeting-invitation.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        self::assertSame('meeting-invitation.pdf', $stored->originalName);
        self::assertSame('application/pdf', $stored->mimeType);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $stored->storageName);
        self::assertFileExists($stored->absolutePath);
        self::assertSame("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF", file_get_contents($stored->absolutePath));
    }

    public function testGeneratedPdfRejectsInvalidHeaderEmptyAndOversizedBytes(): void
    {
        self::assertTrue(method_exists(DocumentStorage::class, 'storeGeneratedPdf'), 'Generated PDF storage API has not been implemented yet.');
        $storage = $this->storage();

        foreach (['', 'not-a-pdf', str_repeat('A', 16 * 1024 * 1024 + 1)] as $bytes) {
            try {
                $storage->storeGeneratedPdf('generated.pdf', $bytes);
                self::fail('Invalid generated PDF bytes must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame([], glob($this->directory.'/*') ?: []);
            }
        }
    }

    public function testStoresPngWithFixedExtension(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);

        $stored = $this->storage()->store($this->upload($bytes, 'image.txt'));

        self::assertSame('image/png', $stored->mimeType);
        self::assertStringEndsWith('.png', $stored->storageName);
    }

    public function testStoresJpegAndWebpWithFixedExtensions(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        $webp = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA', true);
        self::assertIsString($webp);

        $jpegStored = $this->storage()->store($this->upload($jpeg, 'photo.bin'));
        $webpStored = $this->storage()->store($this->upload($webp, 'photo.bin'));

        self::assertSame('image/jpeg', $jpegStored->mimeType);
        self::assertStringEndsWith('.jpg', $jpegStored->storageName);
        self::assertSame('image/webp', $webpStored->mimeType);
        self::assertStringEndsWith('.webp', $webpStored->storageName);
    }

    public function testRejectsTextAndPhpContent(): void
    {
        $storage = $this->storage();

        foreach (["plain text\n", '<?php echo "x";'] as $content) {
            try {
                $storage->store($this->upload($content, 'document.pdf'));
                self::fail('Unsafe text content must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame([], glob($this->directory.'/*') ?: []);
            }
        }
    }

    public function testRejectsEmptyAndOversizedFiles(): void
    {
        $storage = $this->storage();

        foreach (['', str_repeat('A', 16 * 1024 * 1024 + 1)] as $content) {
            try {
                $storage->store($this->upload($content, 'large.pdf'));
                self::fail('Invalid document size must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame([], glob($this->directory.'/*') ?: []);
            }
        }
    }

    public function testPathResolutionRejectsTraversalAndRemoveDeletesStoredFile(): void
    {
        $storage = $this->storage();
        $stored = $storage->store($this->upload("%PDF-1.4\n%%EOF", 'document.pdf'));

        self::assertSame($stored->absolutePath, $storage->pathFor($stored->storageName));
        $storage->remove($stored->storageName);
        self::assertFileDoesNotExist($stored->absolutePath);

        $this->expectException(InvalidArgumentException::class);
        $storage->pathFor('../secret.pdf');
    }

    private function storage(): DocumentStorage
    {
        self::assertTrue(class_exists(DocumentStorage::class), 'DocumentStorage has not been implemented yet.');

        return new DocumentStorage($this->directory);
    }

    private function upload(string $content, string $originalName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vhod-doc-source-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->sourcePaths[] = $path;

        return new UploadedFile($path, $originalName, null, null, true);
    }
}
