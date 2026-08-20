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

    protected $touches = ['gameSession'];

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

    /**
     * The owner's private view of this pile, cards and all.
     *
     * Shared by the page props, the deal broadcast and the resync endpoint so a
     * client can swap one for another without the shapes drifting apart.
     *
     * @return array{id: int, pile_index: int, is_completed: bool, cards: array<int, array{id: int, clothing_type: int, color: int}>}
     */
    public function toPlayerPayload(): array
    {
        return [
            'id' => $this->id,
            'pile_index' => $this->pile_index,
            'is_completed' => $this->is_completed,
            'cards' => $this->pileCards->map(fn (PileCard $pileCard) => [
                'id' => $pileCard->card->id,
                'clothing_type' => $pileCard->card->clothing_type->value,
                'color' => $pileCard->card->color->value,
            ])->values()->all(),
        ];
    }
}
