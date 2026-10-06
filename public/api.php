<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Templates.php';
require_once __DIR__ . '/../src/Auth.php';

require_login_api();

$pdo = Database::get();
$method = $_SERVER['REQUEST_METHOD'];
$path = trim((string) ($_GET['path'] ?? ''), '/');
$segments = $path === '' ? [] : explode('/', $path);

try {
    route($pdo, $method, $segments);
} catch (PDOException $e) {
    json_error('Database error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}

function route(PDO $pdo, string $method, array $seg): void
{
    // templates
    if ($seg === ['templates']) {
        $out = [];
        foreach (Templates::all() as $key => $t) {
            $out[] = ['key' => $key, 'label' => $t['label'], 'description' => $t['description']];
        }
        json_response($out);
    }

    // projects
    if ($seg === ['projects'] && $method === 'GET') {
        list_projects($pdo);
    }
    if ($seg === ['projects'] && $method === 'POST') {
        create_project($pdo);
    }
    if (count($seg) === 2 && $seg[0] === 'projects') {
        $id = (int) $seg[1];
        if ($method === 'GET') get_project($pdo, $id);
        if ($method === 'PUT') update_project($pdo, $id);
        if ($method === 'DELETE') delete_project($pdo, $id);
    }

    // columns
    if (count($seg) === 3 && $seg[0] === 'projects' && $seg[2] === 'columns') {
        $projectId = (int) $seg[1];
        if ($method === 'GET') list_columns($pdo, $projectId);
        if ($method === 'POST') create_column($pdo, $projectId);
    }
    if (count($seg) === 4 && $seg[0] === 'projects' && $seg[2] === 'columns' && $seg[3] === 'reorder' && $method === 'POST') {
        reorder_columns($pdo, (int) $seg[1]);
    }
    if (count($seg) === 2 && $seg[0] === 'columns') {
        $id = (int) $seg[1];
        if ($method === 'PUT') update_column($pdo, $id);
        if ($method === 'DELETE') delete_column($pdo, $id);
    }

    // rows
    if (count($seg) === 3 && $seg[0] === 'projects' && $seg[2] === 'rows') {
        $projectId = (int) $seg[1];
        if ($method === 'GET') list_rows($pdo, $projectId);
        if ($method === 'POST') create_row($pdo, $projectId);
    }
    if (count($seg) === 4 && $seg[0] === 'projects' && $seg[2] === 'rows' && $seg[3] === 'bulk' && $method === 'POST') {
        bulk_rows($pdo, (int) $seg[1]);
    }
    if (count($seg) === 2 && $seg[0] === 'rows') {
        $id = (int) $seg[1];
        if ($method === 'PUT') update_row($pdo, $id);
        if ($method === 'DELETE') delete_row($pdo, $id);
    }

    // export / dashboard
    if (count($seg) === 3 && $seg[0] === 'projects' && $seg[2] === 'export' && $method === 'GET') {
        export_csv($pdo, (int) $seg[1]);
    }
    if (count($seg) === 3 && $seg[0] === 'projects' && $seg[2] === 'dashboard' && $method === 'GET') {
        dashboard($pdo, (int) $seg[1]);
    }
    if (count($seg) === 3 && $seg[0] === 'projects' && $seg[2] === 'preview-import' && $method === 'POST') {
        preview_import($pdo, (int) $seg[1]);
    }
    if (count($seg) === 3 && $seg[0] === 'projects' && $seg[2] === 'commit-import' && $method === 'POST') {
        commit_import($pdo, (int) $seg[1]);
    }

    json_error('Not found: ' . $method . ' /' . implode('/', $seg), 404);
}

/* ---------------- Projects ---------------- */

function list_projects(PDO $pdo): void
{
    $rows = $pdo->query("
        SELECT p.*,
            (SELECT COUNT(*) FROM mop_rows r WHERE r.project_id = p.id) AS row_count,
            (SELECT COUNT(*) FROM mop_columns c WHERE c.project_id = p.id) AS column_count
        FROM mop_projects p ORDER BY p.updated_at DESC, p.id DESC
    ")->fetchAll();
    json_response($rows);
}

function create_project(PDO $pdo): void
{
    $body = read_json_body();
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') json_error('Nama project wajib diisi.');
    $description = (string) ($body['description'] ?? '');
    $templateKey = (string) ($body['template'] ?? 'blank');
    $template = Templates::get($templateKey) ?? Templates::get('blank');

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO mop_projects (name, description) VALUES (?,?)")->execute([$name, $description]);
    $projectId = (int) $pdo->lastInsertId();

    $order = 0;
    $existingKeys = [];
    foreach ($template['columns'] as $col) {
        $key = slugify($col['label'], $existingKeys);
        $existingKeys[] = $key;
        $pdo->prepare("
            INSERT INTO mop_columns (project_id, col_key, label, type, role, options, order_index, is_primary, required, width)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $projectId, $key, $col['label'], $col['type'] ?? 'text', $col['role'] ?? 'normal',
            json_encode($col['options'] ?? []), $order, !empty($col['is_primary']) ? 1 : 0, 0, $col['width'] ?? null,
        ]);
        $order++;
    }
    $pdo->commit();

    get_project($pdo, $projectId);
}

function get_project(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("SELECT * FROM mop_projects WHERE id = ?");
    $stmt->execute([$id]);
    $project = $stmt->fetch();
    if (!$project) json_error('Project tidak ditemukan.', 404);

    $project['columns'] = fetch_columns($pdo, $id);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mop_rows WHERE project_id = ?");
    $countStmt->execute([$id]);
    $project['row_count'] = (int) $countStmt->fetchColumn();

    json_response($project);
}

function update_project(PDO $pdo, int $id): void
{
    $body = read_json_body();
    $fields = [];
    $params = [];
    foreach (['name', 'description'] as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "$f = ?";
            $params[] = $body[$f];
        }
    }
    if (!$fields) json_error('Tidak ada perubahan.');
    $fields[] = "updated_at = CURRENT_TIMESTAMP";
    $params[] = $id;
    $pdo->prepare("UPDATE mop_projects SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    get_project($pdo, $id);
}

function delete_project(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM mop_projects WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);
}

/* ---------------- Columns ---------------- */

function fetch_columns(PDO $pdo, int $projectId): array
{
    $stmt = $pdo->prepare("SELECT * FROM mop_columns WHERE project_id = ? ORDER BY order_index ASC, id ASC");
    $stmt->execute([$projectId]);
    $cols = $stmt->fetchAll();
    foreach ($cols as &$c) {
        $c['options'] = json_decode($c['options'], true) ?: [];
        $c['is_primary'] = (bool) $c['is_primary'];
        $c['required'] = (bool) $c['required'];
    }
    return $cols;
}

function list_columns(PDO $pdo, int $projectId): void
{
    json_response(fetch_columns($pdo, $projectId));
}

function create_column(PDO $pdo, int $projectId): void
{
    $body = read_json_body();
    $label = trim((string) ($body['label'] ?? ''));
    if ($label === '') json_error('Label kolom wajib diisi.');

    $stmt = $pdo->prepare("SELECT col_key FROM mop_columns WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $key = slugify($label, $existing);

    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(order_index), -1) FROM mop_columns WHERE project_id = ?");
    $maxStmt->execute([$projectId]);
    $nextOrder = ((int) $maxStmt->fetchColumn()) + 1;

    $isPrimary = bool_from($body['is_primary'] ?? 0);
    if ($isPrimary) {
        $pdo->prepare("UPDATE mop_columns SET is_primary = 0 WHERE project_id = ?")->execute([$projectId]);
    }

    $pdo->prepare("
        INSERT INTO mop_columns (project_id, col_key, label, type, role, options, order_index, is_primary, required, width)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $projectId, $key, $label,
        $body['type'] ?? 'text', $body['role'] ?? 'normal',
        json_encode($body['options'] ?? []), $nextOrder, $isPrimary,
        bool_from($body['required'] ?? 0), $body['width'] ?? null,
    ]);

    json_response(['id' => (int) $pdo->lastInsertId(), 'col_key' => $key]);
}

