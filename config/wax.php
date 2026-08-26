<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The account the login fee is paid to
    |--------------------------------------------------------------------------
    |
    | §2 -- a wallet must prove it is controlled, not merely named. A signed
    | transfer is that proof: naming an account costs nothing, and moving funds
    | out of it costs a key nobody but the owner has.
    |
    */
    'account' => env('WAX_LOGIN_ACCOUNT', 'shaf.wiz'),

    /*
    |--------------------------------------------------------------------------
    | What a login costs
    |--------------------------------------------------------------------------
    |
    | ONE UNIT of WAX -- 8 decimal places, so this is the smallest quantity the
    | chain can express. The fee was never the point: what proves the wallet is
    | the SIGNATURE, and a signature costs the same whether it moves a fortune
    | or a dust mote. Charging anything real would have been charging for the
    | privilege of proving who you are.
    |
    | The amount is matched EXACTLY rather than as a floor: a floor would let
    | one large transfer stand in for many, and the point is that each proof is
    | its own signed act.
    |
    | Written as the chain writes it -- 8 decimal places, symbol included -- so
    | there is one spelling of the amount and no conversion between the string
    | the wallet signs and the string this compares.
    |
    */
    'fee' => env('WAX_LOGIN_FEE', '0.00000001 WAX'),
    'token_contract' => env('WAX_TOKEN_CONTRACT', 'eosio.token'),

    /*
    |--------------------------------------------------------------------------
    | The window -- and it is ONE number on purpose
    |--------------------------------------------------------------------------
    |
    | Ten minutes, and three separate rules read it:
    |
    |   1. a challenge is redeemable for this long after it is issued
    |   2. a transfer is accepted only if it is younger than this
    |   3. a spent transaction id is locked against reuse for this long, plus
    |      the skew below
    |
    | The three MUST be the same number. If the lock were shorter than the
    | acceptance window, a transaction would come back up for reuse while still
    | young enough to be accepted -- which is the replay this exists to stop.
    | Deriving all three from one value is what keeps them from drifting apart,
    | so this is the only figure to tune.
    |
    | The skew is added to the lock alone. Chain time and server time are two
    | clocks, and the lock is the half that must never expire early.
    |
    */
    'window' => (int) env('WAX_LOGIN_WINDOW', 600),
    'skew' => (int) env('WAX_LOGIN_SKEW', 60),

    /*
    |--------------------------------------------------------------------------
    | Where the proof is kept
    |--------------------------------------------------------------------------
    |
    | Redis. Every key here is worthless ten minutes after it is written, and a
    | store that forgets on its own is the right shape for that -- a database
    | table of spent transaction ids would need sweeping forever to hold facts
    | nobody may ask about again.
    |
    | Tests override this to the array store, which has the same semantics and
    | no server.
    |
    */
    'store' => env('WAX_LOGIN_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Chain endpoints, tried in order
    |--------------------------------------------------------------------------
    |
    | A login is a chain read, so a node being down is a login being down.
    | Several, tried in order until one answers, and the answer is normalized:
    | Hyperion (/v2) and the classic history plugin (/v1) return the same
    | transaction in two different shapes and this treats them as one.
    |
    | An endpoint is listed with the shape it speaks, because guessing costs a
    | round trip against every node that does not speak the other one.
    |
    */
    'endpoints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WAX_ENDPOINTS', 'https://wax.eosusa.io|v2,https://api.waxsweden.org|v2,https://wax.greymass.com|v1'))
    ))),

    'timeout' => (int) env('WAX_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | What the wallet in the browser is told
    |--------------------------------------------------------------------------
    |
    | A signing wallet needs the chain it is signing for and one node to push
    | to, and it needs them before it can ask the server anything -- so these
    | ride GET /api/auth/wax rather than being compiled into the bundle. Same
    | argument as the map handing the client its size at boot: one source of
    | truth, and no frontend edit to change a deployment.
    |
    | The chain id IS the network. Pointed at a testnet id with a mainnet node,
    | or the reverse, every signature is rejected -- so the pair moves together.
    |
    */
    'chain_id' => env('WAX_CHAIN_ID', '1064487b3cd1a897ce03ae5b6a865651747e2e152090f99c1d19d44e01aea5a4'),
    'client_endpoint' => env('WAX_CLIENT_ENDPOINT', 'https://wax.greymass.com'),

    /*
    |--------------------------------------------------------------------------
    | Irreversibility
    |--------------------------------------------------------------------------
    |
    | Off by default, and that is a UX decision with a stated price. WAX puts a
    | block beyond reversal about three minutes after it lands; requiring that
    | would mean staring at a spinner for three minutes to log in.
    |
    | What is required instead is that the transaction executed and is in a
    | block. The exposure is a micro-fork orphaning a 0.0001 WAX transfer inside
    | that window, which buys an attacker one session on a wallet whose keys
    | they would have needed anyway to sign it. Turn it on for a deployment that
    | would rather wait than accept that.
    |
    */
    'require_irreversible' => (bool) env('WAX_REQUIRE_IRREVERSIBLE', false),

];
