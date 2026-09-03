<script setup lang="ts">
/**
 * The door, §2.
 *
 * A wallet gets in by signing something out of itself: naming an account proves
 * nothing, and moving funds out of one needs a key only its owner holds. The
 * amount is one unit -- the smallest the chain can express -- because the fee
 * was never the point and charging for the privilege of proving who you are
 * would be. The figure stays the largest thing on the page anyway: it is the
 * one thing a player is consenting to, and consent to a trivial charge is still
 * consent.
 *
 * THE SIGNATURE IS THE DISC OF SEVEN, and it is not an ornament: sight is one
 * hex (§5.6), so the tile underfoot and its six neighbors are literally the
 * whole of what a prospector can see. It is drawn from the map's own geometry
 * -- the same squashed 58x34 flat-top slabs, the same tiling, the same
 * painter's sort (§13.2) -- because it is not a picture of the map, it is the
 * map, at the one moment before anybody has walked anywhere.
 *
 * The ring resolves a tile at a time on load, which is a scouting report
 * arriving rather than a flourish. Nothing else on the screen moves.
 */
import { computed, onMounted, ref } from 'vue'
import { loadSettings, login, WalletError, type WalletKind, type WaxSettings } from '@/wallet/wax'
import { HEX_SIDE_PATH, HEX_TOP_PATH, tileToScreen } from '@/map/hexGeometry'
import { BIOME_COLOR, COPPER, VELLUM_DIM, shade } from '@/theme/palette'

const emit = defineEmits<{ (e: 'connected', wallet: string): void }>()

const settings = ref<WaxSettings | null>(null)
const busy = ref<WalletKind | null>(null)
const error = ref('')

/**
 * What is happening, in the wallet's words.
 *
 * A signing flow leaves the page -- a desktop app, a popup -- and comes back,
 * so the one thing this screen must never do is look idle while the player is
 * being asked to sign somewhere else.
 */
const stage = ref('')

/**
 * The seven, in map coordinates.
 *
 * Five biomes around the claim and one worked out (§5.1: drained, not dead, so
 * it keeps its own hue and only loses light). The order is clockwise from due
 * north, which is the order the ring lights up in.
 */
const UNSCOUTED = -0.4

const DISC = [
  { col: 0, row: 0, fill: VELLUM_DIM, claim: true },
  { col: 0, row: -1, fill: shade(BIOME_COLOR.forest, UNSCOUTED), claim: false },
  { col: 1, row: -1, fill: shade(BIOME_COLOR.mountain, UNSCOUTED), claim: false },
  { col: 1, row: 0, fill: shade(BIOME_COLOR.badlands, UNSCOUTED), claim: false },
  { col: 0, row: 1, fill: shade(BIOME_COLOR.grassland, UNSCOUTED), claim: false },
  { col: -1, row: -1, fill: shade(BIOME_COLOR.forest, -0.68), claim: false },
]

/**
 * Painter's algorithm, §13.2: sorted by screen Y so a tile's slab occludes the
 * one behind it. The reveal order is the DISC order and the draw order is this
 * one, which is why the index is carried rather than inferred from position.
 */
const tiles = computed(() =>
  DISC.map((tile, index) => ({
    ...tile,
    index,
    ...tileToScreen(tile.col, tile.row),
    side: shade(tile.fill, -0.45),
    rim: tile.claim ? COPPER : shade(tile.fill, -0.6),
  })).sort((a, b) => a.y - b.y),
)

onMounted(async () => {
  try {
    settings.value = await loadSettings()

    if (settings.value.wallet) {
      emit('connected', settings.value.wallet)
    }
  } catch {
    error.value = 'The server did not answer. Reload to try again.'
  }
})

