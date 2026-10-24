<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Http\Requests;

use BJTheCod3r\Docket\Facades\Docket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Docket::statuses()->all()->keys()->all())],
            'reason' => ['nullable', 'string'],
        ];
    }
}
