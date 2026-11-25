# Piles! — Design Document

## Game Rules

Original game by Lost Boy Entertainment / FoxMind (2024). Rules are summarised here for development reference.

### Components

- 63 sets of clothing cards. Each set = 4 cards of the **same clothing item** in different colours.
- Total: 252 cards.

### Sets per Player Count

Sets used = 6 × players + 1: 6 piles of 4 cards per player, plus 4 centre cards. The same rule holds at every player count; 9–10 players extend it within the 63 sets.

| Players | Centre | Sets used | Total cards |
|---------|--------|-----------|-------------|
| 2 | 4 | 13 | 48 + 4 centre = 52 |
| 3 | 4 | 19 | 72 + 4 centre = 76 |
| 4 | 4 | 25 | 96 + 4 centre = 100 |
| 5 | 4 | 31 | 120 + 4 centre = 124 |
| 6 | 4 | 37 | 144 + 4 centre = 148 |
| 7 | 4 | 43 | 168 + 4 centre = 172 |
| 8 | 4 | 49 | 192 + 4 centre = 196 |
| 9 | 4 | 55 | 216 + 4 centre = 220 |
| 10 | 4 | 61 | 240 + 4 centre = 244 |

With the variant, add one set and deal 8 centre cards: 6 × players + 2 sets (62 at 10 players).

### Setup

1. Build the deck based on player count, shuffle.
2. Deal each player **6 piles of 4 cards** face-down (players may not look until the game starts).
3. Place the remaining **4 cards face-down** in the centre (visible to all once flipped).

### Starting the Game

All 4 centre cards are flipped simultaneously (countdown from 3). Once flipped, all players begin simultaneously — there are no turns.

### How to Play

- **Goal:** Convert all 6 of your piles into matching sets (4 cards of the same clothing type).
- You may hold **only one pile** at a time.
- To change the cards in a pile, **swap with the centre**: discard one card face-up to the centre *first*, then pick up a centre card.
- You may only swap **one card at a time**.
- You **cannot** transfer cards between your own piles or take cards from other players.
- Piles you are not holding must **always contain exactly 4 cards** and remain **face-down** (unless completed).
- Once a pile is a complete matching set, place it **face-up** in front of you.

### Winning

The first player to have all 6 piles face-up (all sets complete) shouts **"PILES!"**. The winner's piles are verified; if a mistake is found, play resumes immediately.

### Variant (Chaos Mode)

At any player count, the host can choose variant: it adds **one extra set** to the deck and deals **8 cards to the centre** instead of 4.

### Similar items (hard mode)

Not in the original rules, and independent of the variant: the variant sets the deck's size, Similar items only changes which items make it up. `App\Services\DeckBuilder` picks the items.

- **Off (default):** the deck avoids look-alike items. It only adds an item with a look-alike already in the deck once no fully distinct item is left.
- **On:** items are picked at random, with no preference for any group, but look-alikes always come in at least pairs. Whenever a picked item has no look-alike in the deck yet, one random look-alike joins it. The last slot only takes an item that won't be left on its own (an ungrouped item, or one whose look-alike is already in the deck), unless none is left. A group can still end up with 3 or more items by chance.

The look-alike groups live in `App\Services\ClothingSimilarity::GROUPS`. Two items are similar when they share a group; an item can be in several groups or none.

---

## Architecture

```
┌──────────────────────────────────────────────┐
│  Browser                                     │
│  Vue 3 + Pinia stores                        │
│  ↕ HTTP (Inertia page visits + game actions) │
│  ↕ WebSocket (Laravel Echo → Reverb)         │
└──────────────────────┬───────────────────────┘
                       │
┌──────────────────────▼───────────────────────┐
│  Laravel 12                                  │
│  Controllers → Services → Models             │
│  Broadcasting via Reverb                     │
└──────────────────────┬───────────────────────┘
                       │
┌──────────────────────▼───────────────────────┐
│  Database (SQLite local / MySQL production)  │
└──────────────────────────────────────────────┘
```

**Key design decision:** Game actions (swap card, pick up pile, claim "PILES!") are HTTP POST requests, not WebSocket messages. This gives us database transactions, proper authentication, and easy rate limiting. Reverb is used exclusively to broadcast state changes to other players after an action is committed.

---

## Data Models

