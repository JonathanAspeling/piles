<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PileCard extends Model
{
    protected $fillable = [
        'pile_id',
        'card_id',
        'position',
    ];

    public function pile(): BelongsTo
    {
        return $this->belongsTo(Pile::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
