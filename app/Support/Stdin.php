<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Symfony leaves Input::isInteractive() true when stdin is a pipe.
 * Laravel Prompts then enters select() and spins on EOF. A prompt is
 * allowed only for a real TTY, and never under --json or a named target.
 */
class Stdin
{
    public static function allowsPrompt(bool $json, bool $inputInteractive, bool $stdinIsTty, bool $namedTarget): bool
    {
        if ($json || $namedTarget || ! $inputInteractive) {
            return false;
        }

        return $stdinIsTty;
    }

    public function isTty(): bool
    {
        if (! defined('STDIN') || ! is_resource(\STDIN)) {
            return false;
        }

        return stream_isatty(\STDIN);
    }
}
