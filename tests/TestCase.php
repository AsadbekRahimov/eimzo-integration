<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use AsadbekRahimov\EimzoIntegration\Providers\EimzoServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            EimzoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('eimzo.frontend_url', '/frontend');
        $app['config']->set('eimzo.server_url', 'http://127.0.0.1:8080');
        $app['config']->set('eimzo.auth.user_model', TestUser::class);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app['db']->connection()->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('tin')->nullable()->index();
            $table->string('pinfl')->nullable()->index();
            $table->string('eimzo_serial_number')->nullable();
            $table->string('eimzo_full_name')->nullable();
            $table->timestamp('eimzo_authenticated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
