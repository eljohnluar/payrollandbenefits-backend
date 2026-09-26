<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Key/value configuration from system_settings, mirroring the defaults the
 * legacy MySQL app shipped with so statutory maths stays identical.
 */
final class SettingsService
{
    private const DEFAULTS = [
        'company_name'         => 'TRI-M GLOBAL LOGISTICS & TRADING INC.',
        'currency_symbol'      => "\u{20B1}",
        'currency_decimals'    => '2',
        'work_days_per_month'  => '22',
        'work_hours_per_day'   => '8',
        'philhealth_rate'      => '2',
        'sss_ee_fixed'         => '900',
        'sss_er_rate'          => '9.5',
        'sss_er_cap'           => '1900',
        'pagibig_ee_min'       => '50',
        'pagibig_ee_max'       => '100',
        'pagibig_er'           => '100',
        'tax_free_threshold'   => '22000',
        'payslip_lead_days'    => '3',
        'idle_timeout_minutes' => '5',
        'sil_days'             => '15',
        'sil_month'            => '4',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Database::pdo()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    self::$cache[$row['setting_key']] = (string) $row['setting_value'];
                }
            } catch (\Throwable) {
                // Table not migrated yet: defaults keep the API usable.
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function num(string $key, float $default): float
    {
        $value = (float) self::get($key, (string) $default);
        return $value > 0 ? $value : $default;
    }

    /** Upserts one or more system_settings and clears the in-request cache. */
    public static function update(array $pairs): array
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value'
        );
        foreach ($pairs as $key => $value) {
            $stmt->execute([(string) $key, (string) $value]);
        }
        self::$cache = null;
        return self::all();
    }
}