### `game_sessions`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `code` | string(6) unique | Human-readable join code (e.g. `FXQR7K`) |
| `status` | enum | `lobby`, `countdown`, `playing`, `verifying`, `ended` |
| `host_user_id` | FK → users | |
| `variant` | boolean | Chaos mode (8 centre cards) |
| `similar_items` | boolean | Hard mode: look-alike items come in at least pairs |
| `sets_count` | tinyint | Derived from player count at game start |
| `winner_user_id` | FK → users, nullable | |
| `started_at` | timestamp, nullable | |
| `ended_at` | timestamp, nullable | |

### `game_players`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `game_session_id` | FK | |
| `user_id` | FK → users | |
| `seat_index` | tinyint | 0–6, display position |
| `is_ready` | boolean | |
| `connected_at` | timestamp, nullable | |
| `disconnected_at` | timestamp, nullable | |

### `cards`

Static lookup table — seeded once, never mutated.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `clothing_type` | tinyint | 0–43 (the set identifier) |
| `color` | tinyint | 0–3 (four colours per set) |

### `piles`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `game_session_id` | FK | |
| `game_player_id` | FK, nullable | `null` = centre pile |
| `pile_index` | tinyint | 0–5 for player piles; 0–3 (or 0–7 variant) for centre |
| `is_completed` | boolean | True once the pile is a matching set |
| `version` | integer | Optimistic lock counter for concurrent swap protection |

### `pile_cards`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `pile_id` | FK | |
| `card_id` | FK | |
| `position` | tinyint | 0–3, card position within the pile |

### Relationships

```
User         hasMany  GamePlayer
GameSession  hasMany  GamePlayer
GameSession  hasMany  Pile
GamePlayer   hasMany  Pile      (their 6 piles)
Pile         hasMany  PileCard
PileCard     belongsTo Card
```

---

## Game State Machine

```
lobby ──► countdown (3 s) ──► playing ──► verifying ──► ended
                                              │
                                              └──► playing  (claim rejected, resume)
```

| Transition | Trigger | Actor |
|---|---|---|
| `lobby → countdown` | Host presses Start (≥2 players, all ready) | HTTP |
| `countdown → playing` | 3-second timer elapses | Server job |
| `playing → verifying` | Player claims "PILES!" | HTTP |
| `verifying → ended` | Server confirms all 6 sets valid | Server |
| `verifying → playing` | Server rejects claim | Server |

---

## WebSocket Events (Reverb)

### Channels

- `presence-game.{gameSessionId}` — all players in the session
- `private-game.{gameSessionId}.player.{gamePlayerId}` — per-player private channel (carries card data only the player should see)

### Event Reference

| Event | Channel | Fired when |
|---|---|---|
| `GameLobbyUpdated` | presence | Player joins, leaves, or toggles ready |
| `GameCountdownStarted` | presence | Host triggers start |
| `GameStarted` | presence + private | Countdown ends; private carries this player's 6 piles + card data |
| `CenterPileSwapped` | presence | Successful swap committed; the centre pile's new top card and version (the authoritative result every screen converges on) |
| `PlayerPileCompleted` | presence | Player's pile becomes a matching set (cards revealed) |
| `GameEnded` | presence | Claim verified; includes winner info |
| `GameResumed` | presence | Claim rejected; game continues |
| `PlayerDisconnected` | presence | Player goes offline mid-game |
| `PlayerReconnected` | presence | Player reconnects |

---

## HTTP Routes

```
GET  /lobby                    → lobby index (Inertia)
GET  /games/{game}             → game room (Inertia — lobby + board states)
GET  /join/{code}              → invite link: seats you and opens the room (signed out → home page first, then back here)

POST /games                    → create a new session
POST /games/join               → join by 6-character code
POST /games/{game}/ready       → toggle ready state
POST /games/{game}/start       → host starts countdown
DELETE /games/{game}/leave     → leave a session

POST /games/{game}/client-ready → mark client fully loaded (all-ready gate for countdown)
GET  /games/{game}/status      → poll current status (safety fallback for stuck clients)
GET  /games/{game}/state       → full snapshot for this player: status, centre, own hand, opponents' progress (resync)

POST /games/{game}/pickup      → pick up a card from one of your piles into your hand
POST /games/{game}/swap        → swap the held card with a centre pile (atomic)
POST /games/{game}/piles/reorder → rearrange your piles (full order; presentational)
POST /games/{game}/piles/{pile}/cards/reorder → rearrange the cards in one of your piles (409 if a swap changed them first)
POST /games/{game}/claim       → claim "PILES!" (verified server-side)
POST /games/{game}/forfeit     → forfeit the game (ends the session)
```

