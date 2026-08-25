<?php

declare(strict_types=1);

namespace App\Services\Daemon;

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;

abstract class Supervisor
{
    protected SpotifyAuthManager $auth;

    protected SpotifyPlayerService $player;

    public function __construct(SpotifyAuthManager $auth, SpotifyPlayerService $player)
    {
        $this->auth = $auth;
        $this->player = $player;
    }

    abstract public function start(): int;

    abstract public function stop(): int;

    abstract public function status(): int;

    abstract public function install(): int;

    abstract public function uninstall(): int;

    abstract public function health(bool $heal = false): int;
}
