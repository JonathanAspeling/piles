<?php

namespace App\Models;

use Database\Factories\GamePlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamePlayer extends Model
{
    /** @use HasFactory<GamePlayerFactory> */
    use HasFactory;

    protected $fillable = [
        'game_session_id',
        'user_id',
        'seat_index',
        'is_ready',
        'is_game_ready',
        'connected_at',
        'disconnected_at',
    ];

    protected function casts(): array
    {
        return [
            'is_ready' => 'boolean',
            'is_game_ready' => 'boolean',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function piles(): HasMany
    {
        return $this->hasMany(Pile::class)->orderBy('pile_index');
    }

    public function completedPilesCount(): int
    {
        return $this->piles()->where('is_completed', true)->count();
    }
}
