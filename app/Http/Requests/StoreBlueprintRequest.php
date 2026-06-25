<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBlueprintRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        //
        return [
            'name' => ['required', 'string', 'max:255'],
            'target_audience' => ['sometimes', 'string', 'max:255'],
            'tone' => ['sometimes', 'string', 'max:255'],
            'max_characters' => ['sometimes', 'integer', 'min:1', 'max:4000'],
            'max_hashtags' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'forbidden_words' => ['sometimes', 'array'],
            'forbidden_words.*' => ['string', 'max:100'],
            'style_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
