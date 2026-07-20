<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\GameResumed;
use App\Events\LobbyUpdated;
use App\Events\PlayerPileCompleted;
use App\Exceptions\StaleVersionException;
use App\Http\Requests\ClaimPilesRequest;
use App\Http\Requests\SwapCardRequest;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Services\CardSwapService;
use App\Services\GameVerifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GameplayController extends Controller
{
    public function pickup(GameSession $game, Request $request): Response
    {
        abort_unless($game->status === GameStatus::Playing, 422, 'Game is not in progress.');

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $cardId = $request->integer('card_id');

        $pileCard = PileCard::whereHas('pile', function ($q) use ($game, $player) {
            $q->where('game_session_id', $game->id)
                ->where('game_player_id', $player->id)
                ->where('is_completed', false);
        })->where('card_id', $cardId)->firstOrFail();

        $player->update([
            'picked_up_card_id' => $cardId,
            'picked_up_pile_id' => $pileCard->pile_id,
        ]);

        return response()->noContent();
    }

    public function swap(GameSession $game, SwapCardRequest $request, CardSwapService $cardSwapService): JsonResponse
    {
        abort_unless($game->status === GameStatus::Playing, 422, 'Game is not in progress.');

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        abort_unless($player->picked_up_card_id !== null, 422, 'You have not picked up a card.');

        $myCardId = $player->picked_up_card_id;
        $myPileId = $player->picked_up_pile_id;

        try {
            $cardSwapService->swap(
                $game,
                $player,
                $request->integer('center_pile_id'),
                $request->integer('center_card_id'),
                $myCardId,
                $request->integer('expected_version'),
            );
        } catch (StaleVersionException) {
            return response()->json(['message' => 'Stale version conflict.'], 409);
        }

        $player->update(['picked_up_card_id' => null, 'picked_up_pile_id' => null]);

        $playerPile = Pile::where('id', $myPileId)
            ->where('game_session_id', $game->id)
            ->where('game_player_id', $player->id)
            ->with('pileCards.card')
            ->firstOrFail();

        $clothingTypes = $playerPile->pileCards->map(fn ($pc) => $pc->card->clothing_type->value)->unique();

        if ($playerPile->pileCards->count() === 4 && $clothingTypes->count() === 1) {
            $playerPile->update(['is_completed' => true]);
            broadcast(new PlayerPileCompleted($game, $player, $playerPile));
        }

        return response()->json(['message' => 'Swap successful.'], 200);
    }

    public function claimPiles(GameSession $game, ClaimPilesRequest $request, GameVerifierService $verifier): JsonResponse
    {
        abort_unless($game->status === GameStatus::Playing, 422, 'Game is not in progress.');

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->with('user')
            ->firstOrFail();

        // Atomic transition — only the first simultaneous claim proceeds
        $transitioned = GameSession::where('id', $game->id)
            ->where('status', GameStatus::Playing)
            ->update(['status' => GameStatus::Verifying]);

        if (! $transitioned) {
            return response()->json(['message' => 'A claim is already being verified.'], 422);
        }

        // Resync so subsequent $game->update() calls treat Verifying as the
        // baseline. Without this, Eloquent's dirty check treats a Verifying→Playing
        // reset as no-op (original attribute was still Playing) and silently
        // skips the SQL UPDATE, leaving the game stuck in Verifying.
        $game->refresh();

        $isValid = $verifier->verify($player);

        if ($isValid) {
            $game->update([
                'status' => GameStatus::Ended,
                'winner_user_id' => $player->user_id,
            ]);

            broadcast(new GameEnded($game, $player));
        } else {
            $game->update(['status' => GameStatus::Playing]);

            broadcast(new GameResumed($game, $player->user->name));
        }

        return response()->json(['message' => 'Claim processed.'], 200);
    }

    public function centerPiles(GameSession $game): JsonResponse
    {
        $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $centerPiles = $game->centerPiles()->with('pileCards.card')->get()->map(fn (Pile $pile) => [
            'id' => $pile->id,
            'pile_index' => $pile->pile_index,
            'version' => $pile->version,
            'top_card' => $pile->pileCards->first() ? [
                'id' => $pile->pileCards->first()->card->id,
                'clothing_type' => $pile->pileCards->first()->card->clothing_type->value,
                'color' => $pile->pileCards->first()->card->color->value,
            ] : null,
        ])->values()->all();

        return response()->json($centerPiles);
    }

    public function forfeit(GameSession $game): Response
    {
        abort_unless(
            in_array($game->status, [GameStatus::Playing, GameStatus::Verifying]),
            422,
            'You can only forfeit an active game.'
        );

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->with('user')
            ->firstOrFail();

        $game->update([
            'status' => GameStatus::Ended,
            'winner_user_id' => null,
            'ended_at' => now(),
        ]);

        broadcast(new GameEnded($game, winner: null, forfeitedBy: $player->user->name));
        broadcast(new LobbyUpdated);

        return response()->noContent();
    }
}
