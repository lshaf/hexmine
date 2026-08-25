<?php

declare(strict_types=1);

namespace App\Wax;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The chain, read-only.
 *
 * One question is asked of it -- "what is in transaction X" -- and the answer
 * comes back in one shape whichever kind of node answered. Hyperion (/v2) and
 * the classic history plugin (/v1) both know the transaction; they disagree
 * about where in the JSON it lives and what the fields are called, and that
 * disagreement stops here rather than travelling into the verifier.
 *
 * Nothing is written and nothing is signed. The server never holds a key: the
 * only thing it does with the chain is look, which is what makes a login proof
 * safe to build out of public data.
 */
class Chain
{
    /**
     * @return array{id:string,executed:bool,irreversible:bool,block_time:?int,actions:list<array{account:string,name:string,data:array<string,mixed>}>}|null
     *                                                                                                                                                        null when no listed node could answer -- which is "we do not
     *                                                                                                                                                        know", and is deliberately different from "no such transaction".
     */
    public function transaction(string $id): ?array
    {
        foreach (config('wax.endpoints') as $endpoint) {
            [$url, $shape] = array_pad(explode('|', $endpoint, 2), 2, 'v2');

            try {
                $found = $shape === 'v1'
                    ? $this->viaHistoryPlugin(rtrim($url, '/'), $id)
                    : $this->viaHyperion(rtrim($url, '/'), $id);
            } catch (Throwable) {
                // A node that is down, slow or lying is not an answer about the
                // transaction. Try the next one rather than telling the player
                // their payment does not exist.
                continue;
            }

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function viaHyperion(string $url, string $id): ?array
    {
        $response = Http::timeout(config('wax.timeout'))
            ->acceptJson()
            ->get($url.'/v2/history/get_transaction', ['id' => $id]);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['actions'] ?? null) || $body['actions'] === []) {
            return null;
        }

        $first = $body['actions'][0];

        // Hyperion reports irreversibility as a number to compare, not a flag:
        // `lib` is the last irreversible block and the action carries its own.
        $lib = (int) ($body['lib'] ?? 0);
        $blockNum = (int) ($first['block_num'] ?? 0);

        return [
            'id' => (string) ($body['trx_id'] ?? $id),
            // Hyperion only indexes what executed, so a transaction it returns
            // at all executed. The flag is still read where it is given.
            'executed' => (bool) ($body['executed'] ?? true),
            'irreversible' => $lib > 0 && $blockNum > 0 && $blockNum <= $lib,
            'block_time' => $this->timestamp($first['@timestamp'] ?? $first['timestamp'] ?? null),
            'actions' => $this->actions($body['actions'], 'act'),
        ];
    }

    private function viaHistoryPlugin(string $url, string $id): ?array
    {
        $response = Http::timeout(config('wax.timeout'))
            ->acceptJson()
            ->post($url.'/v1/history/get_transaction', ['id' => $id]);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['traces'] ?? null)) {
            return null;
        }

        return [
            'id' => (string) ($body['id'] ?? $id),
            'executed' => ($body['trx']['receipt']['status'] ?? 'executed') === 'executed',
            'irreversible' => (bool) ($body['irreversible'] ?? false),
            'block_time' => $this->timestamp($body['block_time'] ?? null),
            'actions' => $this->actions($body['traces'], 'act'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $raw
     * @return list<array{account:string,name:string,data:array<string,mixed>}>
     */
    private function actions(array $raw, string $key): array
    {
        $actions = [];

        foreach ($raw as $entry) {
            $act = $entry[$key] ?? null;

            if (! is_array($act)) {
                continue;
            }

            // An action whose ABI the node could not resolve arrives as a hex
            // string instead of a map. It is not a transfer anybody can read,
            // so it is not a transfer this can accept.
            $data = $act['data'] ?? null;

            $actions[] = [
                'account' => (string) ($act['account'] ?? ''),
                'name' => (string) ($act['name'] ?? ''),
                'data' => is_array($data) ? $data : [],
            ];
        }

        return $actions;
    }

    /** Chain time is UTC and says so in no other way, so it is read as UTC. */
    private function timestamp(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = strtotime(str_ends_with($value, 'Z') ? $value : $value.'Z');

        return $parsed === false ? null : $parsed;
    }
}
