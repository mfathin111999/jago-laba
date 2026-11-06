<?php

namespace App\Database;

/**
 * Looks at the values of every field in a list of records and describes them: which kinds occur, the longest text,
 * how many are filled, and a column type that fits all of them.
 */
final class KonveksiFieldProfiler
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed> field => {filled, kinds, max_length, samples, suggest}, plus '_rows'
     */
    public static function profile(array $records): array
    {
        $fields = [];
        foreach ($records as $record) {
            foreach ($record as $name => $value) {
                $f = &$fields[(string) $name];
                $f ??= ['filled' => 0, 'kinds' => [], 'max_length' => 0, 'samples' => [], 'decimals' => 0, 'int_max' => 0];
                $kind = self::kind($value);
                if ($kind === 'empty') {
                    $f['kinds']['empty'] = ($f['kinds']['empty'] ?? 0) + 1;
                    unset($f);

                    continue;
                }
                $f['filled']++;
                $f['kinds'][$kind] = ($f['kinds'][$kind] ?? 0) + 1;
                $text = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;
                $f['max_length'] = max($f['max_length'], mb_strlen($text));
                if (count($f['samples']) < 3 && ! in_array(mb_substr($text, 0, 40), $f['samples'], true)) {
                    $f['samples'][] = mb_substr($text, 0, 40);
                }
                if (in_array($kind, ['int', 'float', 'numeric-string'], true)) {
                    $f['int_max'] = max($f['int_max'], (int) min(abs((float) $value), PHP_INT_MAX));
                    $f['decimals'] = max($f['decimals'], self::decimals((string) $value));
                }
                unset($f);
            }
        }
        foreach ($fields as &$f) {
            $f['suggest'] = self::suggest($f);
            unset($f['decimals'], $f['int_max']);
        }
        unset($f);
        $fields['_rows'] = count($records);

        return $fields;
    }

    public static function kind(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => 'empty',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => 'date',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', $value) === 1 => 'datetime',
            is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value)) === 1 && ! (strlen(trim($value)) > 1 && trim($value)[0] === '0' && ctype_digit(trim($value))) => 'numeric-string',
            default => 'string',
        };
    }

    /** @param array<string, mixed> $f */
    private static function suggest(array $f): string
    {
        $kinds = array_keys(array_diff_key($f['kinds'], ['empty' => 1]));
        if ($kinds === []) {
            return '?';
        }
        if ($kinds === ['date']) {
            return 'DATE';
        }
        if ($kinds === ['datetime'] || $kinds === ['date', 'datetime']) {
            return 'DATETIME';
        }
        if (array_diff($kinds, ['int', 'float', 'numeric-string']) === []) {
            if ($f['decimals'] === 0 && $f['int_max'] < 2147483647) {
                return 'INT';
            }

            return $f['decimals'] === 0 ? 'BIGINT' : 'DECIMAL(18,'.min(4, max(2, $f['decimals'])).')';
        }
        if ($kinds === ['bool']) {
            return 'TINYINT(1)';
        }
        if ($kinds === ['json']) {
            return 'JSON';
        }
        if ($f['max_length'] <= 190) {
            return 'VARCHAR('.max(30, (int) (ceil($f['max_length'] / 10) * 10 * 2)).')';
        }

        return 'TEXT';
    }

    private static function decimals(string $value): int
    {
        $position = strpos($value, '.');

        return $position === false ? 0 : strlen(rtrim(substr($value, $position + 1), '0'));
    }
}
