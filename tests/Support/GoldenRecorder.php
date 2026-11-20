<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

/**
 * Records API responses and compares them with versioned JSON references.
 *
 * Only what legitimately changes between runs is masked: row ids (numbered by first appearance),
 * the random run suffix, timestamps and the current month. Types, key order, number formats and
 * the timestamp layout stay exact, so an engine change that alters any of them fails the comparison.
 */
final class GoldenRecorder
{
    private array $sections = [];
    private string $current = '';
    private array $ids = [];

    public function __construct(private string $suffix) {}

    public function section(string $name): void
    {
        $this->current = $name;
        $this->sections[$name] ??= [];
    }

    /** @param array{0: int, 1: mixed, 2?: string, 3?: string} $result */
    public function add(string $step, array $result): void
    {
        $this->sections[$this->current][] = [
            'step' => $step,
            'status' => $result[0],
            'cacheControl' => $result[3] ?? '',
            'body' => is_string($result[1]) ? $this->csv($result[1]) : $this->normalize($result[1], ''),
        ];
    }

    public function assertMatches(string $directory, bool $update): void
    {
        foreach ($this->sections as $name => $steps) {
            $json = json_encode($steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $file = "{$directory}/{$name}.json";
            if ($update) {
                is_dir($directory) || mkdir($directory, 0775, true);
                file_put_contents($file, $json);
                continue;
            }
            Assert::assertFileExists($file, 'Missing reference; record it with UPDATE_GOLDEN=1 on the known-good engine.');
            Assert::assertSame((string) file_get_contents($file), $json, "Response drift in section '{$name}'");
        }
    }

    private function normalize(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            $masked = [];
            foreach ($value as $name => $item) {
                $masked[$name] = $this->normalize($item, is_string($name) ? $name : $key);
            }
            return $masked;
        }
        if ($key === 'nextCursor' && is_string($value)) {
            [$date, $id] = explode('~', $value, 2);
            return $date . '~' . $this->id($id);
        }
        if ($key === 'createdOn' && is_string($value)) {
            // The creation day is "today"; keep only its layout so a changed format still fails.
            return (string) preg_replace('/\d/', '9', $value);
        }
        if ((is_string($value) && ctype_digit($value) || is_int($value)) && preg_match('/^(id|[a-z]+Id)$/', $key)) {
            return $this->id((string) $value);
        }
        return is_string($value) ? $this->text($value) : $value;
    }

    private function text(string $value): string
    {
        $value = str_replace($this->suffix, '<suffix>', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value)) {
            // Keep the layout (separator, offset notation), drop the instant and the fraction.
            return (string) preg_replace('/\d/', '9', (string) preg_replace('/\.\d+/', '', $value));
        }
        $month = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m');
        return $value === $month ? '<current-month>' : $value;
    }

    private function id(string $value): string
    {
        return $this->ids[$value] ??= '<id:' . (count($this->ids) + 1) . '>';
    }

    private function csv(string $content): array
    {
        $rows = [];
        foreach (explode("\n", rtrim($content, "\n")) as $line) {
            $rows[] = array_map(fn (?string $cell): string => $this->ids[$cell ?? ''] ?? $this->text($cell ?? ''), str_getcsv($line, escape: ''));
        }
        return $rows;
    }
}
