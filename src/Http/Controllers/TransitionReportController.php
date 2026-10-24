<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Http\Controllers;

use BJTheCod3r\Docket\Actions\TransitionReport;
use BJTheCod3r\Docket\Exceptions\InvalidTransitionException;
use BJTheCod3r\Docket\Http\Requests\TransitionReportRequest;
use BJTheCod3r\Docket\Http\Resources\ReportResource;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class TransitionReportController extends Controller
{
    public function __invoke(TransitionReportRequest $request, string $report, TransitionReport $transition): ReportResource
    {
        try {
            $updated = $transition->handle($report, $request->validated('status'), by: $request->user(), reason: $request->validated('reason'));
        } catch (InvalidTransitionException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return new ReportResource($updated);
    }
}
