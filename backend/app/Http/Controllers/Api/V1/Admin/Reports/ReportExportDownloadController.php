<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Actions\Report\DownloadReportExportAction;
use App\Exceptions\Reports\ReportExportNotDownloadableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DownloadReportExportRequest;
use App\Models\ReportExport;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportDownloadController extends Controller
{
    public function show(
        DownloadReportExportRequest $request,
        ReportExport $export,
        DownloadReportExportAction $downloadReportExport,
    ): StreamedResponse|JsonResponse {
        try {
            return $downloadReportExport->handle($export);
        } catch (ReportExportNotDownloadableException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->httpStatus,
            );
        }
    }
}
