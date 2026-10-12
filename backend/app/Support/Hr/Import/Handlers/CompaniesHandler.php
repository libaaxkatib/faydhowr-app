<?php

namespace App\Support\Hr\Import\Handlers;

use App\Actions\Hr\CreateClientCompanyAction;
use App\Enums\ImportType;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Hr\Import\ImportNormalizer;
use App\Support\Hr\Import\ImportTypeHandler;
use App\Support\Hr\Import\RowOutcome;
use App\Support\Hr\Import\StagedRow;

/**
 * Client companies. File columns: name, category (company|guri), aliases (optional).
 * `aliases` uses the approved plan format: spellings separated by "|", each optionally followed by
 * " @ rows 170,171" as its source reference.
 *
 * A company is matched by its name or by an approved alias, ignoring case, spacing and punctuation. A
 * company that already exists is skipped and never changed. A name or alias that points at more than one
 * company, or an alias that belongs to a different existing company, is sent to manual review. Nothing is
 * dropped for being "unused": every valid row is imported.
 */
final class CompaniesHandler implements ImportTypeHandler
{
    public function __construct(private readonly CreateClientCompanyAction $createCompany) {}

    public function type(): ImportType
    {
        return ImportType::Companies;
    }

    public function columns(): array
    {
        return [
            'name' => ['company name', 'company', 'client company', 'client'],
            'category' => ['type'],
            'aliases' => ['alias', 'also known as', 'other spellings'],
        ];
    }

    public function requiredColumns(): array
    {
        return ['name', 'category'];
    }

    public function ignoredColumns(): array
    {
        return [];
    }

