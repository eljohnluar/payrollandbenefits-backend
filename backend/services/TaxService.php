<?php
declare(strict_types=1);

namespace App;

/**
 * Philippine statutory contributions and withholding tax.
 * Ported unchanged from the legacy app so a migrated payroll run reproduces
 * the same figures; every rate is read from system_settings.
 */
final class TaxService
{
    /** @return array{ee:float,er:float} */
    public static function sss(float $salary): array
    {
        $eeCap  = SettingsService::num('sss_ee_fixed', 900);
        $erRate = SettingsService::num('sss_er_rate', 9.5) / 100;
        $erCap  = SettingsService::num('sss_er_cap', 1900);
        if ($salary < 5000)  return ['ee' => $eeCap * 0.25, 'er' => $erCap * 0.25];
        if ($salary < 10000) return ['ee' => $eeCap * 0.5,  'er' => $erCap * 0.5];
        if ($salary < 15000) return ['ee' => $eeCap * 0.75, 'er' => $erCap * 0.75];
        if ($salary < 20000) return ['ee' => $eeCap,        'er' => $erCap];
        return ['ee' => $eeCap, 'er' => min($salary * $erRate, $erCap)];
    }

    /** @return array{ee:float,er:float} */
    public static function philHealth(float $salary): array
    {
        $ee = min($salary, 100000) * (SettingsService::num('philhealth_rate', 2) / 100);
        return ['ee' => $ee, 'er' => $ee];
    }

    /** @return array{ee:float,er:float} */
    public static function pagIbig(float $salary): array
    {
        $ee = $salary >= 5000
            ? SettingsService::num('pagibig_ee_max', 100)
            : SettingsService::num('pagibig_ee_min', 50);
        return ['ee' => $ee, 'er' => SettingsService::num('pagibig_er', 100)];
    }

    /** TIER-1 monthly withholding on the taxable portion of gross pay. */
    public static function withholdingTax(float $grossPay, float $totalEmployeeContributions): float
    {
        $taxableMonthly = $grossPay - $totalEmployeeContributions;
        // Mandatory threshold: taxable monthly income at or below ₱22,000 owes no withholding.
        if ($taxableMonthly <= SettingsService::num('tax_free_threshold', 22000)) {
            return 0.0;
        }
        $annual = $taxableMonthly * 12;
        if ($annual <= 250000)      $annualTax = 0;
        elseif ($annual <= 400000)  $annualTax = ($annual - 250000) * 0.15;
        elseif ($annual <= 800000)  $annualTax = 22500  + ($annual - 400000) * 0.20;
        elseif ($annual <= 2000000) $annualTax = 102500 + ($annual - 800000) * 0.25;
        elseif ($annual <= 8000000) $annualTax = 402500 + ($annual - 2000000) * 0.30;
        else                        $annualTax = 2202500 + ($annual - 8000000) * 0.35;
        return round($annualTax / 12, 2);
    }
}
