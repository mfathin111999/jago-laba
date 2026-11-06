<?php

namespace App\Services\Konveksi\Typed;

use Illuminate\Database\Schema\Blueprint;

/** The typed layout of one collection: table name, columns (in record order) and indexes. */
final class TypedDefinition
{
    /**
     * @param  array<string, TypedColumn>  $columns
     * @param  list<string>  $indexes
     */
    public function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly array $columns,
        public readonly array $indexes = [],
    ) {}

    /** @param array{table?: string, columns: array<string, string>, indexes?: list<string>} $spec */
    public static function fromArray(string $key, array $spec): self
    {
        $columns = [];
        foreach ($spec['columns'] as $name => $type) {
            $columns[$name] = TypedColumn::parse($name, $type);
        }

        return new self($key, $spec['table'] ?? 'kv_'.$key, $columns, $spec['indexes'] ?? []);
    }

    /** Builds the table: surrogate key, the typed columns (nullable), what did not fit, order and timestamps. */
    public function blueprint(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_unicode_ci';
        $table->id('_row_id');
        foreach ($this->columns as $column) {
            $column->define($table)->nullable();
        }
        $table->longText('_extra_json')->nullable();
        $table->integer('_row_order')->default(0);
        $table->dateTime('_created_at')->useCurrent();
        $table->dateTime('_updated_at')->useCurrent()->useCurrentOnUpdate();
        $table->index('_row_order');
        foreach ($this->indexes as $column) {
            $table->index($column, 'idx_'.substr($this->table, 0, 24).'_'.substr($column, 0, 24));
        }
    }

    /** @return list<string> every column of the table */
    public function tableColumns(): array
    {
        return array_merge(['_row_id'], array_keys($this->columns), ['_extra_json', '_row_order', '_created_at', '_updated_at']);
    }
}
