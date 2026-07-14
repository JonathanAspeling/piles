<?php

namespace App\Models;

use App\Enums\CardColor;
use App\Enums\ClothingType;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    protected $fillable = [
        'clothing_type',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'clothing_type' => ClothingType::class,
            'color' => CardColor::class,
        ];
    }

    public function pileCards(): HasMany
    {
        return $this->hasMany(PileCard::class);
    }
}
