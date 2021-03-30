<?php

namespace Sammyjo20\Lasso\Commands;

use Sammyjo20\Lasso\Container\Artisan;
use Sammyjo20\Lasso\Helpers\ConfigValidator;
use Sammyjo20\Lasso\Helpers\Filesystem;
use Sammyjo20\Lasso\Tasks\Publish\PushJob;

final class PushCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lasso:push {env} {--no-git} {--silent}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push assets to the specified Lasso Filesystem Disk without compiling.';

    /**
     * Execute the console command.
     *
     * @param Artisan $artisan
     * @param Filesystem $filesystem
     * @throws \Sammyjo20\Lasso\Exceptions\ConfigFailedValidation
     */
    public function handle(Artisan $artisan, Filesystem $filesystem): int
    {
        (new ConfigValidator())->validate();

        $this->configureApplication($artisan, $filesystem, true);

        if ($this->argument('env')) {
            $filesystem->setLassoEnvironment($this->argument('env'));
        }

        $dontUseGit = $this->option('no-git') === true;

        $this->configureApplication($artisan, $filesystem);

        $job = new PushJob;

        if ($dontUseGit) {
            $job->dontUseGit();
        }

        $artisan->note(sprintf(
            '🏁 Preparing to publish assets to "%s" filesystem...',
            $filesystem->getCloudDisk()
        ));

        $job->run();

        $artisan->note(sprintf(
            '✅ Successfully published assets to "%s" filesystem! Yee-haw! 🐎',
            $filesystem->getCloudDisk()
        ));

        return 0;
    }
}
