<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderPilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shape only — that the ids are the player's own, and all of them, is
     * checked against the database in PileReorderService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pile_ids' => 'required|array|min:1',
            'pile_ids.*' => 'required|integer|distinct',
        ];
    }
}
