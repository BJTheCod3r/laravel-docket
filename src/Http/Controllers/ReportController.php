<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Http\Controllers;

use BJTheCod3r\Docket\Actions\CreateReport;
use BJTheCod3r\Docket\Actions\DeleteReport;
use BJTheCod3r\Docket\Actions\ListReports;
use BJTheCod3r\Docket\Actions\ShowReport;
use BJTheCod3r\Docket\Http\Requests\IndexReportsRequest;
use BJTheCod3r\Docket\Http\Requests\StoreReportRequest;
use BJTheCod3r\Docket\Http\Resources\ReportResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ReportController extends Controller
{
    public function index(IndexReportsRequest $request, ListReports $list): AnonymousResourceCollection
    {
        return ReportResource::collection($list->handle(
            $request->user(),
            category: $request->validated('category'),
            kind: $request->validated('kind'),
            perPage: $request->perPage(),
        ));
    }

    public function show(Request $request, string $report, ShowReport $show): ReportResource
    {
        return new ReportResource($show->handle($report, $request->user()));
    }

    public function store(StoreReportRequest $request, CreateReport $create): ReportResource
    {
        return new ReportResource($create->handle($request->user(), $request->validated(), $request->file('attachments')));
    }

    public function destroy(Request $request, string $report, DeleteReport $delete): Response
    {
        $delete->handle($report, $request->user());

        return response()->noContent();
    }
}
