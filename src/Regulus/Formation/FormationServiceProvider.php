<?php namespace Regulus\Formation;

use Illuminate\Support\ServiceProvider;

class FormationServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		;
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$configPath = __DIR__ . '/../../config/config.php';
		$this->mergeConfigFrom($configPath, 'formation');
		$this->publishes([$configPath => config_path('formation.php')]);

		$this->app['formation'] = $this->app->share(function($app) {
			return new Formation($app['html'], $app['url'], $app['session.store']->getToken());
		});
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array();
	}

}
