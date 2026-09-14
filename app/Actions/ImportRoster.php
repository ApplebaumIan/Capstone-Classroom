<?php

namespace App\Actions;

use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\RepositoryVisibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportRoster
{
    private const HEADERS = [
        'name',
        'canvas_user_id',
        'user_id',
        'login_id',
        'sections',
        'group_name',
        'canvas_group_id',
        'group_id',
    ];

    public function handle(Classroom $classroom, UploadedFile $roster, RepositoryVisibility $visibility): void
    {
        $handle = fopen($roster->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['roster' => 'The roster could not be read.']);
        }

        try {
            $headers = fgetcsv($handle, escape: '');

            if (is_array($headers) && isset($headers[0])) {
                $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            }

            if ($headers !== self::HEADERS) {
                throw ValidationException::withMessages([
                    'roster' => 'The roster headers must exactly match the Canvas group export format.',
                ]);
            }

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $line++;

                if ($values === [null]) {
                    continue;
                }

                if (count($values) !== count(self::HEADERS)) {
                    throw ValidationException::withMessages(['roster' => "Roster row {$line} has the wrong number of columns."]);
                }

                /** @var array<string, string> $row */
                $row = array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    array_combine(self::HEADERS, $values),
                );

                foreach (['name', 'canvas_user_id', 'user_id', 'login_id', 'sections', 'group_name'] as $required) {
                    if ($row[$required] === '') {
                        throw ValidationException::withMessages(['roster' => "Roster row {$line} is missing {$required}."]);
                    }
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['roster' => 'The roster must contain at least one student.']);
        }

        if (collect($rows)->duplicates('canvas_user_id')->isNotEmpty()) {
            throw ValidationException::withMessages(['roster' => 'Each Canvas user must appear only once in the roster.']);
        }

        DB::transaction(function () use ($classroom, $rows, $visibility): void {
            $classroom->groups()->where('created_manually', false)->delete();

            foreach (collect($rows)->groupBy('group_name') as $groupName => $groupRows) {
                $firstRow = $groupRows->first();
                $repositoryName = Str::slug((string) $groupName);

                if ($classroom->groups()->where('repository_name', $repositoryName)->exists()) {
                    $repositoryName = Str::limit($repositoryName, 90, '').'-'.substr(sha1((string) $groupName), 0, 8);
                }

                if ($repositoryName === '') {
                    $repositoryName = 'team-'.($firstRow['canvas_group_id'] ?: $firstRow['group_id']);
                }

                $group = ClassroomGroup::query()->create([
                    'classroom_id' => $classroom->id,
                    'name' => $groupName,
                    'canvas_group_id' => $firstRow['canvas_group_id'] ?: null,
                    'canvas_group_reference' => $firstRow['group_id'] ?: null,
                    'repository_name' => Str::limit($repositoryName, 100, ''),
                ]);

                foreach ($groupRows as $row) {
                    RosterEntry::query()->create([
                        'classroom_id' => $classroom->id,
                        'classroom_group_id' => $group->id,
                        'canvas_user_id' => $row['canvas_user_id'],
                        'canvas_login_id' => $row['login_id'],
                        'canvas_id' => $row['user_id'],
                        'name' => $row['name'],
                        'sections' => $row['sections'],
                    ]);
                }
            }

            $classroom->update([
                'repository_visibility' => $visibility,
                'roster_imported_at' => now(),
                'roster_skipped_at' => null,
            ]);
        });
    }
}
