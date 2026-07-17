<?php

namespace App\Contracts\Reports;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Common contract for immutable report DTOs produced by the report
 * services. Exports (such as the PDF generator) consume this contract so
 * they stay reusable for every report type without knowing concrete DTOs.
 *
 * @extends Arrayable<string, mixed>
 */
interface ReportDataInterface extends Arrayable, JsonSerializable {}
