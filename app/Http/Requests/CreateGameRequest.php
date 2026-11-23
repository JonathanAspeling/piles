<?php

namespace App\Http\Requests;

use App\Enums\CardTheme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'variant' => 'boolean',
            'similar_items' => 'boolean',
            'theme' => [Rule::enum(CardTheme::class)],
            'hard_variant' => 'boolean',
        ];
    }
}
