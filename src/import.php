<?php

/**
 * Sites from a CSV file. The first line names the columns: "url" is
 * required; "group", "enabled", "name", "keyword" and "interval" are used
 * when present, others are ignored. Commas or semicolons (as spreadsheet
 * apps in many locales write them). Every problem is reported with its line
 * number, so the whole file can be fixed in one go.
 *
 * @return array{rows: list<array>, errors: list<string>}
 */
function parse_import_csv(string $contents): array
{
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
    if (trim($contents) === '') return ['rows' => [], 'errors' => ['the file is empty']];

    $firstLine = strtok($contents, "\n");
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $contents);
    rewind($handle);

    $header = array_map(fn ($name) => strtolower(trim((string) $name)), fgetcsv($handle, null, $delimiter, '"', '') ?: []);
    $columns = array_flip($header);
    if (!isset($columns['url'])) {
        return ['rows' => [], 'errors' => ['line 1: the first line must name the columns, with at least "url" (e.g. group,url,enabled)']];
    }
    $field = fn (array $record, string $name) => isset($columns[$name]) ? trim((string) ($record[$columns[$name]] ?? '')) : '';

    $rows = [];
    $errors = [];
    $seen = [];
    $line = 1;
    while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
        $line++;
        if (implode('', array_map(fn ($value) => trim((string) $value), $record)) === '') continue;

        $raw = $field($record, 'url');
        if ($raw === '') {
            $errors[] = "line {$line}: the url is empty";
            continue;
        }
        try {
            $url = normalize_url($raw);
        } catch (InvalidArgumentException $e) {
            $errors[] = "line {$line}: " . $e->getMessage();
            continue;
        }
        if (isset($seen[$url])) {
            $errors[] = "line {$line}: {$url} is already on line {$seen[$url]}";
            continue;
        }
        $seen[$url] = $line;

        $enabled = $field($record, 'enabled');
        $on = $enabled === '' ? true : parse_bool($enabled);
        if ($on === null) {
            $errors[] = "line {$line}: enabled must be yes or no (got \"{$enabled}\")";
            continue;
        }

        $rows[] = [
            'line' => $line,
            'url' => $url,
            'group' => $field($record, 'group'),
            'paused' => !$on,
            'name' => $field($record, 'name'),
            'keyword' => $field($record, 'keyword'),
            'interval' => $field($record, 'interval'),
        ];
    }
    fclose($handle);

    return ['rows' => $rows, 'errors' => $errors];
}

/**
 * Add the parsed rows: new sites are added (with their groups, created when
 * missing), sites already watched are left exactly as they are. All or
 * nothing: a row that fails rolls the whole import back. A dry run rolls
 * back too, after counting.
 *
 * @return array{added: int, paused: int, existing: int, new_groups: list<string>}
 */
function import_sites(PDO $db, array $rows, bool $dryRun = false): array
{
    $summary = ['added' => 0, 'paused' => 0, 'existing' => 0, 'new_groups' => []];

    $db->exec('BEGIN IMMEDIATE');
    try {
        foreach ($rows as $row) {
            if (site_by_url($db, $row['url'])) {
                $summary['existing']++;
                continue;
            }
            try {
                if ($row['group'] !== '' && !find_group($db, $row['group'])) {
                    add_group($db, ['name' => $row['group']]);
                    $summary['new_groups'][] = $row['group'];
                }
                add_site($db, [
                    'url' => $row['url'],
                    'group' => $row['group'],
                    'paused' => $row['paused'],
                    'name' => $row['name'],
                    'keyword' => $row['keyword'],
                    'interval' => $row['interval'],
                ]);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("line {$row['line']}: " . $e->getMessage(), 0, $e);
            }
            $summary['added']++;
            if ($row['paused']) $summary['paused']++;
        }
        $db->exec($dryRun ? 'ROLLBACK' : 'COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    return $summary;
}
