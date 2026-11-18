<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Domain;

final readonly class RegistrationInput
{
    public string $name;
    public string $email;
    public string $password;
    public array $errors;

    public function __construct(array $data)
    {
        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';
        $this->name = trim($string('name'));
        $this->email = trim($string('email'), " \t\n\r\v\f");
        $this->password = $string('password');
        $errors = [];
        if (!self::hasLength($this->name, 1, 100) || str_contains($this->name, "\0")) {
            $errors['name'] = 'Ingresa un nombre de 1 a 100 caracteres';
        }
        if (!self::validEmail($this->email)) {
            $errors['email'] = 'Ingresa un correo válido';
        }
        if (!self::hasLength($this->password, 12, 128)) {
            $errors['password'] = 'Usa una contraseña de 12 a 128 caracteres';
        }
        if ($string('passwordConfirmation') === '' || $this->password !== $string('passwordConfirmation')) {
            $errors['passwordConfirmation'] = 'Las contraseñas no coinciden';
        }
        $this->errors = $errors;
    }

    public static function validEmail(string $email): bool
    {
        return self::hasLength($email, 1, 254) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function hasLength(string $value, int $minimum, int $maximum): bool
    {
        return mb_check_encoding($value, 'UTF-8') &&
            mb_strlen($value, 'UTF-8') >= $minimum && mb_strlen($value, 'UTF-8') <= $maximum;
    }
}
