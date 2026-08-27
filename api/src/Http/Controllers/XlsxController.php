<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Content\XlsxExportService;
use Dana\Domain\Content\XlsxImportService;
use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * FR-13.15: the superadmin moves content in and out as one .xlsx
 * workbook — the whole database, one level, or one parent unit.
 *
 * Export is a plain download. Import validates every row first and
 * writes all-or-nothing; whatever it writes lands as draft behind the
 * publish gate (FR-13.20), so a spreadsheet can never reach a student
 * directly. Student data is untouched in both directions.
 */
final class XlsxController extends Controller
{
    public function __construct(
        private readonly XlsxExportService $exporter,
        private readonly XlsxImportService $importer,
    ) {
    }

    /** GET /manage/export.xlsx?scope=all|level|unit&id=N */
    public function export(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $params = $request->getQueryParams();
        $scope = (string) ($params['scope'] ?? XlsxExportService::SCOPE_ALL);
        $id = isset($params['id']) && $params['id'] !== '' ? (int) $params['id'] : null;

        $response->getBody()->write($this->exporter->workbook($scope, $id)->toString());

        $name = 'dana-content-' . $scope . ($id !== null ? '-' . $id : '') . '-' . date('Ymd') . '.xlsx';

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
    }

    /**
     * GET /manage/data-summary — the numbers behind the «База данных»
     * page: how much content the database holds, plus the level → unit
     * tree the panel turns into per-scope export buttons. Counts only;
     * student data has no business here, same as in the workbook.
     */
    public function summary(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $sections = Capsule::table('sections')
            ->select('status', Capsule::raw('COUNT(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        $counts = [
            'levels'             => Capsule::table('levels')->count(),
            'units'              => Capsule::table('units')->count(),
            'child_units'        => Capsule::table('unit_sections')->count(),
            'sections_published' => (int) ($sections['published'] ?? 0),
            'sections_draft'     => (int) ($sections['draft'] ?? 0),
            'words'              => Capsule::table('vocabulary_items')->count(),
            'exercise_sets'      => Capsule::table('exercise_sets')->count(),
            // Soft-deleted questions are attempt-history bookkeeping,
            // not content — the export skips them, so does the count.
            'questions'          => Capsule::table('questions')->where('is_active', 1)->count(),
        ];

        $units = Capsule::table('units as u')
            ->leftJoin('unit_sections as cu', 'cu.unit_id', '=', 'u.id')
            ->groupBy('u.id', 'u.level_id', 'u.number', 'u.name', 'u.title', 'u.sort_order')
            ->orderBy('u.sort_order')->orderBy('u.number')
            ->get(['u.id', 'u.level_id', 'u.number', 'u.name', 'u.title', Capsule::raw('COUNT(cu.id) as child_units')]);

        $levels = Capsule::table('levels')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn ($level): array => [
                'id'    => (int) $level->id,
                'name'  => $level->name,
                'units' => $units
                    ->where('level_id', $level->id)
                    ->values()
                    ->map(static fn ($unit): array => [
                        'id'          => (int) $unit->id,
                        'number'      => (int) $unit->number,
                        // Manual naming (FR-15.7): the export picker must
                        // identify named units the way the rest of the
                        // panel does, not by a number nobody chose.
                        'name'        => $unit->name,
                        'title'       => $unit->title,
                        'child_units' => (int) $unit->child_units,
                    ])
                    ->all(),
            ])
            ->all();

        return $this->json($response, ['counts' => $counts, 'levels' => $levels]);
    }

    /**
     * POST /manage/import-xlsx — multipart. The panel may send ONE file
     * or the client's whole set (Listening + Grammar + Vocabulary +
     * UnitQuiz + Wordlist) in a single request, under any field name
     * (`file`, `files[]`, …) — the importer treats them as one
     * all-or-nothing transaction.
     */
    public function import(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $uploads = [];

        $flatten = static function (mixed $node) use (&$flatten, &$uploads): void {
            if ($node instanceof UploadedFileInterface) {
                $uploads[] = $node;
            } elseif (is_array($node)) {
                foreach ($node as $child) {
                    $flatten($child);
                }
            }
        };
        $flatten($request->getUploadedFiles());

        if ($uploads === []) {
            throw ApiException::validation(
                '.xlsx faýly saýlanmady ýa-da ýüklenmedi.',
                'Файл .xlsx не выбран или не загрузился.'
            );
        }

        $files = [];

        try {
            foreach ($uploads as $upload) {
                $name = (string) ($upload->getClientFilename() ?? 'workbook.xlsx');

                if ($upload->getError() !== UPLOAD_ERR_OK) {
                    throw ApiException::validation(
                        "«{$name}» ýüklenmedi.",
                        "«{$name}» не загрузился."
                    );
                }

                // Buffer to a real path — ZipArchive opens files, not streams.
                $tmp = tempnam(sys_get_temp_dir(), 'dana-import-');

                if ($tmp === false) {
                    throw new RuntimeException('Cannot create a temp file for the uploaded workbook.');
                }

                $files[] = ['name' => $name, 'path' => $tmp];
                $upload->moveTo($tmp);
            }

            return $this->json($response, $this->importer->import($files));
        } finally {
            foreach ($files as $file) {
                @unlink($file['path']);
            }
        }
    }
}
