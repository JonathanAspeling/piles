<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_bot',
        'email',
        'password',
    ];

    /** Names for computer players. Each is one reusable account, so adding bots never grows the users table. */
    public const BOT_NAMES = ['Sprocket', 'Widget', 'Gizmo', 'Pixel', 'Ratchet', 'Bolt', 'Dot', 'Cog', 'Byte'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_bot' => 'boolean',
        ];
    }

    /**
     * A bot account not yet seated at this table, created the first time its
     * name is needed. Null when every bot name is already playing here.
     */
    public static function availableBotFor(GameSession $game): ?self
    {
        $seatedNames = $game->gamePlayers()
            ->whereHas('user', fn ($query) => $query->where('is_bot', true))
            ->with('user:id,name')
            ->get()
            ->pluck('user.name');

        $name = collect(self::BOT_NAMES)->diff($seatedNames)->first();

        return $name === null ? null : self::firstOrCreate(['name' => $name, 'is_bot' => true]);
    }
}
