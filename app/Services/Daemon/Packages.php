<?php

declare(strict_types=1);

namespace App\Services\Daemon;

class Packages
{
    /**
     * Linux is Arch (pacman, or Omarchy's wrapper). apt is never the path.
     *
     * @param  list<string>  $packages
     */
    public static function installCommand(string $osFamily, array $packages, bool $omarchy): ?string
    {
        $list = implode(' ', $packages);

        if ($osFamily === 'Darwin') {
            return 'brew install '.$list;
        }

        if ($osFamily === 'Linux') {
            return $omarchy
                ? 'omarchy pkg add '.$list
                : 'sudo pacman -S --needed --noconfirm '.$list;
        }

        return null;
    }
}
