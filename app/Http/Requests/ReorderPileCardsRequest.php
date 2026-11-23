<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderPileCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shape only — that the ids are exactly the pile's cards right now is
     * checked against the database in PileReorderService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'card_ids' => 'required|array|min:1',
            'card_ids.*' => 'required|integer|distinct',
        ];
    }
}
