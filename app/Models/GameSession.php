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

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'variant' => 'boolean',
            'theme' => CardTheme::class,
            'hard_variant' => 'boolean',
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

    public function centerCardCount(int $playerCount): int
    {
        return ($this->variant || $playerCount >= 9) ? 8 : 4;
    }
}
