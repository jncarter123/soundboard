<?php

namespace App\Console\Concerns;

use Illuminate\Support\Str;

/**
 * Reads a new password for the user commands: asked for without echoing in a
 * terminal, generated when left blank or when there's no terminal to ask in
 * (`docker compose exec -T`, CI, scripts).
 */
trait ReadsNewPassword
{
    /**
     * @return array{0: string, 1: bool}|null The password and whether it was
     *                                        generated, or null if the confirmation didn't match.
     */
    protected function readNewPassword(): ?array
    {
        $password = $this->canPrompt()
            ? $this->secret('Password (leave blank to generate one)')
            : null;

        if (blank($password)) {
            return [Str::password(20), true];
        }

        if ($this->secret('Confirm password') !== $password) {
            $this->components->error('The passwords do not match.');

            return null;
        }

        return [$password, false];
    }

    /**
     * Symfony only treats input as non-interactive when given -n, so without a
     * terminal (`docker compose exec -T`) the prompt would print and read
     * nothing. Same check Laravel's own prompts use; tests script the answers.
     */
    private function canPrompt(): bool
    {
        return $this->input->isInteractive()
            && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
    }
}
