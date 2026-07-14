<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\CenterCardSwapped;
use App\Events\GameEnded;
use App\Events\GameResumed;
use App\Events\PilesClaimMade;
use App\Events\PlayerPileCompleted;
use App\Exceptions\StaleVersionException;
use App\Http\Requests\ClaimPilesRequest;
use App\Http\Requests\SwapCardRequest;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use App\Services\CardSwapService;
use App\Services\GameVerifierService;
use Illuminate\Http\JsonResponse;

class GameplayController extends Controller
{
    public function swap(GameSession $game, SwapCardRequest $request, CardSwapService $cardSwapService): JsonResponse
    {
        abort_unless($game->status === GameStatus::Playing, 422, 'Game is not in progress.');

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        try {
            $cardSwapService->swap(
                $game,
                $player,
                $request->integer('center_pile_id'),
                $request->integer('center_card_id'),
                $request->integer('my_card_id'),
                $request->integer('expected_version'),
            );
        } catch (StaleVersionException) {
            return response()->json(['message' => 'Stale version conflict.'], 409);
        }

        $centerPile = Pile::findOrFail($request->integer('center_pile_id'));
        $incomingCard = Card::findOrFail($request->integer('my_card_id'));

        $playerPile = Pile::where('id', $request->integer('pile_id'))
            ->where('game_session_id', $game->id)
            ->where('game_player_id', $player->id)
            ->with('pileCards.card')
            ->firstOrFail();

        $clothingTypes = $playerPile->pileCards->map(fn ($pc) => $pc->card->clothing_type->value)->unique();

        if ($playerPile->pileCards->count() === 4 && $clothingTypes->count() === 1) {
            $playerPile->update(['is_completed' => true]);
            broadcast(new PlayerPileCompleted($game, $player, $playerPile));
        }

        broadcast(new CenterCardSwapped($game, $centerPile, $incomingCard, $request->integer('center_card_id')));

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

        $game->status = GameStatus::Verifying;

        broadcast(new PilesClaimMade($game, $player));

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
}
