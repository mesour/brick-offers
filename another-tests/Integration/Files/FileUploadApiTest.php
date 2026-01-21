<?php declare(strict_types = 1);

namespace Tests\Integration\Files;

use App\Files\Database\File;
use App\Files\Database\FileFolder;
use App\Files\Database\FileFolderRepository;
use App\Files\Database\FileRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for File Upload API endpoints.
 *
 * @group integration
 * @group database
 */
final class FileUploadApiTest extends ApiIntegrationTestCase
{
    private FileRepository $fileRepository;
    private FileFolderRepository $folderRepository;

    /**
     * @var array<string>
     */
    private array $tempFiles = [];

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Doctrine\DBAL\Exception
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipTests) {
            return;
        }

        $this->fileRepository = $this->getService(FileRepository::class);
        $this->folderRepository = $this->getService(FileFolderRepository::class);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        foreach ($this->tempFiles as $tmpFile) {
            if (\file_exists($tmpFile)) {
                @\unlink($tmpFile);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    private function createTrackedTempFile(string $content, string $extension = 'txt'): string
    {
        $tmpPath = $this->createTempFile($content, $extension);
        $this->tempFiles[] = $tmpPath;

        return $tmpPath;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function createTrackedTempImage(int $width = 100, int $height = 100, string $format = 'jpeg'): string
    {
        $tmpPath = $this->createTempImage($width, $height, $format);
        $this->tempFiles[] = $tmpPath;

        return $tmpPath;
    }

    // =========================================================================
    // POST /api/file-upload
    // =========================================================================

    #[Test]
    public function uploadSingleFile(): void
    {
        $tmpPath = $this->createTrackedTempFile('Hello World', 'txt');
        $fileSize = (int) \filesize($tmpPath);

        $fileUpload = $this->createFileUpload(
            $tmpPath,
            'test_file.txt',
            'text/plain',
            $fileSize,
        );

        $result = $this->apiPostWithFiles('fileUpload', [], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('files', $data);
        self::assertIsArray($data['files']);
        self::assertCount(1, $data['files']);
        self::assertIsArray($data['files'][0]);
        // Note: filename is sanitized (underscores replaced with dashes)
        self::assertEquals('test-file.txt', $data['files'][0]['originalFilename']);
        self::assertEquals('text/plain', $data['files'][0]['mimeType']);
    }

    #[Test]
    public function uploadMultipleFiles(): void
    {
        $tmpPath1 = $this->createTrackedTempFile('File 1 content', 'txt');
        $tmpPath2 = $this->createTrackedTempFile('File 2 content', 'txt');

        $file1 = $this->createFileUpload($tmpPath1, 'file1.txt', 'text/plain', (int) \filesize($tmpPath1));
        $file2 = $this->createFileUpload($tmpPath2, 'file2.txt', 'text/plain', (int) \filesize($tmpPath2));

        $result = $this->apiPostWithFiles('fileUpload', [], [
            'file0' => $file1,
            'file1' => $file2,
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertCount(2, $data['files']);
    }

    #[Test]
    public function uploadImageFile(): void
    {
        $tmpPath = $this->createTrackedTempImage(200, 150, 'jpeg');
        $fileSize = (int) \filesize($tmpPath);

        $fileUpload = $this->createFileUpload(
            $tmpPath,
            'photo.jpg',
            'image/jpeg',
            $fileSize,
        );

        $result = $this->apiPostWithFiles('fileUpload', [], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertCount(1, $data['files']);
        self::assertIsArray($data['files'][0]);
        // Note: .jpg extension is normalized to .jpeg
        self::assertEquals('photo.jpeg', $data['files'][0]['originalFilename']);
        self::assertEquals('image/jpeg', $data['files'][0]['mimeType']);
    }

    #[Test]
    public function uploadPngFile(): void
    {
        $tmpPath = $this->createTrackedTempImage(100, 100, 'png');
        $fileSize = (int) \filesize($tmpPath);

        $fileUpload = $this->createFileUpload(
            $tmpPath,
            'image.png',
            'image/png',
            $fileSize,
        );

        $result = $this->apiPostWithFiles('fileUpload', [], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertIsArray($data['files'][0]);
        self::assertEquals('image/png', $data['files'][0]['mimeType']);
    }

    #[Test]
    public function uploadFileToFolder(): void
    {
        $folder = new FileFolder('Upload Target', null);
        $this->folderRepository->save($folder);

        $tmpPath = $this->createTrackedTempFile('Content', 'txt');
        $fileUpload = $this->createFileUpload($tmpPath, 'file.txt', 'text/plain', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileUpload', [
            'folderId' => (string) $folder->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertIsArray($data['files'][0]);
        self::assertEquals($folder->getId(), $data['files'][0]['folderId']);
    }

    #[Test]
    public function uploadFileToNonExistentFolderReturns404(): void
    {
        $tmpPath = $this->createTrackedTempFile('Content', 'txt');
        $fileUpload = $this->createFileUpload($tmpPath, 'file.txt', 'text/plain', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileUpload', [
            'folderId' => '999999',
        ], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function uploadNoFileReturns400(): void
    {
        $result = $this->apiPostWithFiles('fileUpload', [], []);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('NO_FILE', $result);
    }

    #[Test]
    public function uploadedFileHasCorrectSize(): void
    {
        $content = \str_repeat('X', 1024); // 1KB
        $tmpPath = $this->createTrackedTempFile($content, 'txt');
        $fileSize = (int) \filesize($tmpPath);

        $fileUpload = $this->createFileUpload($tmpPath, 'data.txt', 'text/plain', $fileSize);

        $result = $this->apiPostWithFiles('fileUpload', [], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertIsArray($data['files'][0]);
        self::assertEquals($fileSize, $data['files'][0]['size']);
    }

    #[Test]
    public function uploadedFileGetsUniqueFilename(): void
    {
        // Upload first file
        $tmpPath1 = $this->createTrackedTempFile('First', 'txt');
        $file1 = $this->createFileUpload($tmpPath1, 'same_name.txt', 'text/plain', (int) \filesize($tmpPath1));
        $result1 = $this->apiPostWithFiles('fileUpload', [], ['file0' => $file1]);
        $this->assertApiStatusCode(201, $result1);
        $data1 = $result1->getJsonData();
        self::assertIsArray($data1);
        self::assertIsArray($data1['files']);
        self::assertIsArray($data1['files'][0]);

        // Upload second file with same original name
        $tmpPath2 = $this->createTrackedTempFile('Second', 'txt');
        $file2 = $this->createFileUpload($tmpPath2, 'same_name.txt', 'text/plain', (int) \filesize($tmpPath2));
        $result2 = $this->apiPostWithFiles('fileUpload', [], ['file0' => $file2]);
        $this->assertApiStatusCode(201, $result2);
        $data2 = $result2->getJsonData();
        self::assertIsArray($data2);
        self::assertIsArray($data2['files']);
        self::assertIsArray($data2['files'][0]);

        // Both should have the same original filename but different stored filenames
        self::assertEquals($data1['files'][0]['originalFilename'], $data2['files'][0]['originalFilename']);
        self::assertNotEquals($data1['files'][0]['filename'], $data2['files'][0]['filename']);
    }

    // =========================================================================
    // POST /api/file-replace
    // =========================================================================

    #[Test]
    public function replaceFileWithSameType(): void
    {
        // Create original file in database
        $file = new File('old_file.jpg', 'photo.jpg', '/uploads/old_file.jpg', 'image/jpeg', 1000);
        $this->fileRepository->save($file);

        // Create new image to replace it
        $tmpPath = $this->createTrackedTempImage(200, 200, 'jpeg');
        $fileUpload = $this->createFileUpload($tmpPath, 'new_photo.jpg', 'image/jpeg', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('image/jpeg', $data['mimeType']);
        self::assertNotEquals('old_file.jpg', $data['filename']); // New filename generated
    }

    #[Test]
    public function replaceImageWithDifferentImageFormat(): void
    {
        // Create original JPEG in database
        $file = new File('photo.jpg', 'photo.jpg', '/uploads/photo.jpg', 'image/jpeg', 1000);
        $this->fileRepository->save($file);

        // Replace with PNG (same category: image/*)
        $tmpPath = $this->createTrackedTempImage(100, 100, 'png');
        $fileUpload = $this->createFileUpload($tmpPath, 'photo.png', 'image/png', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('image/png', $data['mimeType']);
    }

    #[Test]
    public function replaceImageWithTextFails(): void
    {
        // Create original image in database
        $file = new File('photo.jpg', 'photo.jpg', '/uploads/photo.jpg', 'image/jpeg', 1000);
        $this->fileRepository->save($file);

        // Try to replace with text file (different category)
        $tmpPath = $this->createTrackedTempFile('Hello World', 'txt');
        $fileUpload = $this->createFileUpload($tmpPath, 'file.txt', 'text/plain', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('INVALID_MIME_TYPE', $result);
    }

    #[Test]
    public function replaceTextWithImageFails(): void
    {
        // Create original text file in database
        $file = new File('doc.txt', 'document.txt', '/uploads/doc.txt', 'text/plain', 100);
        $this->fileRepository->save($file);

        // Try to replace with image file (different category)
        $tmpPath = $this->createTrackedTempImage(100, 100, 'jpeg');
        $fileUpload = $this->createFileUpload($tmpPath, 'photo.jpg', 'image/jpeg', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('INVALID_MIME_TYPE', $result);
    }

    #[Test]
    public function replaceNonExistentFileReturns404(): void
    {
        $tmpPath = $this->createTrackedTempFile('Content', 'txt');
        $fileUpload = $this->createFileUpload($tmpPath, 'file.txt', 'text/plain', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => '999999',
        ], ['file0' => $fileUpload]);

        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function replaceWithNoFileReturns400(): void
    {
        $file = new File('photo.jpg', 'photo.jpg', '/uploads/photo.jpg', 'image/jpeg', 1000);
        $this->fileRepository->save($file);

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], []);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('NO_FILE', $result);
    }

    #[Test]
    public function replaceUpdatesFileSize(): void
    {
        // Create file with known size
        $file = new File('photo.jpg', 'photo.jpg', '/uploads/photo.jpg', 'image/jpeg', 1000);
        $this->fileRepository->save($file);
        $originalSize = $file->getSize();

        // Replace with larger image
        $tmpPath = $this->createTrackedTempImage(300, 300, 'jpeg');
        $newSize = (int) \filesize($tmpPath);
        $fileUpload = $this->createFileUpload($tmpPath, 'big.jpg', 'image/jpeg', $newSize);

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals($newSize, $data['size']);
        self::assertNotEquals($originalSize, $data['size']);
    }

    #[Test]
    public function replaceKeepsSameFolderId(): void
    {
        $folder = new FileFolder('My Folder', null);
        $this->folderRepository->save($folder);

        $file = new File('photo.jpg', 'photo.jpg', '/uploads/photo.jpg', 'image/jpeg', 1000);
        $file->setFolderId($folder->getId());
        $this->fileRepository->save($file);

        $tmpPath = $this->createTrackedTempImage(100, 100, 'jpeg');
        $fileUpload = $this->createFileUpload($tmpPath, 'new.jpg', 'image/jpeg', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals($folder->getId(), $data['folderId']);
    }

    #[Test]
    public function replaceWithGifImage(): void
    {
        // Create original JPEG
        $file = new File('image.jpg', 'image.jpg', '/uploads/image.jpg', 'image/jpeg', 1000);
        $this->fileRepository->save($file);

        // Replace with GIF (still image/*)
        $tmpPath = $this->createTrackedTempImage(50, 50, 'gif');
        $fileUpload = $this->createFileUpload($tmpPath, 'animation.gif', 'image/gif', (int) \filesize($tmpPath));

        $result = $this->apiPostWithFiles('fileReplace', [
            'id' => (string) $file->getId(),
        ], ['file0' => $fileUpload]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('image/gif', $data['mimeType']);
    }

    // =========================================================================
    // Storage info after uploads
    // =========================================================================

    #[Test]
    public function storageInfoReflectsUploadedFileSize(): void
    {
        // Check initial storage
        $result1 = $this->apiGet('storageInfo');
        $this->assertApiSuccess($result1);
        $data1 = $result1->getJsonData();
        self::assertIsArray($data1);
        self::assertArrayHasKey('totalSize', $data1);
        $initialSize = (int) $data1['totalSize'];

        // Upload a file
        $content = \str_repeat('X', 2048); // 2KB
        $tmpPath = $this->createTrackedTempFile($content, 'txt');
        $fileSize = (int) \filesize($tmpPath);
        $fileUpload = $this->createFileUpload($tmpPath, 'data.txt', 'text/plain', $fileSize);

        $uploadResult = $this->apiPostWithFiles('fileUpload', [], ['file0' => $fileUpload]);
        $this->assertApiStatusCode(201, $uploadResult);

        // Check storage after upload
        $result2 = $this->apiGet('storageInfo');
        $this->assertApiSuccess($result2);
        $data2 = $result2->getJsonData();
        self::assertIsArray($data2);
        self::assertArrayHasKey('totalSize', $data2);
        $newSize = (int) $data2['totalSize'];

        self::assertEquals($initialSize + $fileSize, $newSize);
    }
}
