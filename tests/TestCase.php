<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests;

use BJTheCod3r\Docket\DocketServiceProvider;
use BJTheCod3r\Docket\Tests\Fixtures\Artist;
use BJTheCod3r\Docket\Tests\Fixtures\Product;
use BJTheCod3r\Docket\Tests\Fixtures\Team;
use BJTheCod3r\Docket\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            DocketServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // CI sets DB_CONNECTION=mysql|pgsql (with DB_HOST, DB_PORT, …) to run the suite against a real server.
        $driver = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $driver === 'sqlite' ? [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ] : array_merge($app['config']->get("database.connections.{$driver}"), [
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT'),
            'database' => env('DB_DATABASE', 'docket'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ]));
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('docket.attachments.queue_variants', false);
        $app['config']->set('docket.notifications.cooldown_seconds', 0);
    }

    protected function setUpDatabase(): void
    {
        Schema::dropAllTables();

        $migration = require __DIR__.'/../database/migrations/create_docket_tables.php.stub';
        $migration->up();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('bio')->nullable();
            $table->foreignId('team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('price')->default(0);
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });
    }

    protected function user(string $name = 'Ada'): User
    {
        return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
    }

    protected function team(string $name = 'Payments'): Team
    {
        return Team::create(['name' => $name]);
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create(array_merge(['name' => 'Widget', 'price' => 3999, 'description' => 'A widget'], $attributes));
    }

    protected function artist(string $name = 'Burna'): Artist
    {
        return Artist::create(['name' => $name]);
    }
}
