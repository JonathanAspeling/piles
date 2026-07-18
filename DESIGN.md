# Piles! — Design Document

## Game Rules

Original game by Lost Boy Entertainment / FoxMind (2024). Rules are summarised here for development reference.

### Components

- 44 sets of clothing cards. Each set = 4 cards of the **same clothing item** in different colours.
- Total: 176 cards.

### Sets per Player Count

| Players | Sets used | Total cards |
|---------|-----------|-------------|
| 2 | 13 | 52 + 4 center = 56 |
| 3 | 19 | 76 + 4 center = 80 |
| 4 | 25 | 100 + 4 center = 104 |
| 5 | 31 | 124 + 4 center = 128 |
| 6 | 37 | 148 + 4 center = 152 |
| 7 | 43 | 172 + 4 center = 176 |

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

Add 1 extra set to the deck and deal **8 cards to the centre** instead of 4.

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
| `PlayerPilePickedUp` | presence | Player picks up a pile |
| `CenterCardSwapped` | presence | Successful swap; reveals the discarded card, hides the taken card |
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

POST /games                    → create a new session
POST /games/join               → join by 6-character code
POST /games/{game}/ready       → toggle ready state
POST /games/{game}/start       → host starts countdown
DELETE /games/{game}/leave     → leave a session

POST /games/{game}/client-ready → mark client fully loaded (all-ready gate for countdown)
GET  /games/{game}/status      → poll current status (safety fallback for stuck clients)

POST /games/{game}/pickup      → pick up a card from one of your piles into your hand
POST /games/{game}/swap        → swap the held card with a centre pile (atomic)
POST /games/{game}/claim       → claim "PILES!" (verified server-side)
POST /games/{game}/forfeit     → forfeit the game (ends the session)
```

---

## Concurrency — Centre Card Swap

The most critical race condition: two players simultaneously grabbing the same centre card.

### Strategy

`CardSwapService` uses a `SELECT ... FOR UPDATE` on the specific centre pile row combined with an optimistic `version` column:

1. Client sends `POST /games/{game}/swap` with `{ center_pile_id, center_card_id, my_card_id, expected_version }`.
2. Server opens a transaction and locks the centre pile row (`FOR UPDATE`).
3. If `pile.version !== expected_version`, the swap is stale — return **HTTP 409 Conflict**.
4. Otherwise, move cards and increment `pile.version`.
5. Broadcast `CenterCardSwapped` to all players (the discarded card is revealed; the taken card is not).

On a 409 the client silently re-evaluates: the concurrent `CenterCardSwapped` event it already received shows the new centre pile state, so the player picks a different card.

Only the specific centre pile row is locked, so players can simultaneously interact with *different* centre piles without blocking each other.

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
  isSwapping: boolean                // guards optimistic swap
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
    PlayerPilePickedUp.php
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
