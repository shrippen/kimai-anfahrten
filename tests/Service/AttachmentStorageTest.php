<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Service\AttachmentStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AttachmentStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mileage-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function upload(string $content, string $name): UploadedFile
    {
        $tmp = tempnam($this->dir, 'up');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    public function testStoresPdfWithRandomName(): void
    {
        $storage = new AttachmentStorage($this->dir);
        $attachment = $storage->store(new User(7), $this->upload("%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", '../../evil name.pdf'));

        self::assertSame('application/pdf', $attachment->getMimeType());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $attachment->getStoredName());
        self::assertSame('evil name.pdf', $attachment->getOriginalName());
        self::assertFileExists($this->dir . '/mileage/7/' . $attachment->getStoredName());
        self::assertSame($this->dir . '/mileage/7/' . $attachment->getStoredName(), $storage->path($attachment));

        $storage->delete($attachment);
        self::assertFileDoesNotExist($this->dir . '/mileage/7/' . $attachment->getStoredName());
    }

    public function testRejectsScriptsEvenWithPdfExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attachment.error.type');

        (new AttachmentStorage($this->dir))->store(new User(7), $this->upload('<?php echo 1;', 'receipt.pdf'));
    }
}
