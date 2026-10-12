<?php

namespace Tests\Support;

use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builders for the HR import tests. Every workbook is generated in memory/temp from fake data; nothing
 * here reads a real workbook or touches a real database.
 */
trait ImportFixtures
{
    protected const IMPORTS = '/api/v1/admin/hr/imports';

    /**
     * Authenticates the next request as the owner of $token. Laravel's test client keeps the first
     * authenticated user for the rest of a test, so when one test switches between identities the auth
     * guards must be reset first; otherwise a later token silently acts as the first user.
     */
    protected function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @return array{Admin, string} */
    protected function importSuperAdmin(): array
    {
        $admin = Admin::factory()->superAdmin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function csvUpload(array $header, array $rows, string $name = 'import.csv'): UploadedFile
    {
        $lines = [implode(',', array_map(fn ($c) => $this->csvCell($c), $header))];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($c) => $this->csvCell($c), $row));
        }

        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function xlsxUpload(array $header, array $rows, string $name = 'import.xlsx', array $extraCells = []): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([$header, ...$rows], null, 'A1', true);

        foreach ($extraCells as $address => $value) {
            $sheet->setCellValue($address, $value);
        }

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function upload(string $token, string $type, UploadedFile $file): TestResponse
    {
        return $this->as($token)->post(self::IMPORTS, ['type' => $type, 'file' => $file], ['Accept' => 'application/json']);
    }

    protected function confirmImport(string $token, int $id): TestResponse
    {
        return $this->as($token)->postJson(self::IMPORTS."/{$id}/confirm", ['confirm' => true]);
    }

    /** Confirms and then processes until the batch is completed (bounded loop). */
    protected function runImport(string $token, int $id): TestResponse
    {
        $this->confirmImport($token, $id)->assertOk();

        $response = null;

        for ($i = 0; $i < 60; $i++) {
            $response = $this->as($token)->postJson(self::IMPORTS."/{$id}/process")->assertOk();

            if ($response->json('data.status') === 'completed') {
                return $response;
            }
        }

        $this->fail('The import did not complete within 60 processing calls.');
    }

    /** Stages one file and returns the batch id, asserting the upload worked. */
    protected function stageImport(string $token, string $type, UploadedFile $file): int
    {
        return (int) $this->upload($token, $type, $file)->assertCreated()->json('data.id');
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/[",\n]/', $text) === 1 ? '"'.str_replace('"', '""', $text).'"' : $text;
    }
}
