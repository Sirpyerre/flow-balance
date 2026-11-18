<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use InvalidArgumentException;

final class OwnedResourceChanges
{
    public static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1
            && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0);
    }

    public function validate(string $type, array $data): array
    {
        $allowed = match ($type) {
            'clients' => ['name'],
            'projects' => ['name', 'clientId'],
            'payments' => ['clientPaidAmount'],
        };
        if (!$data || array_diff(array_keys($data), $allowed)) {
            throw new InvalidArgumentException('Revisa los campos indicados');
        }
        if (array_key_exists('name', $data)) {
            if (!is_string($data['name'])) {
                throw new InvalidArgumentException('Ingresa un nombre válido');
            }
            $name = trim($data['name']);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || str_contains($name, "\0")) {
                throw new InvalidArgumentException('Ingresa un nombre de 1 a 100 caracteres');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('clientId', $data) && !self::identifier($data['clientId'])) {
            throw new InvalidArgumentException('El cliente seleccionado no está disponible');
        }
        if (array_key_exists('clientPaidAmount', $data)) {
            try {
                $data['clientPaidAmount'] = (new ClientPaidAmount($data['clientPaidAmount']))->value;
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('Ingresa un importe positivo con hasta dos decimales');
            }
        }
        return $data;
    }
}
