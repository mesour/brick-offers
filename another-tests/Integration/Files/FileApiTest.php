<?php declare(strict_types = 1);

namespace Tests\Integration\Files;

use App\Files\Database\File;
use App\Files\Database\FileFolder;
use App\Files\Database\FileFolderRepository;
use App\Files\Database\FileRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for File and Folder API endpoints.
 *
 * @group integration
 * @group database
 */
final class FileApiTest extends ApiIntegrationTestCase
{
    private FileRepository $fileRepository;
    private FileFolderRepository $folderRepository;

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

    // =========================================================================
    // GET /api/files
    // =========================================================================

    #[Test]
    public function getFilesReturnsEmptyWhenNoFiles(): void
    {
        $result = $this->apiGet('files');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertIsArray($data['folders']);
        self::assertEmpty($data['files']);
        self::assertEmpty($data['folders']);
    }

    #[Test]
    public function getFilesReturnsFilesAndFolders(): void
    {
        // Create folder
        $folder = new FileFolder('Test Folder', null);
        $this->folderRepository->save($folder);

        // Create file
        $file = new File(
            'test_file.jpg',
            'original.jpg',
            '/uploads/test_file.jpg',
            'image/jpeg',
            1024,
        );
        $this->fileRepository->save($file);

        $result = $this->apiGet('files');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertIsArray($data['folders']);
        self::assertCount(1, $data['files']);
        self::assertCount(1, $data['folders']);
    }

    // =========================================================================
    // GET /api/storage-info
    // =========================================================================

    #[Test]
    public function getStorageInfoReturnsZeroWhenNoFiles(): void
    {
        $result = $this->apiGet('storageInfo');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals(0, $data['totalSize']);
    }

    #[Test]
    public function getStorageInfoReturnsTotalSize(): void
    {
        // Create files with known sizes
        $file1 = new File('file1.jpg', 'file1.jpg', '/uploads/file1.jpg', 'image/jpeg', 1000);
        $file2 = new File('file2.jpg', 'file2.jpg', '/uploads/file2.jpg', 'image/jpeg', 2000);
        $this->fileRepository->save($file1);
        $this->fileRepository->save($file2);

        $result = $this->apiGet('storageInfo');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals(3000, $data['totalSize']);
    }

    // =========================================================================
    // POST /api/file-rename
    // =========================================================================

    #[Test]
    public function renameFile(): void
    {
        $file = new File('stored.jpg', 'original.jpg', '/uploads/stored.jpg', 'image/jpeg', 1024);
        $this->fileRepository->save($file);

        $result = $this->apiPost('fileRename', [
            'id' => (string) $file->getId(),
            'name' => 'new-name.jpg',
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('new-name.jpg', $data['originalFilename']);
    }

    #[Test]
    public function renameFileReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('fileRename', [
            'id' => '999999',
            'name' => 'new-name.jpg',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/file-move
    // =========================================================================

    #[Test]
    public function moveFileToFolder(): void
    {
        $folder = new FileFolder('Target Folder', null);
        $this->folderRepository->save($folder);

        $file = new File('file.jpg', 'file.jpg', '/uploads/file.jpg', 'image/jpeg', 1024);
        $this->fileRepository->save($file);

        $result = $this->apiPost('fileMove', [
            'id' => (string) $file->getId(),
            'folderId' => (string) $folder->getId(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals($folder->getId(), $data['folderId']);
    }

    #[Test]
    public function moveFileToRootFolder(): void
    {
        $folder = new FileFolder('Folder', null);
        $this->folderRepository->save($folder);

        $file = new File('file.jpg', 'file.jpg', '/uploads/file.jpg', 'image/jpeg', 1024);
        $file->setFolderId($folder->getId());
        $this->fileRepository->save($file);

        // Move to root (null folder)
        $result = $this->apiPost('fileMove', [
            'id' => (string) $file->getId(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertNull($data['folderId']);
    }

    #[Test]
    public function moveFileReturns404ForNonExistentFile(): void
    {
        $folder = new FileFolder('Folder', null);
        $this->folderRepository->save($folder);

        $result = $this->apiPost('fileMove', [
            'id' => '999999',
            'folderId' => (string) $folder->getId(),
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function moveFileReturns404ForNonExistentFolder(): void
    {
        $file = new File('file.jpg', 'file.jpg', '/uploads/file.jpg', 'image/jpeg', 1024);
        $this->fileRepository->save($file);

        $result = $this->apiPost('fileMove', [
            'id' => (string) $file->getId(),
            'folderId' => '999999',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/file-delete
    // =========================================================================

    #[Test]
    public function deleteFile(): void
    {
        $file = new File('file.jpg', 'file.jpg', '/uploads/file.jpg', 'image/jpeg', 1024);
        $this->fileRepository->save($file);

        $result = $this->apiPost('fileDelete', [
            'id' => (string) $file->getId(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function deleteFileReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('fileDelete', ['id' => '999999']);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/folder-create
    // =========================================================================

    #[Test]
    public function createFolder(): void
    {
        $result = $this->apiPost('folderCreate', ['name' => 'New Folder']);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertEquals('New Folder', $data['name']);
        self::assertNull($data['parentId']);
    }

    #[Test]
    public function createNestedFolder(): void
    {
        $parentFolder = new FileFolder('Parent', null);
        $this->folderRepository->save($parentFolder);

        $result = $this->apiPost('folderCreate', [
            'name' => 'Child Folder',
            'parentId' => (string) $parentFolder->getId(),
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals($parentFolder->getId(), $data['parentId']);
    }

    #[Test]
    public function createFolderReturns404ForNonExistentParent(): void
    {
        $result = $this->apiPost('folderCreate', [
            'name' => 'Folder',
            'parentId' => '999999',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/folder-rename
    // =========================================================================

    #[Test]
    public function renameFolder(): void
    {
        $folder = new FileFolder('Old Name', null);
        $this->folderRepository->save($folder);

        $result = $this->apiPost('folderRename', [
            'id' => (string) $folder->getId(),
            'name' => 'New Name',
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('New Name', $data['name']);
    }

    #[Test]
    public function renameFolderReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('folderRename', [
            'id' => '999999',
            'name' => 'New Name',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/folder-delete
    // =========================================================================

    #[Test]
    public function deleteEmptyFolder(): void
    {
        $folder = new FileFolder('Empty Folder', null);
        $this->folderRepository->save($folder);

        $result = $this->apiPost('folderDelete', ['id' => (string) $folder->getId()]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function deleteFolderReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('folderDelete', ['id' => '999999']);

        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function cannotDeleteFolderWithFiles(): void
    {
        $folder = new FileFolder('Folder With Files', null);
        $this->folderRepository->save($folder);

        $file = new File('file.jpg', 'file.jpg', '/uploads/file.jpg', 'image/jpeg', 1024);
        $file->setFolderId($folder->getId());
        $this->fileRepository->save($file);

        $result = $this->apiPost('folderDelete', ['id' => (string) $folder->getId()]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('FOLDER_NOT_EMPTY', $result);
    }

    #[Test]
    public function cannotDeleteFolderWithSubfolders(): void
    {
        $parentFolder = new FileFolder('Parent', null);
        $this->folderRepository->save($parentFolder);

        $childFolder = new FileFolder('Child', $parentFolder->getId());
        $this->folderRepository->save($childFolder);

        $result = $this->apiPost('folderDelete', ['id' => (string) $parentFolder->getId()]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('FOLDER_NOT_EMPTY', $result);
    }
}