---

## Concurrency — Centre Card Swap

The most critical race condition: two players simultaneously grabbing the same centre card.

### Strategy

`CardSwapService` checks an optimistic `version` column on the centre pile inside a transaction:

1. Client sends `POST /games/{game}/swap` with `{ center_pile_id, center_card_id, expected_version }` (the held card is the one recorded by `pickup`).
2. Server opens a transaction and reads the centre pile.
3. If `pile.version !== expected_version`, the swap is stale — return **HTTP 409 Conflict**.
4. Otherwise, move cards and increment `pile.version`.
5. After commit, broadcast `CenterPileSwapped` (pile id, new version, new top card) to everyone.

**SQLite.** `lockForUpdate()` is a no-op on SQLite; what serialises swaps is the database's single writer. The connection uses `transaction_mode: IMMEDIATE`, so a transaction takes the write lock at `BEGIN` and concurrent swaps queue (up to `busy_timeout`). Under the default deferred mode, a transaction that read and then tried to write after another connection committed failed at once with "database is locked", turning simultaneous swaps into 500s, even on different piles.

### Clients: the server is the authority

- The swapping player's own screen shows the swap immediately (optimistic) and rolls back on a 409 or error.
- No other screen shows a swap until `CenterPileSwapped` arrives. There are no client-to-client whispers: with several players swapping at once, whispered guesses and cancels arrive in any order and left screens disagreeing with the server.
- A centre pile only ever moves forward: an event or resync applies if its version is at least the one shown, so late or out-of-order messages can't roll it back. At the same version, the server's card replaces a local guess.
- On a 409 the client resyncs from `GET /games/{game}/state`.

### Missed broadcasts

Broadcasts aren't replayed, so a screen that subscribed late or dropped its connection catches up from `GET /games/{game}/state` instead: whenever the presence channel (re)subscribes (`here`), and every few seconds while the table is all ready in the lobby or counting down. A resync moves the screen forward to the server's phase, never back.

`client-ready` is only sent once a screen has both the centre (`GameStarted`) and its own hand (`PlayerHandDealt`), and is retried until it lands; the server records it in one transaction and ignores repeats, so exactly one confirmation starts the countdown. The countdown waits for every player, so a single lost confirmation used to hold the whole table at "Get ready…".

