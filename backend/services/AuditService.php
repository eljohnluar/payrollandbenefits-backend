<?php
declare(strict_types=1);

namespace App;

use PDO;

final class AuditService
{
    public static function log(string $action, string $module, ?int $userId, array $details = []): void
    {
        try {
            $actor = Auth::tryUser();
            if ($userId === null && $actor !== null) {
                $userId = (int) $actor['id'];
            }
            $userName = $actor['name'] ?? null;
            if ($userName === null && $userId !== null) {
                $stmtName = Database::pdo()->prepare('SELECT name FROM app_users WHERE id = ?');
                $stmtName->execute([$userId]);
                $userName = $stmtName->fetchColumn() ?: null;
            }
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_log (action, module, user_id, user_name, ip_address, details)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $action,
                $module,
                $userId,
                $userName,
                Http::clientIp(),
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the operation it is describing.
            error_log('[audit] failed: ' . $e->getMessage());
        }
    }

    /** Paginated, filterable view of the audit trail for the Audit Log page. */
    public static function list(array $filters): array
    {
        $sql = 'SELECT id, action, module, user_id, user_name, ip_address, details, created_at
                  FROM audit_log WHERE 1=1';
        $params = [];
        if (!empty($filters['module']))   { $sql .= ' AND module = ?';       $params[] = $filters['module']; }
        if (!empty($filters['user_id']))  { $sql .= ' AND user_id = ?';      $params[] = (int) $filters['user_id']; }
        if (!empty($filters['date_from'])) { $sql .= ' AND created_at >= ?';  $params[] = $filters['date_from'] . ' 00:00:00'; }
        if (!empty($filters['date_to']))   { $sql .= ' AND created_at <= ?';  $params[] = $filters['date_to'] . ' 23:59:59'; }
        if (!empty($filters['search'])) {
            $sql .= ' AND (action ILIKE ? OR details::text ILIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $countSql = preg_replace('/^SELECT .*? FROM/i', 'SELECT COUNT(*) FROM', $sql, 1);
        $limit = min(500, max(1, (int) ($filters['limit'] ?? 200)));
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return ['data' => $rows, 'total' => $total, 'modules' => self::modules()];
    }

    public static function modules(): array
    {
        try {
            return array_map(
                static fn ($m) => (string) $m,
                Database::pdo()->query('SELECT DISTINCT module FROM audit_log WHERE module IS NOT NULL ORDER BY module')->fetchAll(PDO::FETCH_COLUMN)
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
