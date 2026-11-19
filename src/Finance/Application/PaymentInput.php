<?php
declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use DateTimeImmutable;
use DateTimeZone;
use FlowBalance\Shared\Clock\Clock;
use FlowBalance\Shared\Clock\SystemClock;

final class PaymentInput
{
    public static function date(mixed $value, ?Clock $clock = null): string
    {
        $zone = new DateTimeZone('America/Mexico_City');
        $date = is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone) : false;
        $today = (new DateTimeImmutable('@' . ($clock ?? new SystemClock())->now()))->setTimezone($zone)->format('Y-m-d');
        if (!$date || $date->format('Y-m-d') !== $value || $value < '0001-01-01' || $value > $today) {
            throw new IncomeValidation('paidOn', 'Indica una fecha de pago válida y no posterior a hoy.');
        }
        return $value;
    }

    public static function currency(mixed $currency, string $field = 'currency'): void
    {
        if ($currency !== 'MXN') {
            throw new IncomeValidation($field, 'Selecciona pesos mexicanos (MXN).');
        }
    }

    public static function text(mixed $value, string $field, int $limit): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || str_contains($value, "\0") || mb_strlen($value) > $limit) {
            throw new IncomeValidation($field, "Escribe entre 1 y {$limit} caracteres.");
        }
        return $value;
    }

    public static function source(array $body, bool $allowHistorical = false): array
    {
        $source = $body['sourceType'] ?? null;
        if ($source === null && $allowHistorical && array_key_exists('sourceType', $body)) {
            return [null, null];
        }
        if (!in_array($source, ['direct_client', 'platform', 'other'], true)) {
            throw new IncomeValidation('sourceType', 'Selecciona la fuente del cobro.');
        }
        return [$source, $source === 'direct_client' ? null : self::text($body['sourceName'] ?? null, 'sourceName', 100)];
    }

    public static function optionalText(mixed $value, string $field): ?string
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : self::text($value, $field, 250);
    }

    public static function key(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 255 || str_contains($key, "\0")) {
            throw new IncomeValidation('idempotencyKey', 'No se pudo identificar el guardado. Intenta de nuevo.');
        }
        return $key;
    }
}