    public function stage(array $cells, array &$seen): StagedRow
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $cells['name'] ?? ''));
        $category = mb_strtolower(trim($cells['category'] ?? ''));
        $aliases = $this->parseAliases($cells['aliases'] ?? '');

        if ($name === '') {
            return StagedRow::invalid('NAME_REQUIRED', 'The company name is required.');
        }

        if (mb_strlen($name) > 150) {
            return StagedRow::invalid('NAME_TOO_LONG', 'The company name is longer than 150 characters.');
        }

        if (! in_array($category, ['company', 'guri'], true)) {
            return StagedRow::invalid('CATEGORY_INVALID', "The category must be 'company' or 'guri'.");
        }

        foreach ($aliases as $alias) {
            if (mb_strlen($alias['alias']) > 150) {
                return StagedRow::invalid('ALIAS_TOO_LONG', 'An alias is longer than 150 characters.');
            }
        }

        $key = ImportNormalizer::nameKey($name);

        if ($key === '') {
            return StagedRow::invalid('NAME_REQUIRED', 'The company name has no letters or digits.');
        }

        $payload = ['name' => $name, 'category' => $category, 'aliases' => $aliases];

        if (isset($seen['names'][$key])) {
            return StagedRow::valid($payload, $key)->asRepeatInFile('company');
        }

        $seen['names'][$key] = true;
        $index = $this->index($seen);

        if (isset($seen['alias_owner'][$key]) && $seen['alias_owner'][$key] !== $key) {
            return StagedRow::review('ALIAS_SHARED_IN_FILE', 'This company name is also an alias of a different company earlier in this file.', $payload, $key);
        }

        // 1. The company already exists under this name or an approved alias.
        $byName = $index['names'][$key] ?? [];
        $byAlias = $index['aliases'][$key] ?? [];
        $owners = array_values(array_unique([...$byName, ...$byAlias]));

        if (count($owners) > 1) {
            return StagedRow::review('AMBIGUOUS_MATCH', 'This name matches more than one existing company. Decide which one it is before importing.', $payload, $key, null, ['company_ids' => $owners]);
        }

        if (count($owners) === 1) {
            return StagedRow::duplicate(
                $byName !== [] ? 'COMPANY_EXISTS' : 'ALIAS_EXISTS',
                $byName !== [] ? 'A company with this name already exists. It was left unchanged.' : 'This name is an approved alias of an existing company. It was left unchanged.',
                $payload,
                $key,
                null,
                ['company_ids' => $owners],
                ClientCompany::class,
                $owners[0],
            );
        }

        // 2. One of the row's aliases belongs to a different existing company, or to another row of this file.
        foreach ($aliases as $alias) {
            $aliasKey = ImportNormalizer::nameKey($alias['alias']);

            if ($aliasKey === '' || $aliasKey === $key) {
                continue;
            }

            $existing = array_values(array_unique([...($index['names'][$aliasKey] ?? []), ...($index['aliases'][$aliasKey] ?? [])]));

            if ($existing !== []) {
                return StagedRow::review('ALIAS_BELONGS_TO_OTHER_COMPANY', 'An alias on this row already belongs to an existing company.', $payload, $key, null, ['company_ids' => $existing]);
            }

            if (isset($seen['alias_owner'][$aliasKey]) && $seen['alias_owner'][$aliasKey] !== $key) {
                return StagedRow::review('ALIAS_SHARED_IN_FILE', 'An alias on this row is also used by a different company in this file.', $payload, $key);
            }

            if (isset($seen['names'][$aliasKey]) && $aliasKey !== $key) {
                return StagedRow::review('ALIAS_SHARED_IN_FILE', 'An alias on this row is the name of a different company in this file.', $payload, $key);
            }

            $seen['alias_owner'][$aliasKey] = $key;
        }

        return StagedRow::valid($payload, $key);
    }

    public function process(ImportRow $row, ImportBatch $batch, Admin $actor): RowOutcome
    {
        $data = (array) $row->payload;
        $key = ImportNormalizer::nameKey((string) ($data['name'] ?? ''));

        // Re-check the live data: the preview may be old, and two admins may have been working.
        $index = $this->liveIndex();
        $owners = array_values(array_unique([...($index['names'][$key] ?? []), ...($index['aliases'][$key] ?? [])]));

        if ($owners !== []) {
            return RowOutcome::skipped('COMPANY_EXISTS', 'The company now exists. It was left unchanged.', ClientCompany::class, $owners[0]);
        }

        $company = $this->createCompany->handle(['name' => $data['name'], 'category' => $data['category']], $actor);

        foreach ((array) ($data['aliases'] ?? []) as $alias) {
            $aliasKey = ImportNormalizer::nameKey((string) $alias['alias']);

            if ($aliasKey === '' || $aliasKey === $key || isset($index['names'][$aliasKey]) || isset($index['aliases'][$aliasKey])) {
                continue; // never attach a spelling that already identifies another company
            }

            ClientCompanyAlias::query()->firstOrCreate(
                ['client_company_id' => $company->id, 'alias' => $alias['alias']],
                ['source' => "Import #{$batch->id}", 'source_reference' => $alias['reference'] ?? null, 'created_by' => $actor->id],
            );
        }

        return RowOutcome::imported(ClientCompany::class, $company->id, 'Company registered.');
    }

    /**
     * @return list<array{alias: string, reference: ?string}>
     */
    private function parseAliases(string $raw): array
    {
        $aliases = [];

        foreach (array_filter(array_map('trim', explode('|', $raw))) as $part) {
            [$alias, $reference] = array_pad(array_map('trim', explode(' @ ', $part, 2)), 2, null);

            if ($alias !== '') {
                $aliases[] = ['alias' => $alias, 'reference' => $reference];
            }
        }

        return $aliases;
    }

    /**
     * @param  array<string, mixed>  $seen
     * @return array{names: array<string, list<int>>, aliases: array<string, list<int>>}
     */
    private function index(array &$seen): array
    {
        return $seen['db'] ??= $this->liveIndex();
    }

    /**
     * @return array{names: array<string, list<int>>, aliases: array<string, list<int>>}
     */
    private function liveIndex(): array
    {
        $names = [];
        $aliases = [];

        foreach (ClientCompany::query()->get(['id', 'name']) as $company) {
            $names[ImportNormalizer::nameKey($company->name)][] = $company->id;
        }

        foreach (ClientCompanyAlias::query()->get(['client_company_id', 'alias']) as $alias) {
            $aliases[ImportNormalizer::nameKey($alias->alias)][] = $alias->client_company_id;
        }

        return ['names' => $names, 'aliases' => $aliases];
    }
}
