<?php

declare(strict_types=1);

it('installs by publishing config and migrations', function () {
    $this->artisan('docket:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Docket is installed')
        ->assertSuccessful();

    expect(file_exists(config_path('docket.php')))->toBeTrue();

    $this->artisan('docket:install', ['--force' => true])
        ->expectsConfirmation('Enable change management (proposals)?', 'no')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(file_get_contents(config_path('docket.php')))->toMatch("/'proposals' => \[\s*'enabled' => false/")
        ->and(glob(database_path('migrations/*_create_docket_tables.php')))->toHaveCount(1);

    @unlink(config_path('docket.php'));
    foreach (glob(database_path('migrations/*_create_docket_tables.php')) ?: [] as $file) {
        @unlink($file);
    }
});
