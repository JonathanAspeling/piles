<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PlayerNameRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthenticatedSessionController extends Controller
{
    public function store(PlayerNameRequest $request): RedirectResponse
    {
        Auth::login($this->playerNamed($request->validated('name')), remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('lobby.index', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Names are matched case-insensitively so one person keeps one account
     * across devices, and the retry covers two players claiming a new name
     * at the same moment.
     */
    private function playerNamed(string $name): User
    {
        if ($player = $this->findByName($name)) {
            return $player;
        }

        try {
            return User::create(['name' => $name]);
        } catch (UniqueConstraintViolationException $e) {
            return $this->findByName($name) ?? throw $e;
        }
    }

    private function findByName(string $name): ?User
    {
        return User::whereRaw('lower(name) = ?', [Str::lower($name)])->first();
    }
}
