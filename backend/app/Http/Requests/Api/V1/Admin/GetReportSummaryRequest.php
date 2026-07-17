<?php

namespace App\Http\Requests\Api\V1\Admin;

/**
 * Report summary endpoints accept exactly the dashboard date filter
 * contract (filter, start_date, end_date), so the dashboard request is
 * reused instead of duplicating its rules and accessors.
 */
class GetReportSummaryRequest extends GetDashboardRequest {}
