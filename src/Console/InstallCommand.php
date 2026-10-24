<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;

class InstallCommand extends Command
{
    protected $signature = 'docket:install
        {--migrate : Run the migrations after publishing}
        {--force : Overwrite any existing config file}';

    protected $description = 'Publish the Docket config and migrations, and print the quickstart.';

    public function handle(): int
    {
        $this->components->info('Installing Docket…');

        $this->components->task('Publishing config/docket.php', fn () => $this->callSilently('vendor:publish', [
            '--tag' => 'docket-config',
            '--force' => (bool) $this->option('force'),
        ]) === self::SUCCESS);

        if (glob(database_path('migrations/*_create_docket_tables.php')) ?: []) {
            $this->components->twoColumnDetail('Migrations already published', '<fg=yellow;options=bold>SKIPPED</>');
        } else {
            $this->components->task('Publishing migrations', fn () => $this->callSilently('vendor:publish', ['--tag' => 'docket-migrations']) === self::SUCCESS);
        }

        $interactive = $this->input->isInteractive();

        if ($interactive && ! confirm('Enable change management (proposals)?', default: true)) {
            $this->disableProposals();
        }

        if ($this->option('migrate') || ($interactive && confirm('Run the migrations now?', default: true))) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->info('Docket is installed.');
        $this->line("  Add <comment>ReceivesReports</comment> and <comment>FilesReports</comment> to your notifiable model, usually <comment>User</comment> (it should already be <comment>Notifiable</comment>).");
        $this->line('  Add <comment>IsReportable</comment> to anything reports can be filed against.');
        $this->line('  Add <comment>HasProposals</comment> to models that should accept reviewable changes.');
        $this->newLine();
        $this->line('  <info>Docket::report()->about($order)->from($user)->body(\'Wrong item shipped\')->create();</info>');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function disableProposals(): void
    {
        $path = config_path('docket.php');
        $config = File::get($path);

        $updated = preg_replace("/('proposals' => \[\s*'enabled' => )true/", '$1false', $config, 1);

        if ($updated !== null && $updated !== $config) {
            File::put($path, $updated);
            $this->components->task('Disabling change management in config/docket.php', fn () => true);
        } else {
            $this->components->warn("Set 'proposals.enabled' => false in config/docket.php to disable change management.");
        }
    }
}
