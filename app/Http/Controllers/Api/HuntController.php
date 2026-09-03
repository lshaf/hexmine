<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §5.5 -- the hunt.
 *
 * One verb and no preview, which is the difference from a fight. A pack is a
 * decision — whether to close at all, at what cost — so §9.5.5 gives it a
 * promise to read first. An animal is not: it costs nothing but the walk you
 * already made, and the only question it asks is whether you brought a bow,
 * which a player can answer by looking at their own belt.
 */
final class HuntController extends GameController
{
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $result = $this->game->hunt($character);

        $name = $result['animal']['name'];

        return $this->respond(
            $character,
            $result,
            $result['armed']
                ? "Took the {$name}."
                : "Took the {$name} bare-handed — the hide is barely worth carrying.",
        );
    }
}
