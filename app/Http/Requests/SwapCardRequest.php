<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SwapCardRequest extends FormRequest
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
            'pile_id' => 'required|integer',
            'my_card_id' => 'required|integer',
            'center_pile_id' => 'required|integer',
            'center_card_id' => 'required|integer',
            'expected_version' => 'required|integer|min:0',
        ];
    }
}
