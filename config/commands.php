<?php

/*
| Dev/test commands that ship in the binary but are hidden from the command
| list in the production distribution. "Production" = the built phar users
| download (Phar::running() is only truthy inside a packaged phar); running
| `php spotify ...` from source always shows everything. Set APP_ENV=production
| to force the production surface while testing locally.
*/
$inDistribution = \Phar::running(false) !== '' || env('APP_ENV') === 'production';

$devHidden = $inDistribution ? [
    App\Commands\WebhookTestCommand::class,
    App\Commands\ServeCommand::class,
    App\Commands\EventEmitCommand::class,
] : [];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Command
    |--------------------------------------------------------------------------
    |
    | Laravel Zero will always run the command specified below when no command name is
    | provided. Consider update the default command for single command applications.
    | You cannot pass arguments to the default command because they are ignored.
    |
    */

    'default' => NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,

    /*
    |--------------------------------------------------------------------------
    | Commands Paths
    |--------------------------------------------------------------------------
    |
    | This value determines the "paths" that should be loaded by the console's
    | kernel. Foreach "path" present on the array provided below the kernel
    | will extract all "Illuminate\Console\Command" based class commands.
    |
    */

    'paths' => [app_path('Commands')],

    /*
    |--------------------------------------------------------------------------
    | Added Commands
    |--------------------------------------------------------------------------
    |
    | You may want to include a single command class without having to load an
    | entire folder. Here you can specify which commands should be added to
    | your list of commands. The console's kernel will try to load them.
    |
    */

    'add' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Hidden Commands
    |--------------------------------------------------------------------------
    |
    | Your application commands will always be visible on the application list
    | of commands. But you can still make them "hidden" specifying an array
    | of commands below. All "hidden" commands can still be run/executed.
    |
    */

    'hidden' => [
        NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,
        Symfony\Component\Console\Command\DumpCompletionCommand::class,
        Symfony\Component\Console\Command\HelpCommand::class,
        Illuminate\Console\Scheduling\ScheduleRunCommand::class,
        Illuminate\Console\Scheduling\ScheduleListCommand::class,
        Illuminate\Console\Scheduling\ScheduleFinishCommand::class,
        Illuminate\Foundation\Console\VendorPublishCommand::class,
        LaravelZero\Framework\Commands\StubPublishCommand::class,
        Laravel\Mcp\Console\Commands\MakeServerCommand::class,
        Laravel\Mcp\Console\Commands\MakeToolCommand::class,
        Laravel\Mcp\Console\Commands\MakePromptCommand::class,
        Laravel\Mcp\Console\Commands\MakeResourceCommand::class,
        Laravel\Mcp\Console\Commands\InspectorCommand::class,
        Laravel\Ai\Console\Commands\MakeAgentCommand::class,
        Laravel\Ai\Console\Commands\MakeToolCommand::class,
        ...$devHidden,
    ],

    /*
    |--------------------------------------------------------------------------
    | Removed Commands
    |--------------------------------------------------------------------------
    |
    | Do you have a service provider that loads a list of commands that
    | you don't need? No problem. Laravel Zero allows you to specify
    | below a list of commands that you don't to see in your app.
    |
    */

    'remove' => [
        //
    ],

];
