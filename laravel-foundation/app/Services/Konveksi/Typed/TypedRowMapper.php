<?php

namespace App\Services\Konveksi\Typed;

/**
 * Translates between a record (the associative array the client knows) and a typed row, without losing anything:
 *  - fields without a column go to _extra_json "x";
 *  - a field whose value changes on the round trip through its column (a number kept as text, a date written as a
 *    spreadsheet serial, null instead of '') keeps its original value in "o", and the column holds the typed value;
 *  - fields missing from the record are listed in "a" so they stay missing.
 * Reading a row therefore returns exactly the record that was written.
 */
final class TypedRowMapper
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(private readonly TypedDefinition $definition) {}

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed> column => value, ready to insert (without _row_id/_row_order)
     */
    public function toRow(array $record): array
    {
        // The reference behaviour is the JSON layout, which stores a record as JSON text: 10.0 comes back as 10.
        // Normalising the same way keeps both layouts returning identical records (and keeps _extra_json small).
        $record = (array) json_decode(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $row = [];
        $originals = [];
        $absent = [];
        foreach ($this->definition->columns as $name => $column) {
            if (! array_key_exists($name, $record)) {
                $row[$name] = null;
                $absent[] = $name;

                continue;
            }
            $value = $record[$name];
            $stored = $column->toColumn($value);
            $row[$name] = is_bool($stored) ? (int) $stored : $stored;
            if (! $this->same($column->fromColumn($stored), $value)) {
                $originals[$name] = $value;
            }
        }

        $extra = array_diff_key($record, $this->definition->columns);
        $meta = array_filter(['x' => $extra, 'o' => $originals, 'a' => $absent], static fn (array $part): bool => $part !== []);
        $row['_extra_json'] = $meta === [] ? null : json_encode($meta, self::FLAGS);

        return $row;
    }

    /**
     * @param  object|array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function toRecord(object|array $row): array
    {
        $row = (array) $row;
        $meta = isset($row['_extra_json']) && $row['_extra_json'] !== '' ? (array) json_decode((string) $row['_extra_json'], true) : [];
        $originals = (array) ($meta['o'] ?? []);
        $absent = array_flip((array) ($meta['a'] ?? []));

        $record = [];
        foreach ($this->definition->columns as $name => $column) {
            if (isset($absent[$name])) {
                continue;
            }
            $record[$name] = array_key_exists($name, $originals) ? $originals[$name] : $column->fromColumn($row[$name] ?? null);
        }

        return $record + (array) ($meta['x'] ?? []);
    }

    private function same(mixed $a, mixed $b): bool
    {
        return $a === $b || (is_array($a) && is_array($b) && json_encode($a) === json_encode($b));
    }
}
