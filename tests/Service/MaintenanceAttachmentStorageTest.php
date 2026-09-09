<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MaintenanceAttachmentStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MaintenanceAttachmentStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/vhod-maintenance-storage-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testStoresAllowedPngWithRandomServerFilename(): void
    {
        $upload = $this->pngUpload('снимка.png');
        $storage = new MaintenanceAttachmentStorage($this->directory);

        $stored = $storage->store($upload);

        self::assertSame('снимка.png', $stored->originalName);
        self::assertSame('image/png', $stored->mimeType);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $stored->storageName);
        self::assertFileExists($stored->absolutePath);
        self::assertSame($stored->absolutePath, $storage->pathFor($stored->storageName));
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $path = $this->directory.'/script.php';
        file_put_contents($path, '<?php echo "bad";');
        $upload = new UploadedFile($path, 'script.php', null, null, true);

        $this->expectException(InvalidArgumentException::class);
        (new MaintenanceAttachmentStorage($this->directory))->store($upload);
    }

    public function testRejectsFileLargerThanEightMiB(): void
    {
        $path = $this->directory.'/large.bin';
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        fseek($handle, (8 * 1024 * 1024));
        fwrite($handle, 'x');
        fclose($handle);
        $upload = new UploadedFile($path, 'large.png', 'image/png', null, true);

        $this->expectException(InvalidArgumentException::class);
        (new MaintenanceAttachmentStorage($this->directory))->store($upload);
    }

    public function testPathForRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MaintenanceAttachmentStorage($this->directory))->pathFor('../secret.pdf');
    }

    public function testRemoveDeletesOnlyValidatedStoredName(): void
    {
        $storage = new MaintenanceAttachmentStorage($this->directory);
        $stored = $storage->store($this->pngUpload('photo.png'));
        self::assertFileExists($stored->absolutePath);

        $storage->remove($stored->storageName);

        self::assertFileDoesNotExist($stored->absolutePath);
    }

    private function pngUpload(string $originalName): UploadedFile
    {
        $path = $this->directory.'/upload-'.bin2hex(random_bytes(4)).'.png';
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $originalName, null, null, true);
    }
}
