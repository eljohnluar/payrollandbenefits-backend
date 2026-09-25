<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Soft-delete archive: deleting a main record snapshots it into archive_records
 * and flags the row, so history/foreign keys survive and everything is restorable.
 */
final class ArchiveService
{
    private const ENTITIES = [
        'employee'            => ['table' => 'employees',           'pk' => 'id',    'name' => 'Employee'],
        'claim'               => ['table' => 'claims',              'pk' => 'id',    'name' => 'Claim'],
        'benefit_enrollment'  => ['table' => 'benefit_enrollments', 'pk' => 'id',    'name' => 'Benefit Enrollment'],
        'payslip'             => ['table' => 'payroll_items',       'pk' => 'id',    'name' => 'Payslip Line'],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function archive(string $entityType, string $key, int $userId): array
    {
        $meta = self::ENTITIES[$entityType] ?? null;
        if ($meta === null) {
            throw new RuntimeException("Unknown archive entity '{$entityType}'.", 422);
        }
        $stmt = $this->pdo->prepare("SELECT * FROM {$meta['table']} WHERE {$meta['pk']} = ? AND is_archived = FALSE");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        if ($row === false) {
            throw new RuntimeException("That {$meta['name']} was not found or is already archived.", 404);
        }

        $label = self::label($entityType, $row);
        $this->pdo->prepare(
            'INSERT INTO archive_records (entity_type, entity_key, label, snapshot, archived_by)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (entity_type, entity_key) DO UPDATE
               SET snapshot = EXCLUDED.snapshot, label = EXCLUDED.label,
                   archived_by = EXCLUDED.archived_by, archived_at = now(), restored_at = NULL'
        )->execute([$entityType, $key, $label, json_encode($row, JSON_UNESCAPED_UNICODE), $userId]);

        $this->pdo->prepare("UPDATE {$meta['table']} SET is_archived = TRUE WHERE {$meta['pk']} = ?")->execute([$key]);
        AuditService::log("{$meta['name']} Archived", 'Archive', $userId, ['entity_key' => $key, 'label' => $label]);
        return ['archived' => $entityType, 'key' => $key, 'label' => $label];
    }

    public function list(): array
    {
        return $this->pdo->query(
            'SELECT a.id, a.entity_type, a.entity_key, a.label, a.archived_at, a.restored_at,
                    u.name AS archived_by_name
               FROM archive_records a
               LEFT JOIN app_users u ON u.id = a.archived_by
              ORDER BY a.archived_at DESC LIMIT 500'
        )->fetchAll();
    }

    public function restore(int $archiveId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM archive_records WHERE id = ?');
        $stmt->execute([$archiveId]);
        $entry = $stmt->fetch();
        $stmt->closeCursor();
        if ($entry === false) {
            throw new RuntimeException('Archive entry not found.', 404);
        }
        if ($entry['restored_at'] !== null) {
            throw new RuntimeException('That record has already been restored.', 422);
        }
        $meta = self::ENTITIES[$entry['entity_type']] ?? null;
        if ($meta === null) {
            throw new RuntimeException('Unknown archive entity type.', 422);
        }
        $check = $this->pdo->prepare("SELECT is_archived FROM {$meta['table']} WHERE {$meta['pk']} = ?");
        $check->execute([$entry['entity_key']]);
        if ($check->fetchColumn() === false) {
            throw new RuntimeException('The original record no longer exists in this table.', 404);
        }
        $this->pdo->prepare("UPDATE {$meta['table']} SET is_archived = FALSE WHERE {$meta['pk']} = ?")->execute([$entry['entity_key']]);
        $this->pdo->prepare('UPDATE archive_records SET restored_at = now() WHERE id = ?')->execute([$archiveId]);
        AuditService::log("{$meta['name']} Restored", 'Archive', $userId, ['entity_key' => $entry['entity_key'], 'label' => $entry['label']]);
        return ['restored' => $entry['entity_type'], 'key' => $entry['entity_key']];
    }

    private static function label(string $entityType, array $row): string
    {
        return match ($entityType) {
            'employee' => trim($row['first_name'] . ' ' . $row['last_name']) . ' (' . $row['id'] . ')',
            'claim' => $row['claim_number'] . ' — ' . $row['employee_name'],
            'benefit_enrollment' => $row['employee_name'] . ' — ' . $row['plan_name'],
            'payslip' => $row['employee_name'] . ' (line ' . $row['id'] . ')',
            default => (string) ($row['id'] ?? ''),
        };
    }
}
