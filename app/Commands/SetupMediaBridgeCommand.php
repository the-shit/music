
<?php

namespace App\Commands;

use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class SetupMediaBridgeCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'setup:media-bridge';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Sets up the Swift media bridge for macOS Control Center and media keys';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Setting up Swift media bridge...');

        // Step 1: Compile the Swift wrapper
        $swiftFile = base_path('helpers/media-bridge.swift');
        $outputBin = base_path('bin/media-bridge');

        if (! File::exists($swiftFile)) {
            $this->error('Swift file not found at '.$swiftFile);

            return 1;
        }

        $compileProcess = new Process(['swiftc', $swiftFile, '-o', $outputBin]);
        $compileProcess->run();

        if (! $compileProcess->isSuccessful()) {
            $this->error('Compilation failed: '.$compileProcess->getErrorOutput());

            return 1;
        }

        $this->info('Compiled Swift bridge to '.$outputBin);

        // Step 2: Create launchd plist
        $plistPath = getenv('HOME').'/Library/LaunchAgents/com.spotify.media-bridge.plist';
        $projectDir = base_path();
        $binPath = $outputBin;

        $plistContent = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.spotify.media-bridge</string>
    <key>ProgramArguments</key>
    <array>
        <string>$binPath</string>
    </array>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PROJECT_DIR</key>
        <string>$projectDir</string>
    </dict>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/tmp/media-bridge.stdout</string>
    <key>StandardErrorPath</key>
    <string>/tmp/media-bridge.stderr</string>
</dict>
</plist>
EOT;

        File::put($plistPath, $plistContent);
        $this->info('Created launchd plist at '.$plistPath);

        // Step 3: Load the daemon
        $loadProcess = new Process(['launchctl', 'load', $plistPath]);
        $loadProcess->run();

        if (! $loadProcess->isSuccessful()) {
            $this->error('Failed to load daemon: '.$loadProcess->getErrorOutput());

            return 1;
        }

        $this->info('Media bridge daemon loaded successfully!');
        $this->info('To unload/reload: launchctl unload '.$plistPath.' && launchctl load '.$plistPath);

        return 0;
    }
}
