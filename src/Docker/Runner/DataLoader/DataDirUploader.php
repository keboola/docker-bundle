<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Options\FileUploadOptions;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use ZipArchive;

class DataDirUploader
{
    private const TAG_DEBUG = 'debug';
    private const TAG_PREFIX_ROW_ID = 'rowId';
    private const TAG_PREFIX_JOB_ID = 'jobId';

    private const FILE_PATHS_TO_MASK = [
        'config.json',
        'in/state.json',
        'out/state.json',
    ];

    public function __construct(
        private readonly BranchAwareClient $storageApiClient,
        private readonly SecretsRedactorInterface $secretRedactor,
    ) {
    }

    public function uploadDataDir(
        string $jobId,
        string $componentId,
        ?string $configRowId,
        string $dataDirPath,
        string $archiveFileBaseName,
    ): void {
        $zipFilePath = sprintf('%s/%s.zip', $dataDirPath, $archiveFileBaseName);

        try {
            $this->prepareZipFile($dataDirPath, $zipFilePath);
            $this->uploadZipFile(
                $zipFilePath,
                [
                    self::TAG_DEBUG,
                    $componentId,
                    sprintf('%s:%s', self::TAG_PREFIX_JOB_ID, $jobId),
                    sprintf('%s:%s', self::TAG_PREFIX_ROW_ID, $configRowId ?? ''),
                ],
            );
        } finally {
            (new Filesystem())->remove($zipFilePath);
        }
    }

    private function prepareZipFile(string $dataDirPath, string $zipFilePath): void
    {
        $zip = new ZipArchive();
        $zip->open($zipFilePath, ZipArchive::CREATE);

        $finder = new Finder();
        foreach ($finder->in($dataDirPath) as $item) {
            $filepath = $item->getRelativePathname();
            $filename = $item->getFilename();

            if ($item->isDir()) {
                self::failOnFalse(
                    $zip->addEmptyDir($filepath),
                    'Failed to add directory: ' . $filename,
                );
                continue;
            }

            if (in_array($filepath, self::FILE_PATHS_TO_MASK, true)) {
                $configData = self::failOnFalse(
                    file_get_contents($item->getPathname()),
                    'Failed to read file: ' . $filename,
                );
                $configData = $this->secretRedactor->redactSecrets($configData);

                self::failOnFalse(
                    $zip->addFromString($filepath, $configData),
                    'Failed to add file: ' . $filename,
                );
                continue;
            }

            self::failOnFalse(
                $zip->addFile($item->getPathname(), $filepath),
                'Failed to add file: ' . $filename,
            );
        }
        $zip->close();
    }

    private function uploadZipFile(
        string $zipPathname,
        array $tags,
    ): void {
        $uploadOptions = new FileUploadOptions();
        $uploadOptions->setTags($tags);
        $uploadOptions->setIsPermanent(false);
        $uploadOptions->setIsPublic(false);
        $uploadOptions->setNotify(false);

        $this->storageApiClient->uploadFile($zipPathname, $uploadOptions);
    }

    /**
     * @template T
     * @param T|false $result
     * @return T
     */
    private static function failOnFalse(mixed $result, string $errorMessage): mixed
    {
        if ($result === false) {
            throw new RuntimeException($errorMessage);
        }

        return $result;
    }
}
