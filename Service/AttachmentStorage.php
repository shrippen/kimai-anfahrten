<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores receipts below Kimai's data directory (var/data/mileage/{user id}/).
 */
class AttachmentStorage
{
    public const MAX_SIZE = 10 * 1024 * 1024;

    public const ALLOWED_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public function __construct(
        #[Autowire('%kimai.data_dir%')]
        private readonly string $dataDir,
    ) {
    }

    /**
     * @throws \InvalidArgumentException with a translation key
     */
    public function store(User $user, UploadedFile $file): Attachment
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('mileage.attachment.error.upload');
        }
        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException('mileage.attachment.error.size');
        }

        // Detect the type from the content, never trust the client.
        $mime = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_TYPES[$mime])) {
            throw new \InvalidArgumentException('mileage.attachment.error.type');
        }

        $directory = $this->directory($user);
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create ' . $directory);
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_TYPES[$mime];
        $size = (int) $file->getSize();
        $file->move($directory, $name);

        return (new Attachment($name, mb_substr($file->getClientOriginalName(), 0, 255), $mime, $size))->setUser($user);
    }

    public function path(Attachment $attachment): string
    {
        $user = $attachment->getUser();
        if ($user === null || !preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $attachment->getStoredName())) {
            throw new \RuntimeException('Invalid attachment');
        }

        return $this->directory($user) . '/' . $attachment->getStoredName();
    }

    public function delete(Attachment $attachment): void
    {
        $path = $this->path($attachment);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function directory(User $user): string
    {
        return rtrim($this->dataDir, '/') . '/mileage/' . (int) $user->getId();
    }
}
