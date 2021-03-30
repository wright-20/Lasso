<?php

namespace Sammyjo20\Lasso;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Sammyjo20\Lasso\Commands\PublishCommand;
use Sammyjo20\Lasso\Commands\PullCommand;
use Sammyjo20\Lasso\Commands\PushCommand;
use Sammyjo20\Lasso\Container\Artisan;
use Sammyjo20\Lasso\Helpers\Filesystem;

class LassoServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/config.php',
            'lasso'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerCommands()
                ->offerPublishing()
                ->bindsServices();
        }
    }

    /**
     * @return $this
     */
    protected function registerCommands(): self
    {
        $this->commands([
            PushCommand::class,
            PublishCommand::class,
            PullCommand::class,
        ]);

        return $this;
    }

    /**
     * @return $this
     */
    protected function offerPublishing(): self
    {
        $this->publishes([
            __DIR__.'/../config/config.php' => config_path('lasso.php'),
        ], 'lasso-config');

        return $this;
    }

    /**
     * @return $this
     */
    protected function bindsServices(): self
    {
        $this->app->instance(Artisan::class, new Artisan);
        $this->app->instance(Filesystem::class, new Filesystem);

        return $this;
    }
}
