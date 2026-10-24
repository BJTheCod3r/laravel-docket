<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Http\Requests;

use BJTheCod3r\Docket\Enums\Visibility;
use BJTheCod3r\Docket\Facades\Docket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'kind' => ['nullable', 'string', Rule::in(Docket::kinds()->all()->keys()->all())],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
            'priority' => ['nullable', 'integer'],
            'meta' => ['nullable', 'array'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string', Rule::exists(Docket::model('service'), 'key')->withoutTrashed()],
            'parent' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
        ];
    }
}
