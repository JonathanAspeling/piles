<?php

namespace App\Models;

use App\Enums\CardTheme;
use App\Enums\GameStatus;
use Database\Factories\GameSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameSession extends Model
{
    /** @use HasFactory<GameSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'status',
        'host_user_id',
        'variant',
        'similar_items',
        'theme',
        'hard_variant',
        'sets_count',
        'winner_user_id',
        'rematch_game_session_id',
        'started_at',
        'ended_at',
    ];

    /**
     * Mirrors the column defaults so a freshly created game reports them
     * without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'variant' => false,
        'similar_items' => false,
        'theme' => 'standard',
        'hard_variant' => false,
    ];

    /**
     * Every game gets its own random background seed when it's created. A game
     * is never replayed (each new game is a new row), so the hard colour
     * variant backgrounds are reshuffled for every game.
     */
    protected static function booted(): void
    {
        static::creating(function (GameSession $game): void {
            if (! $game->background_seed) {
                $game->background_seed = self::newBackgroundSeed();
            }
        });
    }

    /**
     * A random positive 31-bit seed: fits an integer column on any database and
     * the 32-bit maths of the client PRNG.
     */
    public static function newBackgroundSeed(): int
    {
        return random_int(1, 0x7FFFFFFF);
    }

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'variant' => 'boolean',
            'similar_items' => 'boolean',
            'theme' => CardTheme::class,
            'hard_variant' => 'boolean',
            'background_seed' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    /** The lobby this game's players regroup in for "Play again", once someone asks for one. */
    public function rematchGame(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'rematch_game_session_id');
    }

    /**
     * Who has moved on to this game's rematch lobby, for the end-of-game panel.
     *
     * @return array{rematch_game_id: int|null, user_ids: array<int, int>}
     */
    public function rematchPayload(): array
    {
        $rematch = $this->rematchGame;

        return [
            'rematch_game_id' => $rematch?->id,
            'user_ids' => $rematch
                ? $rematch->gamePlayers()->orderBy('seat_index')->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all()
                : [],
        ];
    }

    /**
     * The centre as every player sees it: each pile's top card and version.
     *
     * @return array<int, array{id: int, pile_index: int, version: int, top_card: array{id: int, clothing_type: int, color: int}|null}>
     */
    public function centerPilesPayload(): array
    {
        return $this->centerPiles()->with('pileCards.card')->get()->map(function (Pile $pile): array {
            $topCard = $pile->pileCards->first()?->card;

            return [
                'id' => $pile->id,
                'pile_index' => $pile->pile_index,
                'version' => $pile->version,
                'top_card' => $topCard ? [
                    'id' => $topCard->id,
                    'clothing_type' => $topCard->clothing_type->value,
                    'color' => $topCard->color->value,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Everyone else at the table, as the given player may see them.
     *
     * Completed piles are public: they're revealed by PlayerPileCompleted the
     * moment they finish. An in-progress pile's contents stay private to its
     * owner, so only the completed ones surrender a clothing type.
     *
     * @return array<int, array{id: int, user_id: int, name: string, seat_index: int, piles: array<int, array{id: int, pile_index: int, is_completed: bool, completed_type: int|null}>}>
     */
    public function opponentsPayload(GamePlayer $viewer): array
    {
        return $this->gamePlayers()
            ->where('id', '!=', $viewer->id)
            ->with('user', 'piles.pileCards.card')
            ->get()
            ->map(fn (GamePlayer $player): array => [
                'id' => $player->id,
                'user_id' => $player->user_id,
                'name' => $player->user->name,
                'is_bot' => $player->user->is_bot,
                'seat_index' => $player->seat_index,
                'piles' => $player->piles->map(fn (Pile $pile): array => [
                    'id' => $pile->id,
                    'pile_index' => $pile->pile_index,
                    'is_completed' => $pile->is_completed,
                    'completed_type' => $pile->is_completed
                        ? $pile->pileCards->first()?->card->clothing_type->value
                        : null,
                ])->values()->all(),
            ])->values()->all();
    }

    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function piles(): HasMany
    {
        return $this->hasMany(Pile::class);
    }

    public function centerPiles(): HasMany
    {
        return $this->hasMany(Pile::class)->whereNull('game_player_id')->orderBy('pile_index');
    }

    /**
     * Centre cards for this game. The real game deals 4, or 8 with the variant
     * (which adds one set to the deck), at every player count. The deck is then
     * 6 sets per player plus 1 (or plus 2 with the variant).
     */
    public function centerCardCount(int $playerCount): int
    {
        return $this->variant ? 8 : 4;
    }
}
