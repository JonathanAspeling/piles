<?php

namespace App\Http\Controllers;

use App\Enums\ClaimOutcome;
use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\LobbyUpdated;
use App\Exceptions\StaleVersionException;
use App\Http\Requests\ClaimPilesRequest;
use App\Http\Requests\ReorderPileCardsRequest;
use App\Http\Requests\ReorderPilesRequest;
use App\Http\Requests\SwapCardRequest;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Services\CardSwapService;
use App\Services\PileReorderService;
use App\Services\PilesClaimService;
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

    /**
     * Put a picked-up card back down without swapping it.
     *
     * Deliberately has no "game is in progress" guard: a release firing as the
     * game ends is a harmless state clear, and a 422 there would surface a
     * spurious error to the player.
     */
    public function release(GameSession $game): Response
    {
        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $player->update([
            'picked_up_card_id' => null,
            'picked_up_pile_id' => null,
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
            $cardSwapService->move(
                $game,
                $player,
                $request->integer('center_pile_id'),
                $request->integer('center_card_id'),
                $myCardId,
                $myPileId,
                $request->integer('expected_version'),
            );
        } catch (StaleVersionException) {
            return response()->json(['message' => 'Stale version conflict.'], 409);
        }

        return response()->json(['message' => 'Swap successful.'], 200);
    }

    public function claimPiles(GameSession $game, ClaimPilesRequest $request, PilesClaimService $claims): JsonResponse
    {
        abort_unless($game->status === GameStatus::Playing, 422, 'Game is not in progress.');

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->with('user')
            ->firstOrFail();

        if ($claims->claim($game, $player) === ClaimOutcome::AlreadyVerifying) {
            return response()->json(['message' => 'A claim is already being verified.'], 422);
        }

        return response()->json(['message' => 'Claim processed.'], 200);
    }

    /**
     * Rearrange the player's own piles.
     *
     * Purely presentational: pile_index drives nothing in the rules, and
     * opponents only ever see which piles are finished. It lives on the server
     * so the order survives the refresh a player reaches for when something
     * looks wrong.
     */
    public function reorderPiles(GameSession $game, ReorderPilesRequest $request, PileReorderService $pileReorderService): Response
    {
        abort_unless($game->status === GameStatus::Playing, 422, 'Game is not in progress.');

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $pileReorderService->reorder($game, $player, $request->input('pile_ids'));

        return response()->noContent();
    }

    public function reorderPileCards(GameSession $game, Pile $pile, ReorderPileCardsRequest $request, PileReorderService $pileReorderService): Response
    {
        abort_unless($game->status === GameStatus::Playing, 422, 'Game is not in progress.');

        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $pileReorderService->reorderCards($game, $player, $pile->id, $request->input('card_ids'));

        return response()->noContent();
    }

    /**
     * The authoritative game as this player is allowed to see it.
     *
     * The client resyncs from this whenever it may have missed a broadcast:
     * after losing a swap race, on (re)joining the game channel, or when a
     * countdown runs long. So it carries everything the board needs: status,
     * centre, the player's own hand and their opponents' progress.
     */
    public function state(GameSession $game): JsonResponse
    {
        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $myPiles = $player->piles()->with('pileCards.card')->get()
            ->map(fn (Pile $pile) => $pile->toPlayerPayload())
            ->values()
            ->all();

        return response()->json([
            'status' => $game->status->value,
            'center_piles' => $game->centerPilesPayload(),
            'my_piles' => $myPiles,
            'opponents' => $game->opponentsPayload($player),
        ]);
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