async function connect(kind: WalletKind): Promise<void> {
  if (busy.value) return

  busy.value = kind
  error.value = ''
  stage.value = 'Waiting for your wallet…'

  try {
    stage.value = 'Sign the payment in your wallet.'
    const wallet = await login(kind)
    stage.value = ''
    emit('connected', wallet)
  } catch (e) {
    // A cancelled signature is a decision, not a fault. It gets the same quiet
    // line as anything else, and the server's own words when the server spoke.
    error.value = e instanceof WalletError ? e.message : 'The login did not finish.'
    stage.value = ''
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <div class="gate">
    <div class="sheet">
      <!--
        The claim. aria-hidden because it says nothing a screen reader needs:
        everything it means is in the copy beside it.
      -->
      <div class="disc" :class="{ signing: busy !== null }" aria-hidden="true">
        <svg viewBox="-82 -62 164 132" role="presentation">
          <g
            v-for="tile in tiles"
            :key="`${tile.col},${tile.row}`"
            class="tile"
            :class="{ claim: tile.claim }"
            :style="{ '--step': tile.index }"
            :transform="`translate(${tile.x},${tile.y})`"
          >
            <path :d="HEX_SIDE_PATH" :fill="tile.side" />
            <path :d="HEX_TOP_PATH" :fill="tile.fill" :stroke="tile.rim" stroke-width="1" />
          </g>
        </svg>
        <p class="caption">Sight is one hex</p>
      </div>

      <div class="terms">
        <h1>hexmine</h1>

        <p class="lead">
          One wallet, one character, kept for good. Sign a transfer and the
          signature proves the wallet is yours.
        </p>

        <div class="price">
          <p class="label">You send</p>
          <p class="fee">{{ settings?.fee ?? '—' }}</p>
          <p class="to">
            to <span>{{ settings?.account ?? '—' }}</span>
          </p>
        </div>

        <p class="note">
          The memo is issued to this browser, so nobody else can spend it.
        </p>

        <div class="wallets">
          <button
            class="btn"
            type="button"
            :disabled="!settings || busy !== null"
            @click="connect('cloudwallet')"
          >
            {{ busy === 'cloudwallet' ? 'Connecting…' : 'WAX Cloud Wallet' }}
          </button>
          <button
            class="btn ghost"
            type="button"
            :disabled="!settings || busy !== null"
            @click="connect('anchor')"
          >
            {{ busy === 'anchor' ? 'Connecting…' : 'Anchor' }}
          </button>
        </div>

        <p class="status" :class="{ bad: error !== '' }" role="status">
          {{ error || stage }}
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * MOBILE FIRST, and the two-column arrangement further down is the addition.
 * It was the other way round -- a desktop layout with a breakpoint that reset
 * the grid -- and the phone got whatever fell out of that: the claim shrunk
 * into a corner, the copy crammed against the top edge, and a third of the
 * screen left empty under the buttons.
 */
.gate {
  display: flex;
  flex-direction: column;
  /* Centred, so the slack sits above and below the block rather than inside it.
     Pushing the buttons to the very bottom put a void between the terms and the
     thing that agrees to them, which read as a page that had failed to load. */
  justify-content: center;
  /* dvh, not vh: on a phone vh is the viewport with the browser chrome pretending
     not to be there, which puts the buttons under the address bar. */
  min-height: 100dvh;
  padding: 34px 22px 30px;
  background: var(--ink);
}

.sheet {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 380px;
  margin: 0 auto;
}

/* The claim is the first thing on the page and is given room to be it. */
.disc {
  width: 172px;
}

.disc svg {
  display: block;
  width: 100%;
  height: auto;
  overflow: visible;
}

/* The scouting report arriving: the claim settles, then the ring, clockwise. */
.tile {
  transform-box: fill-box;
  transform-origin: center;
  animation: scout 420ms ease-out backwards;
  animation-delay: calc(var(--step) * 70ms);
}

@keyframes scout {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

/* While the wallet has the floor, the claim breathes. Nothing else moves. */
.signing .claim path:last-child {
  animation: breathe 1.6s ease-in-out infinite;
}

@keyframes breathe {
  50% {
    stroke-width: 2.4;
  }
}

/* Set to the picture's own width, so the label sits under what it labels and
   the page keeps one left edge all the way down. */
.caption {
  width: 172px;
  margin: 15px 0 26px;
  color: var(--copper);
  font-size: 10.5px;
  letter-spacing: 0.14em;
  line-height: 1.5;
  text-transform: uppercase;
}

h1 {
  margin: 0 0 12px;
  font-family: var(--font-display);
  font-size: 30px;
  font-weight: 600;
  letter-spacing: 0.01em;
}

.lead {
  margin: 0 0 22px;
  max-width: 38ch;
  color: var(--vellum-dim);
  line-height: 1.6;
}

/*
 * The price, given the top of the hierarchy on purpose: the decision on this
 * page is whether to be charged, and the name of the game is not that decision.
 */
.price {
  padding: 15px 0;
  border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
}

.label {
  margin: 0;
  color: var(--copper);
  font-size: 10.5px;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

/* §13.3 -- gold is the coin colour, and this is the only figure on the page. */
.fee {
  margin: 3px 0 2px;
  font-family: var(--font-display);
  font-size: 27px;
  line-height: 1.1;
  color: var(--gold);
  font-variant-numeric: tabular-nums;
}

.to {
  margin: 0;
  color: var(--vellum-dim);
  font-size: 12px;
}

.to span {
  color: var(--vellum);
}

.note {
  margin: 13px 0 22px;
  max-width: 36ch;
  color: var(--vellum-dim);
  font-size: 11.5px;
  line-height: 1.55;
}

.wallets {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.wallets .btn {
  width: 100%;
  /* A comfortable target, and the same height for both so neither reads as the
     more important tap. */
  min-height: 46px;
  background: var(--copper);
  color: var(--ink);
}

.wallets .btn:hover:not(:disabled) {
  background: var(--vellum);
}

/* The second wallet is the same act with its weight taken off, not a different
   kind of control -- so it is the same shape, quieter. */
.wallets .ghost {
  background: var(--ink-raised);
  color: var(--vellum);
}

.wallets .ghost:hover:not(:disabled) {
  background: var(--line);
}

.btn:disabled {
  opacity: 0.5;
  cursor: default;
}

/*
 * One line, always in the layout. A status that appears and disappears would
 * shove the buttons down the screen at the exact moment a finger is on its way
 * to one.
 */
.status {
  margin: 13px 0 0;
  min-height: 1.4em;
  color: var(--vellum-dim);
  font-size: 11.5px;
  line-height: 1.4;
}

/* §13.3 -- ember is a state to deal with, which a refused login is. */
.status.bad {
  color: var(--ember);
}

/*
 * Wide enough for two columns: the claim takes its own, and the buttons stop
 * being a thumb target and go back to sitting under what they are agreeing to.
 */
@media (min-width: 700px) {
  .gate {
    justify-content: center;
    padding: 40px;
  }

  .sheet {
    display: grid;
    grid-template-columns: minmax(0, 236px) minmax(0, 352px);
    align-items: center;
    gap: 48px;
    flex: 0 1 auto;
    max-width: none;
    width: auto;
  }

  .disc,
  .caption {
    width: 100%;
  }

  .caption {
    margin: 16px 0 0;
    text-align: center;
  }

}

@media (prefers-reduced-motion: reduce) {
  .tile,
  .signing .claim path:last-child {
    animation: none;
  }
}
</style>
