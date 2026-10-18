<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LiveKeysCommand extends Command
{
    protected $signature = 'classroom:live-keys {--env-file= : The .env file to update (defaults to the project .env)}';

    protected $description = 'Fill in the live updates (Reverb) key and secret in .env when they are empty';

    public function handle(): int
    {
        $path = $this->option('env-file') ?: base_path('.env');
        if (! is_file($path)) {
            $this->error('No .env file found.');

            return self::FAILURE;
        }
        $contents = (string) file_get_contents($path);
        $changed = false;
        foreach (['REVERB_APP_ID' => 'ccis-classroom', 'REVERB_APP_KEY' => Str::lower(Str::random(20)), 'REVERB_APP_SECRET' => Str::lower(Str::random(32))] as $key => $value) {
            if (preg_match('/^'.$key.'=(.*)$/m', $contents, $match)) {
                if (trim($match[1], " \t\r\"'") !== '') {
                    continue;
                }
                $contents = preg_replace('/^'.$key.'=.*$/m', $key.'='.$value, $contents, 1);
            } else {
                $contents = rtrim($contents)."\n{$key}={$value}\n";
            }
            $changed = true;
        }
        if ($changed) {
            file_put_contents($path, $contents);
            $this->info('Live updates keys added to .env.');
        } else {
            $this->line('Live updates keys are already set.');
        }

        return self::SUCCESS;
    }
}
