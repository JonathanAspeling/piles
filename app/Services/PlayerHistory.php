<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A player's record across the games they've finished: totals, their best
 * time, the people they play most and their latest games, for the lobby.
 *
 * Only games that actually got going count: a game that ended in its lobby
 * was never played. Play time runs from GO to the end, so it is only known
 * for games that recorded both.
 */
class PlayerHistory
{
    public const RECENT_GAMES = 10;

    public const TOP_RIVALS = 3;

    /**
     * @return array{
     *     played: int,
     *     won: int,
     *     win_streak: int,
     *     fastest_win_seconds: int|null,
     *     rivals: list<array{name: string, games: int, my_wins: int, their_wins: int}>,
     *     recent: list<array{id: int, code: string, ended_at: string|null, duration_seconds: int|null, result: string, winner_name: string|null, opponents: list<string>, variant: bool, similar_items: bool}>
     * }
     */
    public function for(User $user): array
    {
        /** @var Collection<int, GameSession> $games */
        $games = GameSession::query()
            ->where('status', GameStatus::Ended)
            ->whereNotNull('started_at')
            ->whereHas('gamePlayers', fn ($query) => $query->where('user_id', $user->id))
            ->with(['gamePlayers.user:id,name', 'winner:id,name'])
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->get();

        $wins = $games->where('winner_user_id', $user->id);

        return [
            'played' => $games->count(),
            'won' => $wins->count(),
            'win_streak' => $games->takeWhile(fn (GameSession $game) => $game->winner_user_id === $user->id)->count(),
            'fastest_win_seconds' => $wins->map(fn (GameSession $game) => $this->durationSeconds($game))->filter(fn (?int $seconds) => $seconds !== null)->min(),
            'rivals' => $this->rivals($games, $user),
            'recent' => $games->take(self::RECENT_GAMES)->map(fn (GameSession $game) => [
                'id' => $game->id,
                'code' => $game->code,
                'ended_at' => $game->ended_at?->toIso8601String(),
                'duration_seconds' => $this->durationSeconds($game),
                'result' => match ($game->winner_user_id) {
                    null => 'no_winner',
                    $user->id => 'won',
                    default => 'lost',
                },
                'winner_name' => $game->winner?->name,
                'opponents' => $game->gamePlayers
                    ->where('user_id', '!=', $user->id)
                    ->sortBy('seat_index')
                    ->map(fn ($player) => $player->user->name)
                    ->values()
                    ->all(),
                'variant' => (bool) $game->variant,
                'similar_items' => (bool) $game->similar_items,
            ])->values()->all(),
        ];
    }

    /**
     * The people this player has shared the most games with, and how the
     * games between them went.
     *
     * @param  Collection<int, GameSession>  $games
     * @return list<array{name: string, games: int, my_wins: int, their_wins: int}>
     */
    private function rivals(Collection $games, User $user): array
    {
        return $games
            ->flatMap(fn (GameSession $game) => $game->gamePlayers
                ->where('user_id', '!=', $user->id)
                ->map(fn ($player) => ['user' => $player->user, 'game' => $game]))
            ->groupBy(fn (array $pairing) => $pairing['user']->id)
            ->map(fn (Collection $pairings) => [
                'name' => $pairings->first()['user']->name,
                'games' => $pairings->count(),
                'my_wins' => $pairings->filter(fn (array $pairing) => $pairing['game']->winner_user_id === $user->id)->count(),
                'their_wins' => $pairings->filter(fn (array $pairing) => $pairing['game']->winner_user_id === $pairing['user']->id)->count(),
            ])
            ->sortByDesc('games')
            ->take(self::TOP_RIVALS)
            ->values()
            ->all();
    }

    private function durationSeconds(GameSession $game): ?int
    {
        if (! $game->started_at || ! $game->ended_at || $game->ended_at->lt($game->started_at)) {
            return null;
        }

        return (int) $game->started_at->diffInSeconds($game->ended_at);
    }
}