`tests/e2e/swap-race.spec.ts` plays a 10-player table racing for the centre, over HTTP (server consistency) and through ten browsers (every screen converges on the server's centre).

---

## Win Verification

1. `POST /games/{game}/claim` received.
2. Server atomically transitions `playing → verifying` using a conditional `UPDATE ... WHERE status = 'playing'`. If zero rows are affected, a concurrent claim already won — return 409 and stop.
3. `GameVerifierService::verify()` loads all 6 of the claimant's piles with their cards.
4. Checks: exactly 4 cards per pile, all cards in each pile share the same `clothing_type`, all 4 colours (0–3) present.
5. If valid: mark `status = 'ended'`, set `winner_user_id`, broadcast `GameEnded`. The client jumps straight from `playing` to `ended` — no interstitial "PILES!" announcement.
6. If invalid: revert `verifying → playing`, broadcast `GameResumed`, game continues.

The `verifying` state is retained server-side as a concurrency guard but is deliberately invisible to clients. `games.status` remains as a polling endpoint so a client that missed the terminal broadcast can recover.

---

## Win-state UX

Three deliberate design choices sit on top of the state machine to make the ending feel decisive:

- **Ready-to-claim CTA.** At 6/6 completed piles, the sticky-hand header swaps to "You're ready — call PILES! to win! ↓" and a full-width pulsing emerald bar CTA appears above the pile grid. The pulse is a scoped keyframed `box-shadow` (not `animate-pulse`, which would fade the text). Forfeit stays visible but visually demoted.
- **Face-up completed piles.** Once a pile is completed it renders as a 4-card colour fan (cards slightly offset via `translateX`). On desktop, the pile's clothing silhouette is overlaid via `CardArt.vue`. `PileViewer` gates all interactions on `is_completed`, and `GameBoard` watches `activePile.is_completed` to auto-close the viewer when the completing swap arrives asynchronously.
- **Winner-only celebration.** `GameView.vue` watches an `iWon` computed (`isEnded && winner.user_id === currentPlayer.user_id`). On the false → true transition it calls `celebrate()` from `resources/js/utils/celebrate.ts`, which fires an initial `canvas-confetti` burst and then repeats every 2 s until `stopCelebrating()` runs (Back-to-Lobby click or `onUnmounted`). The winner's modal reads "Congratulations, {name}! 🎉"; every other player sees the plain "{name} wins!" copy. Because `watch` defaults to non-immediate, reloading during the ended state does *not* re-trigger the confetti.

---

## Frontend State (Pinia)

### `useLobbyStore`

Manages the pre-game session browser and join flow.

```typescript
state: {
  availableGames: GameSummary[]
  currentGame: GameSession | null
  players: LobbyPlayer[]
  countdownEndsAt: Date | null
}
```

### `useGameStore`

The authoritative client-side game state. Everything the board UI renders derives from this store.

```typescript
state: {
  session: GameSession | null
  currentPlayer: GamePlayer | null
  players: LobbyPlayer[]             // lobby-view player list
  myPiles: PlayerPile[]              // 6 piles with full card data (private)
  myPickedUpCard: Card | null        // card currently in hand
  centerPiles: CenterPile[]          // 4 or 8 piles
  opponents: OpponentState[]
  winner: GameWinner | null
  forfeitedBy: string | null
}
```

### `useEchoStore`

Manages Reverb channel subscriptions and bridges incoming events into `useGameStore`. Separated to keep WebSocket concerns isolated from game state.

### `useNotificationStore`

Transient toast queue for in-game events ("Swap failed — try again", "Claim rejected — resume!") with auto-dismiss.

---

## Directory Structure

```
app/
  Enums/
    GameStatus.php
    CardColor.php
    ClothingType.php
  Events/
    GameLobbyUpdated.php
    GameCountdownStarted.php
    GameStarted.php
    CenterCardSwapped.php
    PlayerPileCompleted.php
    GameEnded.php
    GameResumed.php
  Http/
    Controllers/
      LobbyController.php
      GameSessionController.php
      GameplayController.php
    Requests/
      CreateGameRequest.php
      JoinGameRequest.php
      SwapCardRequest.php
      ClaimPilesRequest.php
  Models/
    GameSession.php
    GamePlayer.php
    Card.php
    Pile.php
    PileCard.php
  Services/
    GameDealerService.php     # shuffles deck, deals piles
    CardSwapService.php       # atomic centre-card swap
    GameVerifierService.php   # validates PILES! claims

database/
  migrations/
    xxxx_create_game_sessions_table.php
    xxxx_create_game_players_table.php
    xxxx_create_cards_table.php
    xxxx_create_piles_table.php
    xxxx_create_pile_cards_table.php
  seeders/
    CardSeeder.php            # seeds all 176 cards once

routes/
  game.php
  channels.php

resources/js/
  pages/
    Lobby/Index.vue
    Game/Show.vue
  components/
    game/
      GameBoard.vue
      PlayerPile.vue
      CenterPile.vue
      OpponentRow.vue
      CountdownOverlay.vue
      LobbyPanel.vue
      GameView.vue          # top-level shell that swaps lobby ↔ board on status
      PileViewer.vue
      CardArt.vue
  stores/
    lobby.ts
    game.ts
    echo.ts
    notification.ts
  types/
    game.ts
  utils/
    celebrate.ts        # canvas-confetti wrapper for the winner-only burst
    cardArt.ts          # base64-inlined card silhouettes
```

---

## Deployment (Coolify / Raspberry Pi)

- Laravel served via PHP-FPM + Nginx.
- Reverb runs as a separate long-lived process: `php artisan reverb:start --host=0.0.0.0 --port=8080`
- Configure Reverb as a separate worker/service in Coolify alongside the main app.
- Queue worker required for countdown jobs: `php artisan queue:work`
- Run `php artisan migrate --seed` on first deploy (seeds the `cards` table).
- `npm run build` must be run during the build step (Vite bundles assets).

### Nginx WebSocket Proxy

Nginx must proxy WebSocket connections to Reverb. Add to the server block:

```nginx
location /app {
    proxy_pass http://localhost:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
}
```
