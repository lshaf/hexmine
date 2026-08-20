<?php

declare(strict_types=1);

namespace App\Game;

use RuntimeException;

/**
 * A rule said no.
 *
 * These are expected outcomes, not faults: "both slots are taken", "not enough
 * gold", "you have to be at the settlement". They render as 422 with a message
 * the player is meant to read, so the copy here is player-facing.
 */
class GameException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'blocked')
    {
        parent::__construct($message);
    }
}
