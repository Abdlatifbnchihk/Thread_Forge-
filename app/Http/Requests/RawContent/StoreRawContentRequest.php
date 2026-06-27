<?php

namespace App\Http\Requests\RawContent;

use Illuminate\Foundation\Http\FormRequest;

class StoreRawContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body'        => ['required', 'string', 'min:20', 'max:50000'],
            'source_type' => ['sometimes', 'in:manual,markdown,github,notes'],
            'title'       => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.min' => 'Please provide at least 20 characters of content to transform.',
        ];
    }
}
