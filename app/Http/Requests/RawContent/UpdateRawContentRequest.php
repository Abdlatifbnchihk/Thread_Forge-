<?php

namespace App\Http\Requests\RawContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRawContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body'        => ['sometimes', 'string', 'min:20', 'max:50000'],
            'source_type' => ['sometimes', 'in:manual,markdown,github,notes'],
            'title'       => ['nullable', 'string', 'max:255'],
        ];
    }
}
