<?php

namespace App\Support\Hr\Import;

use App\Enums\ImportType;
use App\Support\Hr\Import\Handlers\ActivationPreviewHandler;
use App\Support\Hr\Import\Handlers\CompaniesHandler;
use App\Support\Hr\Import\Handlers\EmployeeRegistrationsHandler;
use App\Support\Hr\Import\Handlers\WaitingListHandler;

/** Maps an import type to its handler. The only place that knows every workflow. */
final class ImportRegistry
{
    public function for(ImportType $type): ImportTypeHandler
    {
        return match ($type) {
            ImportType::Companies => app(CompaniesHandler::class),
            ImportType::EmployeeRegistrations => app(EmployeeRegistrationsHandler::class),
            ImportType::WaitingList => app(WaitingListHandler::class),
            ImportType::ActivationPreview => app(ActivationPreviewHandler::class),
        };
    }
}
