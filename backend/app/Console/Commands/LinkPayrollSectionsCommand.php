<?php

namespace App\Console\Commands;

use App\Support\Hr\Payroll\PayrollSectionBackfill;
use Illuminate\Console\Command;

/**
 * Links the existing lines of every payroll run to their company sections (the same step the company-sections migration runs). Safe to
 * run again; with --dry-run it only reports what it would link. It never recalculates or regenerates payroll and writes only payroll_items.section_id.
 */
class LinkPayrollSectionsCommand extends Command
{
    protected $signature = 'hr:link-payroll-sections {--dry-run : Report what would be linked, write nothing}';

    protected $description = 'Link existing payroll lines to their company sections (no recalculation, idempotent).';

    public function handle(): int
    {
        $report = PayrollSectionBackfill::run((bool) $this->option('dry-run'));

        $this->line(json_encode($report, JSON_PRETTY_PRINT));
        $this->info($this->option('dry-run') ? 'Dry run: nothing was written.' : 'Done.');

        return self::SUCCESS;
    }
}
