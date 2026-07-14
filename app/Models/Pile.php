<?php

namespace App\Models;

use Database\Factories\PileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pile extends Model
{
    /** @use HasFactory<PileFactory> */
    use HasFactory;

    protected $fillable = [
        'game_session_id',
        'game_player_id',
        'pile_index',
        'is_completed',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
        ];
    }

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class);
    }

    public function gamePlayer(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class);
    }

    public function pileCards(): HasMany
    {
        return $this->hasMany(PileCard::class)->orderBy('position');
    }

    public function isCenter(): bool
    {
        return $this->game_player_id === null;
    }
}
