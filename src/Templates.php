<?php
declare(strict_types=1);

/**
 * Built-in MOP column presets. "vm_migration" mirrors the columns from the
 * uploaded "Jadwal Migrasi VM" MOP format.
 */
class Templates
{
    public static function all(): array
    {
        return [
            'blank' => [
                'label' => 'Kosong (mulai dari nol)',
                'description' => 'Project tanpa kolom bawaan — tambahkan kolom sendiri.',
                'columns' => [
                    ['label' => 'No', 'type' => 'number', 'role' => 'normal'],
                    ['label' => 'Activity', 'type' => 'text', 'role' => 'group'],
                    ['label' => 'PIC', 'type' => 'text', 'role' => 'group'],
                    ['label' => 'Jadwal', 'type' => 'text', 'role' => 'date'],
                    ['label' => 'Status', 'type' => 'status', 'role' => 'status', 'is_primary' => true],
                ],
            ],
            'vm_migration' => [
                'label' => 'Migrasi VM (Jadwal & Cutover)',
                'description' => 'Format lengkap ala "Jadwal Migrasi VM": hostname, IP, site, cluster, tim, status replikasi & cutover.',
                'columns' => [
                    ['label' => 'No', 'type' => 'number', 'role' => 'normal', 'width' => 'sm'],
                    ['label' => 'VM Name', 'type' => 'text', 'role' => 'normal'],
                    ['label' => 'Hostname (DNS Name)', 'type' => 'text', 'role' => 'normal'],
                    ['label' => 'IP Address', 'type' => 'text', 'role' => 'normal'],
                    ['label' => 'Site', 'type' => 'text', 'role' => 'group', 'width' => 'sm'],
                    ['label' => 'Cluster', 'type' => 'text', 'role' => 'group'],
                    ['label' => 'Aplikasi / Activity', 'type' => 'text', 'role' => 'group'],
                    ['label' => 'PIC', 'type' => 'text', 'role' => 'group'],
                    ['label' => 'Jadwal Replikasi', 'type' => 'text', 'role' => 'normal'],
                    ['label' => 'Malam Cutover', 'type' => 'text', 'role' => 'date'],
                    ['label' => 'Jadwal Cutover (jam riil)', 'type' => 'text', 'role' => 'normal'],
                    ['label' => 'Tim Migrasi', 'type' => 'text', 'role' => 'group'],
                    ['label' => 'Status Replicate', 'type' => 'status', 'role' => 'status'],
                    ['label' => 'Status CutOver', 'type' => 'status', 'role' => 'status', 'is_primary' => true],
                    ['label' => 'Tanggal', 'type' => 'text', 'role' => 'normal'],
                    ['label' => 'Keterangan', 'type' => 'textarea', 'role' => 'normal'],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        $all = self::all();
        return $all[$key] ?? null;
    }
}