function update_column(PDO $pdo, int $id): void
{
    $body = read_json_body();
    $stmt = $pdo->prepare("SELECT * FROM mop_columns WHERE id = ?");
    $stmt->execute([$id]);
    $col = $stmt->fetch();
    if (!$col) json_error('Kolom tidak ditemukan.', 404);

    $fields = [];
    $params = [];
    foreach (['label', 'type', 'role', 'width'] as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "$f = ?";
            $params[] = $body[$f];
        }
    }
    if (array_key_exists('options', $body)) {
        $fields[] = "options = ?";
        $params[] = json_encode($body['options']);
    }
    if (array_key_exists('required', $body)) {
        $fields[] = "required = ?";
        $params[] = bool_from($body['required']);
    }
    if (array_key_exists('is_primary', $body)) {
        $isPrimary = bool_from($body['is_primary']);
        if ($isPrimary) {
            $pdo->prepare("UPDATE mop_columns SET is_primary = 0 WHERE project_id = ?")->execute([$col['project_id']]);
        }
        $fields[] = "is_primary = ?";
        $params[] = $isPrimary;
    }
    if (!$fields) json_error('Tidak ada perubahan.');
    $params[] = $id;
    $pdo->prepare("UPDATE mop_columns SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    json_response(['ok' => true]);
}

