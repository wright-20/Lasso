<?php

namespace Sammyjo20\Lasso\Tasks\Pull;

use Sammyjo20\Lasso\Exceptions\FetchCommandFailed;
use Sammyjo20\Lasso\Helpers\BundleIntegrityHelper;
use Sammyjo20\Lasso\Helpers\FileLister;
use Sammyjo20\Lasso\Services\ArchiveService;
use Sammyjo20\Lasso\Services\BackupService;
use Sammyjo20\Lasso\Services\VersioningService;
use Sammyjo20\Lasso\Tasks\BaseJob;
use Sammyjo20\Lasso\Tasks\Webhook;

class PullJob extends BaseJob
{
    /**
     * @var bool
     */
    protected $useGit = true;

    /**
     * @var BackupService
     */
    protected $backup;

    /**
     * PullJob constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->setBackup();
    }

    /**
     * @throws FetchCommandFailed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \Sammyjo20\Lasso\Exceptions\BaseException
     * @return void
     */
    public function run(): void
    {
        $this->cleanLassoDirectory();

        $this->artisan->note('⏳ Reading Bundle meta file...');

        $bundleInfo = $this->getLatestBundleInfo();

        $this->artisan->note('⏳ Downloading bundle...');

        $bundleZipPath = $this->downloadBundleZip($bundleInfo['file'], $bundleInfo['checksum']);

        $this->artisan->note('✅ Successfully downloaded bundle.')
            ->note('⏳ Creating backup...');

        try {
            $this->runBackup();

            $this->artisan->note('✅ Backed up.')
                ->note('⏳ Updating assets...');

            $publicPath = $this->filesystem->getPublicPath();

            ArchiveService::extract($bundleZipPath, base_path('.lasso/bundle'));

            $files = (new FileLister(base_path('.lasso/bundle')))
                ->getFinder();

            foreach ($files as $file) {
                $source = $file->getRealPath();

                $destination = sprintf('%s/%s', $publicPath, $file->getRelativePathName());
                $directory = sprintf('%s/%s', $publicPath, $file->getRelativePath());

                $this->filesystem
                    ->ensureDirectoryExists($directory);

                $this->filesystem
                    ->copy($source, $destination);
            }
        } catch (\Exception $ex) {
            // If anything goes wrong inside this try block,
            // we will "roll back" which means we will restore
            // our backup.

            $this->rollBack($ex);
        }

        if ($this->useGit === true) {
            VersioningService::appendNewVersion(
                $this->cloud->getUploadPath($bundleInfo['file'])
            );
        }

        $this->cleanUp();

        $this->artisan->note('✅ Successfully updated assets.')
            ->note('⏳ Dispatching webhooks...');

        $this->dispatchWebhooks();

        $this->artisan->note('✅ Webhooks dispatched.');
    }

    /**
     * @return void
     */
    public function cleanUp(): void
    {
        $this->filesystem->deleteBaseLassoDirectory();
    }

    /**
     * @return void
     */
    public function dispatchWebhooks(): void
    {
        $webhooks = config('lasso.webhooks.pull', []);

        foreach ($webhooks as $webhook) {
            Webhook::send($webhook, 'pull');
        }
    }

    /**
     * @return array
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function getLatestBundleInfo(): array
    {
        $localPath = base_path('lasso-bundle.json');
        $cloudPath = $this->cloud->getUploadPath('lasso-bundle.json');

        // Firstly, let's check if the local filesystem has a "lasso-bundle.json"
        // file in it's root directory.

        if ($this->filesystem->exists($localPath)) {
            $file = $this->filesystem->get($localPath);
            $bundle = json_decode($file, true);

            $this->validateBundle($bundle);

            return $bundle;
        }

        $this->doesntUseGit();

        // If there isn't a "lasso-bundle.json" file in the root directory,
        // that's okay - this means that the commit is in "non-git" mode. So
        // let's just grab that file.If we don't have a file on the server
        // however; we need to throw an exception.

        if (!$this->cloud->has($cloudPath)) {
            $this->rollBack(
                FetchCommandFailed::because('A valid "lasso-bundle.json" file could not be found in the Filesystem for the current environment.')
            );
        }

        $file = $this->cloud->get($cloudPath);
        $bundle = json_decode($file, true);

        $this->validateBundle($bundle);

        return $bundle;
    }

    /**
     * @param array $bundle
     * @return bool
     */
    private function validateBundle(array $bundle): bool
    {
        if (!isset($bundle['file']) || !isset($bundle['checksum'])) {
            $this->rollBack(
                FetchCommandFailed::because('The bundle info was missing the required data.')
            );
        }

        return true;
    }

    /**
     * @param string $file
     * @param string $checksum
     * @return string
     * @throws FetchCommandFailed
     */
    private function downloadBundleZip(string $file, string $checksum): string
    {
        $bundlePath = $this->cloud->getUploadPath($file);
        $localBundlePath = base_path('.lasso/bundle.zip');

        if (!$this->cloud->exists($bundlePath)) {
            $this->rollBack(
                FetchCommandFailed::because('The bundle zip does not exist. If you are using a specific environment, please make sure the LASSO_ENV is the same in your .env file.')
            );
        }

        try {
            $bundleZip = $this->cloud
                ->readStream($bundlePath);

            $this->filesystem
                ->putStream($bundleZip, $localBundlePath);

            if (is_resource($bundleZip)) {
                fclose($bundleZip);
            }
        } catch (\Exception $ex) {
            $this->rollBack(
                FetchCommandFailed::because('An error occurred while writing to the local path.')
            );
        }

        // Now we want to check if the integrity of the bundle is okay.
        // If the integrity is incorrect, it could have been downloaded
        // incorrectly or tampered with!

        if (!BundleIntegrityHelper::verifyChecksum($localBundlePath, $checksum)) {
            $this->rollBack(
                FetchCommandFailed::because('The bundle Zip\'s checksum is incorrect.')
            );
        }

        return $localBundlePath;
    }

    /**
     * @param \Exception $exception
     * @throws \Exception
     */
    private function rollBack(\Exception $exception)
    {
        if ($this->backup->hasBackup()) {
            $this->artisan->note('⏳ Restoring backup...');

            $this->backup->restoreBackup($this->filesystem->getPublicPath());

            $this->artisan->note('✅ Successfully restored backup.');
        }

        $this->filesystem->deleteBaseLassoDirectory();

        throw $exception;
    }

    /**
     * @return $this
     */
    private function runBackup(): self
    {
        $this->backup->createBackup(
            $this->filesystem->getPublicPath(),
            base_path('.lasso/backup')
        );

        return $this;
    }

    /**
     * @return $this
     */
    private function setBackup(): self
    {
        $this->backup = new BackupService($this->filesystem);

        return $this;
    }

    /**
     * @return void
     */
    private function cleanLassoDirectory(): void
    {
        $this->filesystem->deleteBaseLassoDirectory();

        $this->filesystem->ensureDirectoryExists(base_path('.lasso'));
    }

    /**
     * @return $this
     */
    public function doesntUseGit(): self
    {
        $this->useGit = false;

        return $this;
    }
}
