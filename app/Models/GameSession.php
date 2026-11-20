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
        'theme',
        'hard_variant',
        'sets_count',
        'winner_user_id',
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
