<?php

namespace App\Services\Reports;

use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Enums\ReportType;
use App\Exceptions\Reports\UnsupportedReportTypeException;

class ReportManager
{
    /**
     * @var list<ReportGeneratorInterface>
     */
    private array $generators = [];

    /**
     * @param  iterable<ReportGeneratorInterface>  $generators
     */
    public function __construct(iterable $generators = [])
    {
        foreach ($generators as $generator) {
            $this->register($generator);
        }
    }

    public function register(ReportGeneratorInterface $generator): void
    {
        $this->generators[] = $generator;
    }

    public function generatorFor(ReportType|string $type): ReportGeneratorInterface
    {
        $reportType = $this->resolveType($type);

        foreach ($this->generators as $generator) {
            if ($generator->supports($reportType)) {
                return $generator;
            }
        }

        throw UnsupportedReportTypeException::forType($reportType);
    }

    public function supports(ReportType|string $type): bool
    {
        $reportType = $type instanceof ReportType ? $type : ReportType::tryFrom($type);

        if ($reportType === null) {
            return false;
        }

        foreach ($this->generators as $generator) {
            if ($generator->supports($reportType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ReportGeneratorInterface>
     */
    public function generators(): array
    {
        return $this->generators;
    }

    /**
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        $types = [];

        foreach (ReportType::cases() as $type) {
            if ($this->supports($type)) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    private function resolveType(ReportType|string $type): ReportType
    {
        if ($type instanceof ReportType) {
            return $type;
        }

        return ReportType::tryFrom($type) ?? throw UnsupportedReportTypeException::forType($type);
    }
}