function delete_column(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM mop_columns WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);
}

function reorder_columns(PDO $pdo, int $projectId): void
{
    $body = read_json_body();
    $ids = $body['ids'] ?? [];
    $order = 0;
    foreach ($ids as $id) {
        $pdo->prepare("UPDATE mop_columns SET order_index = ? WHERE id = ? AND project_id = ?")
            ->execute([$order, (int) $id, $projectId]);
        $order++;
    }
    json_response(['ok' => true]);
}

/* ---------------- Rows ---------------- */

function list_rows(PDO $pdo, int $projectId): void
{
    $stmt = $pdo->prepare("SELECT * FROM mop_rows WHERE project_id = ? ORDER BY row_no ASC, id ASC");
    $stmt->execute([$projectId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['data'] = json_decode($r['data'], true) ?: [];
    }
    json_response($rows);
}

function create_row(PDO $pdo, int $projectId): void
{
    $body = read_json_body();
    $data = is_array($body['data'] ?? null) ? $body['data'] : [];

    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(row_no), 0) FROM mop_rows WHERE project_id = ?");
    $maxStmt->execute([$projectId]);
    $rowNo = ((int) $maxStmt->fetchColumn()) + 1;

    $pdo->prepare("INSERT INTO mop_rows (project_id, row_no, data) VALUES (?,?,?)")
        ->execute([$projectId, $rowNo, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare("UPDATE mop_projects SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$projectId]);

    json_response(['id' => (int) $pdo->lastInsertId(), 'row_no' => $rowNo, 'data' => $data]);
}

function update_row(PDO $pdo, int $id): void
{
    $body = read_json_body();
    $stmt = $pdo->prepare("SELECT * FROM mop_rows WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Baris tidak ditemukan.', 404);

    $data = json_decode($row['data'], true) ?: [];
    if (is_array($body['data'] ?? null)) {
        $data = array_merge($data, $body['data']);
    }
    $pdo->prepare("UPDATE mop_rows SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $id]);
    $pdo->prepare("UPDATE mop_projects SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$row['project_id']]);

    json_response(['ok' => true, 'data' => $data]);
}

function delete_row(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM mop_rows WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);
}

function bulk_rows(PDO $pdo, int $projectId): void
{
    $body = read_json_body();
    $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
    if (!$rows) json_error('Tidak ada baris untuk diimpor.');

    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(row_no), 0) FROM mop_rows WHERE project_id = ?");
    $maxStmt->execute([$projectId]);
    $rowNo = (int) $maxStmt->fetchColumn();

    $insert = $pdo->prepare("INSERT INTO mop_rows (project_id, row_no, data) VALUES (?,?,?)");
    $pdo->beginTransaction();
    $count = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $rowNo++;
        $insert->execute([$projectId, $rowNo, json_encode($r, JSON_UNESCAPED_UNICODE)]);
        $count++;
    }
    $pdo->commit();
    $pdo->prepare("UPDATE mop_projects SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$projectId]);

    json_response(['ok' => true, 'imported' => $count]);
}

/**
 * Parses raw CSV text against the project's existing columns (matched by
 * label, case-insensitively) and reports which headers matched vs. will be
 * created as new columns, plus a preview of the first rows.
 */
function preview_import(PDO $pdo, int $projectId): void
{
    $body = read_json_body();
    $csvText = (string) ($body['csv'] ?? '');
    $headerRow = max(1, (int) ($body['header_row'] ?? 1));
    $delimiter = (string) ($body['delimiter'] ?? '');

    if (trim($csvText) === '') json_error('CSV kosong.');

    $lines = preg_split('/\r\n|\r|\n/', $csvText);
    if ($delimiter === '' || $delimiter === 'auto') {
        $delimiter = detect_delimiter($lines[$headerRow - 1] ?? $lines[0]);
    }

    $records = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $records[] = str_getcsv($line, $delimiter);
    }
    if (count($records) < $headerRow) json_error('Baris header melebihi jumlah baris file.');

    $headers = array_map(fn($h) => trim((string) $h), $records[$headerRow - 1]);
    $dataLines = array_slice($records, $headerRow);

    $columns = fetch_columns($pdo, $projectId);
    $byLabel = [];
    foreach ($columns as $c) {
        $byLabel[mb_strtolower(trim($c['label']))] = $c;
    }

    $mapping = [];
    foreach ($headers as $i => $h) {
        if ($h === '') continue;
        $match = $byLabel[mb_strtolower($h)] ?? null;
        $mapping[] = [
            'index' => $i,
            'header' => $h,
            'matched_column' => $match ? $match['col_key'] : null,
            'matched_label' => $match ? $match['label'] : null,
            'will_create' => $match === null,
        ];
    }

    $preview = [];
    foreach (array_slice($dataLines, 0, 5) as $line) {
        $row = [];
        foreach ($mapping as $m) {
            $row[$m['header']] = $line[$m['index']] ?? '';
        }
        $preview[] = $row;
    }

    json_response([
        'delimiter' => $delimiter,
        'header_row' => $headerRow,
        'total_data_rows' => count($dataLines),
        'mapping' => $mapping,
        'preview' => $preview,
    ]);
}

/**
 * Guesses type/role for an auto-created column based on its header text, so
 * CSVs shaped like the standard VM-migration MOP light up the dashboard
 * (status/group/date roles) without any manual column setup.
 */
function guess_column_shape(string $header): array
{
    $h = mb_strtolower($header);
    if (str_contains($h, 'status')) {
        return ['type' => 'status', 'role' => 'status'];
    }
    if (preg_match('/site|cluster|\btim\b|team|\bpic\b|aplikasi|activity|application/u', $h)) {
        return ['type' => 'text', 'role' => 'group'];
    }
    if (str_contains($h, 'malam') || str_contains($h, 'cutover') && !str_contains($h, 'jam')) {
        return ['type' => 'text', 'role' => 'date'];
    }
    return ['type' => 'text', 'role' => 'normal'];
}

function commit_import(PDO $pdo, int $projectId): void
{
    $body = read_json_body();
    $csvText = (string) ($body['csv'] ?? '');
    $headerRow = max(1, (int) ($body['header_row'] ?? 1));
    $delimiter = (string) ($body['delimiter'] ?? '');
    if (trim($csvText) === '') json_error('CSV kosong.');

    $lines = preg_split('/\r\n|\r|\n/', $csvText);
    if ($delimiter === '' || $delimiter === 'auto') {
        $delimiter = detect_delimiter($lines[$headerRow - 1] ?? $lines[0]);
    }

    $records = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $records[] = str_getcsv($line, $delimiter);
    }
    if (count($records) < $headerRow) json_error('Baris header melebihi jumlah baris file.');

    $headers = array_map(fn($h) => trim((string) $h), $records[$headerRow - 1]);
    $dataLines = array_slice($records, $headerRow);

    $pdo->beginTransaction();

    $columns = fetch_columns($pdo, $projectId);
    $byLabel = [];
    foreach ($columns as $c) {
        $byLabel[mb_strtolower(trim($c['label']))] = $c['col_key'];
    }
    $existingKeys = array_column($columns, 'col_key');

    $maxOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(order_index), -1) FROM mop_columns WHERE project_id = ?");
    $maxOrderStmt->execute([$projectId]);
    $nextOrder = ((int) $maxOrderStmt->fetchColumn()) + 1;

    $headerToKey = [];
    $createdColumns = [];
    foreach ($headers as $h) {
        if ($h === '') { $headerToKey[] = null; continue; }
        $lower = mb_strtolower($h);
        if (isset($byLabel[$lower])) {
            $headerToKey[] = $byLabel[$lower];
            continue;
        }
        $shape = guess_column_shape($h);
        $key = slugify($h, $existingKeys);
        $existingKeys[] = $key;
        $pdo->prepare("
            INSERT INTO mop_columns (project_id, col_key, label, type, role, options, order_index, is_primary, required, width)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$projectId, $key, $h, $shape['type'], $shape['role'], '[]', $nextOrder, 0, 0, null]);
        $nextOrder++;
        $byLabel[$lower] = $key;
        $headerToKey[] = $key;
        $createdColumns[] = $h;
    }

    $maxRowStmt = $pdo->prepare("SELECT COALESCE(MAX(row_no), 0) FROM mop_rows WHERE project_id = ?");
    $maxRowStmt->execute([$projectId]);
    $rowNo = (int) $maxRowStmt->fetchColumn();

    $insert = $pdo->prepare("INSERT INTO mop_rows (project_id, row_no, data) VALUES (?,?,?)");
    $imported = 0;
    foreach ($dataLines as $line) {
        $isEmpty = true;
        foreach ($line as $v) { if (trim((string) $v) !== '') { $isEmpty = false; break; } }
        if ($isEmpty) continue;

        $data = [];
        foreach ($headerToKey as $i => $key) {
            if ($key === null) continue;
            $data[$key] = $line[$i] ?? '';
        }
        $rowNo++;
        $insert->execute([$projectId, $rowNo, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $imported++;
    }

    $pdo->prepare("UPDATE mop_projects SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$projectId]);
    $pdo->commit();

    json_response(['ok' => true, 'imported' => $imported, 'created_columns' => $createdColumns]);
}

/* ---------------- Export ---------------- */

function export_csv(PDO $pdo, int $projectId): void
{
    $stmt = $pdo->prepare("SELECT name FROM mop_projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) json_error('Project tidak ditemukan.', 404);

    $columns = fetch_columns($pdo, $projectId);
    $rowStmt = $pdo->prepare("SELECT data FROM mop_rows WHERE project_id = ? ORDER BY row_no ASC, id ASC");
    $rowStmt->execute([$projectId]);
    $rows = $rowStmt->fetchAll();

    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $project['name']) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, array_map(fn($c) => $c['label'], $columns), ';');
    foreach ($rows as $r) {
        $data = json_decode($r['data'], true) ?: [];
        fputcsv($out, array_map(fn($c) => $data[$c['col_key']] ?? '', $columns), ';');
    }
    fclose($out);
    exit;
}

/* ---------------- Dashboard ---------------- */

function dashboard(PDO $pdo, int $projectId): void
{
    $columns = fetch_columns($pdo, $projectId);
    $rowStmt = $pdo->prepare("SELECT data FROM mop_rows WHERE project_id = ?");
    $rowStmt->execute([$projectId]);
    $rows = array_map(fn($r) => json_decode($r['data'], true) ?: [], $rowStmt->fetchAll());

    $total = count($rows);
    $statusBreakdown = [];
    $groupBreakdown = [];
    $timeline = [];
    $primaryStatusCol = null;

    foreach ($columns as $c) {
        if ($c['role'] === 'status') {
            $counts = [];
            $colorMap = [];
            foreach ($rows as $r) {
                $val = trim((string) ($r[$c['col_key']] ?? ''));
                $cls = classify_status($val);
                $bucket = $cls['bucket'];
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
                $colorMap[$bucket] = $cls['color'];
            }
            arsort($counts);
            $statusBreakdown[] = [
                'key' => $c['col_key'],
                'label' => $c['label'],
                'is_primary' => $c['is_primary'],
                'counts' => $counts,
                'colors' => $colorMap,
            ];
            if ($c['is_primary']) $primaryStatusCol = $c;
        }

        if ($c['role'] === 'group') {
            $counts = [];
            foreach ($rows as $r) {
                $val = trim((string) ($r[$c['col_key']] ?? ''));
                if ($val === '') $val = '(Kosong)';
                $counts[$val] = ($counts[$val] ?? 0) + 1;
            }
            arsort($counts);
            $groupBreakdown[] = [
                'key' => $c['col_key'],
                'label' => $c['label'],
                'counts' => array_slice($counts, 0, 15, true),
            ];
        }

        if ($c['role'] === 'date') {
            $counts = [];
            foreach ($rows as $r) {
                $label = extract_date_label((string) ($r[$c['col_key']] ?? ''));
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
            uksort($counts, function ($a, $b) {
                $ta = strtotime(preg_replace('/^\w+,\s*/', '', $a));
                $tb = strtotime(preg_replace('/^\w+,\s*/', '', $b));
                if ($ta === false && $tb === false) return strcmp($a, $b);
                if ($ta === false) return 1;
                if ($tb === false) return -1;
                return $ta <=> $tb;
            });
            $timeline[] = [
                'key' => $c['col_key'],
                'label' => $c['label'],
                'counts' => $counts,
            ];
        }
    }

    if (!$primaryStatusCol) {
        foreach ($columns as $c) {
            if ($c['role'] === 'status') { $primaryStatusCol = $c; break; }
        }
    }

    $completion = null;
    if ($primaryStatusCol) {
        $done = 0;
        foreach ($rows as $r) {
            $val = strtolower(trim((string) ($r[$primaryStatusCol['col_key']] ?? '')));
            if ($val === 'done' || $val === 'ready' || str_contains($val, 'selesai')) $done++;
        }
        $completion = [
            'key' => $primaryStatusCol['col_key'],
            'label' => $primaryStatusCol['label'],
            'done' => $done,
            'total' => $total,
            'pct' => $total > 0 ? round($done / $total * 100, 1) : 0,
        ];
    }

    json_response([
        'total' => $total,
        'completion' => $completion,
        'status_breakdown' => $statusBreakdown,
        'group_breakdown' => $groupBreakdown,
        'timeline' => $timeline,
    ]);
}
