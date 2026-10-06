<?php
declare(strict_types=1);

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function slugify(string $text, array $existing = []): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'col';
    }
    if (preg_match('/^[0-9]/', $slug)) {
        $slug = 'c_' . $slug;
    }
    $base = $slug;
    $i = 2;
    while (in_array($slug, $existing, true)) {
        $slug = $base . '_' . $i;
        $i++;
    }
    return $slug;
}

/**
 * Heuristic classification for free-text status values so dashboards stay
 * useful even when real-world data doesn't stick to a strict enum
 * (e.g. "Failed connection refused by the node", "confirm dismantle by Tower").
 */
function classify_status(?string $value): array
{
    $v = trim((string) $value);
    $lower = strtolower($v);

    if ($v === '') {
        return ['bucket' => '(Kosong)', 'kind' => 'neutral', 'color' => '#94a3b8'];
    }
    if (str_contains($lower, 'not ready')) {
        return ['bucket' => $v, 'kind' => 'danger', 'color' => '#dc2626'];
    }
    if (str_contains($lower, 'fail') || str_contains($lower, 'refused') || str_contains($lower, 'error') || str_contains($lower, 'gagal')) {
        return ['bucket' => $v, 'kind' => 'danger', 'color' => '#b91c1c'];
    }
    if (str_starts_with($lower, 'ready')) {
        return ['bucket' => $v, 'kind' => 'success', 'color' => '#16a34a'];
    }
    if (str_contains($lower, 'done') || str_contains($lower, 'selesai')) {
        return ['bucket' => $v, 'kind' => 'info', 'color' => '#2563eb'];
    }
    if (str_contains($lower, 'confirm') || str_contains($lower, 'dismantle') || str_contains($lower, 'power off') || str_contains($lower, 'cancel') || str_contains($lower, 'pending')) {
        return ['bucket' => $v, 'kind' => 'warning', 'color' => '#d97706'];
    }
    return ['bucket' => $v, 'kind' => 'other', 'color' => '#7c3aed'];
}

/**
 * Pull a "dd Mon yyyy" style date out of a free-text field (schedule ranges,
 * "Kamis, 24 Sep 2026", etc.) so it can be used to build a timeline chart.
 */
function extract_date_label(?string $value): string
{
    $v = trim((string) $value);
    if ($v === '') {
        return '(Kosong)';
    }
    if (preg_match('/(\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})/u', $v, $m)) {
        return $m[1];
    }
    return $v;
}

function bool_from($v): int
{
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_numeric($v)) return ((int) $v) ? 1 : 0;
    if (is_string($v)) return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    return 0;
}

function detect_delimiter(string $sampleLine): string
{
    $semi = substr_count($sampleLine, ';');
    $comma = substr_count($sampleLine, ',');
    return $semi > $comma ? ';' : ',';
}
