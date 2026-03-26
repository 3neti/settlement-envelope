<?php

namespace LBHurtado\SettlementEnvelope\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class InstallDriversCommand extends Command
{
    protected $signature = 'envelope:install-drivers
                            {--force : Overwrite existing drivers}';

    protected $description = 'Install default envelope drivers from package stubs to storage';

    public function handle(): int
    {
        $disk = config('settlement-envelope.driver_disk', 'envelope-drivers');
        $stubsPath = $this->getStubsPath();

        if (! File::isDirectory($stubsPath)) {
            $this->error("Stubs directory not found: {$stubsPath}");

            return self::FAILURE;
        }

        $force = $this->option('force');
        $installed = 0;
        $skipped = 0;

        // Iterate through stub driver directories
        $directories = File::directories($stubsPath);
        foreach ($directories as $dir) {
            $driverId = basename($dir);
            $files = File::files($dir);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $targetPath = "{$driverId}/{$filename}";

                if (Storage::disk($disk)->exists($targetPath) && ! $force) {
                    $this->line("<comment>Skipped:</comment> {$targetPath} (exists)");
                    $skipped++;

                    continue;
                }

                $content = File::get($file->getPathname());
                Storage::disk($disk)->put($targetPath, $content);
                $this->info("<info>Installed:</info> {$targetPath}");
                $installed++;
            }
        }

        $this->newLine();
        $this->info("Done! Installed: {$installed}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    protected function getStubsPath(): string
    {
        return dirname(__DIR__, 2).'/resources/stubs/drivers';
    }
}
