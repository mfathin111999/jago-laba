<?php

namespace App\Services\Konveksi\Typed;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use InvalidArgumentException;
use Throwable;

/**
 * One typed column. Converts a record value to the value stored in the column (null when empty or when it does not
 * fit the type) and the stored value back to its canonical form. Anything that does not survive the round trip is
 * kept by the row mapper as an "original", so nothing is ever lost or silently changed.
 *
 * Specs: bigint, int, decimal:18,2, string:60, text, bool, date, datetime, json.
 */
final class TypedColumn
{
    private const SERIAL_EPOCH = '1899-12-30'; // Google Sheets / Excel day 0

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $length = 0,
        public readonly int $scale = 0,
    ) {}

    public static function parse(string $name, string $spec): self
    {
        [$type, $arg] = array_pad(explode(':', $spec, 2), 2, null);
        if (! in_array($type, ['bigint', 'int', 'decimal', 'string', 'text', 'bool', 'date', 'datetime', 'json'], true)) {
            throw new InvalidArgumentException("Tipe kolom tidak dikenal: {$spec}");
        }
        if ($type === 'decimal') {
            [$precision, $scale] = array_map('intval', array_pad(explode(',', (string) $arg), 2, 0));

            return new self($name, $type, $precision, $scale);
        }

        return new self($name, $type, (int) $arg);
    }

    public function define(Blueprint $table): ColumnDefinition
    {
        return match ($this->type) {
            'bigint' => $table->bigInteger($this->name),
            'int' => $table->integer($this->name),
            'decimal' => $table->decimal($this->name, $this->length, $this->scale),
            'string' => $table->string($this->name, $this->length ?: 190),
            'text' => $table->longText($this->name),
            'bool' => $table->boolean($this->name),
            'date' => $table->date($this->name),
            'datetime' => $table->dateTime($this->name),
            'json' => $table->longText($this->name),
        };
    }

    /** The value to store, or null for an empty value or one that does not fit. */
    public function toColumn(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'bigint', 'int' => $this->integer($value),
            'decimal' => $this->decimal($value),
            'string' => is_scalar($value) && ! is_bool($value) && mb_strlen((string) $value) <= ($this->length ?: 190) ? (string) $value : null,
            'text' => is_scalar($value) && ! is_bool($value) ? (string) $value : null,
            'bool' => is_bool($value) ? $value : null,
            'date' => $this->date($value),
            'datetime' => $this->dateTime($value),
            'json' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        };
    }

    /** The canonical record value for a stored value (empty string for NULL). */
    public function fromColumn(mixed $stored): mixed
    {
        if ($stored === null) {
            return '';
        }

        return match ($this->type) {
            'bigint', 'int' => (int) $stored,
            'decimal' => $this->number((float) $stored),
            'string', 'text' => (string) $stored,
            'bool' => (bool) $stored,
            'date' => substr((string) $stored, 0, 10),
            'datetime' => substr((string) $stored, 0, 19),
            'json' => json_decode((string) $stored, true),
        };
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 9.0e15) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 && strlen(ltrim(trim($value), '-')) < 19) {
            return (int) $value;
        }

        return null;
    }

    private function decimal(mixed $value): ?string
    {
        if (! (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value))))) {
            return null;
        }
        $number = round((float) $value, $this->scale);
        if (abs($number) >= 10 ** ($this->length - $this->scale)) {
            return null;
        }

        return number_format($number, $this->scale, '.', '');
    }

    private function number(float $value): int|float
    {
        return floor($value) === $value && abs($value) < 9.0e15 ? (int) $value : $value;
    }

    private function date(mixed $value): ?string
    {
        $dateTime = $this->toCarbon($value);

        return $dateTime?->format('Y-m-d');
    }

    private function dateTime(mixed $value): ?string
    {
        return $this->toCarbon($value)?->format('Y-m-d H:i:s');
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        try {
            if (is_int($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)) === 1)) {
                $serial = (float) $value;
                if ($serial < 20000 || $serial > 80000) {
                    return null;
                }

                return Carbon::parse(self::SERIAL_EPOCH, config('app.timezone'))->addSeconds((int) round($serial * 86400));
            }
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', trim($value)) === 1) {
                $hasZone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value)) === 1;
                $parsed = Carbon::parse(trim($value), $hasZone ? null : config('app.timezone'));

                return $hasZone ? $parsed->setTimezone(config('app.timezone')) : $parsed;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
