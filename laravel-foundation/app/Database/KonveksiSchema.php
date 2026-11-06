<?php

namespace App\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The schema of the Konveksi database, as code (used by the migrations, `konveksi:schema-check` and the tests).
 *
 * Adoption rules: a table that already exists is never altered or dropped, so running the migrations on a database
 * the legacy installer built changes nothing. New collection tables carry only the fixed columns and the JSON
 * document; the per-field text columns the legacy importer mirrored from the spreadsheet headers are not created
 * (the application reads and writes only `_data_json`, `_row_order` and `_row_id`).
 */
final class KonveksiSchema
{
    /** Fixed columns of every `jl_data_*` collection table. */
    public const COLLECTION_COLUMNS = ['_row_id', '_record_key', '_row_order', '_data_json', '_created_at', '_updated_at'];

    /** Support tables and the columns each must have. */
    public const SUPPORT_COLUMNS = [
        'system_settings' => ['setting_key', 'setting_value', 'updated_at'],
        'system_table_map' => ['id', 'sheet_name', 'api_key', 'table_name', 'headers_json', 'source_row_count', 'imported_row_count', 'checksum', 'updated_at'],
        'system_audit_logs' => ['id', 'event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'],
        'backup_log' => ['id', 'backup_id', 'created_at', 'mode', 'status', 'sql_file', 'snapshot_file', 'manifest_file', 'total_rows', 'size_bytes', 'checksum', 'message'],
        'document_files' => ['id', 'file_id', 'document_type', 'reference_id', 'file_name', 'relative_path', 'mime_type', 'size_bytes', 'created_at'],
        'idempotency_keys' => ['id', 'company_key', 'idempotency_key', 'module', 'reference_id', 'response_json', 'created_at'],
        'konveksi_request_receipts' => ['request_key', 'company_key', 'request_hash', 'response_json', 'created_at'],
    ];

    /**
     * @return array{collections: list<array{0: string, 1: string}>, unmapped_tables: list<string>}
     */
    public static function registry(): array
    {
        return require database_path('schema/konveksi.php');
    }

    /**
     * Collection table => [sheet name, key] for every mapped collection.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mappedCollections(): array
    {
        $tables = [];
        foreach (self::registry()['collections'] as [$sheet, $key]) {
            $tables['jl_data_'.$key] = [$sheet, $key];
        }

        return $tables;
    }

    /** @return list<string> every jl_data_* table a complete installation has */
    public static function collectionTables(): array
    {
        return array_merge(array_keys(self::mappedCollections()), self::registry()['unmapped_tables']);
    }

    /**
     * @return list<string> names of the tables that were created (existing ones are left alone)
     */
    public function createSupportTables(Builder $schema): array
    {
        $created = [];
        foreach ($this->supportDefinitions() as $table => $definition) {
            if ($schema->hasTable($table)) {
                continue;
            }
            $schema->create($table, function (Blueprint $blueprint) use ($definition): void {
                $this->engine($blueprint);
                $definition($blueprint);
            });
            $created[] = $table;
        }

        return $created;
    }

    /**
     * @return list<string> names of the tables that were created (existing ones are left alone)
     */
    public function createCollectionTables(Builder $schema): array
    {
        $created = [];
        foreach (self::collectionTables() as $table) {
            if ($schema->hasTable($table)) {
                continue;
            }
            $schema->create($table, function (Blueprint $blueprint): void {
                $this->engine($blueprint);
                $blueprint->id('_row_id');
                $blueprint->string('_record_key', 190)->nullable();
                $blueprint->integer('_row_order')->default(0);
                $blueprint->longText('_data_json');
                $blueprint->dateTime('_created_at')->useCurrent();
                $blueprint->dateTime('_updated_at')->useCurrent()->useCurrentOnUpdate();
                $blueprint->index('_record_key');
                $blueprint->index('_row_order');
            });
            $created[] = $table;
        }

        return $created;
    }

    /** Adds the sheet -> key -> table rows the application looks collections up by; existing rows are kept. */
    public function seedTableMap(ConnectionInterface $connection): int
    {
        $now = now();
        $rows = [];
        foreach (self::mappedCollections() as $table => [$sheet, $key]) {
            $rows[] = ['sheet_name' => $sheet, 'api_key' => $key, 'table_name' => $table, 'headers_json' => null, 'source_row_count' => 0, 'imported_row_count' => 0, 'checksum' => null, 'updated_at' => $now];
        }

        return $connection->table('system_table_map')->insertOrIgnore($rows);
    }

    /** @return list<string> */
    public function dropAll(Builder $schema): array
    {
        $dropped = [];
        foreach (array_merge(array_reverse(self::collectionTables()), array_reverse(array_keys(self::SUPPORT_COLUMNS))) as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
                $dropped[] = $table;
            }
        }

        return $dropped;
    }

    /** MySQL/MariaDB: the same engine and character set the legacy installer used. Ignored by other drivers. */
    private function engine(Blueprint $blueprint): void
    {
        $blueprint->engine = 'InnoDB';
        $blueprint->charset = 'utf8mb4';
        $blueprint->collation = 'utf8mb4_unicode_ci';
    }

    /** @return array<string, Closure(Blueprint): void> */
    private function supportDefinitions(): array
    {
        return [
            'system_settings' => function (Blueprint $t): void {
                $t->string('setting_key', 190)->primary();
                $t->longText('setting_value')->nullable();
                $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            },
            'system_table_map' => function (Blueprint $t): void {
                $t->id();
                $t->string('sheet_name', 190)->unique('sheet_name');
                $t->string('api_key', 190)->unique('api_key');
                $t->string('table_name', 190)->unique('table_name');
                $t->longText('headers_json')->nullable();
                $t->integer('source_row_count')->default(0);
                $t->integer('imported_row_count')->default(0);
                $t->string('checksum', 128)->nullable();
                $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            },
            'system_audit_logs' => function (Blueprint $t): void {
                $t->id();
                $t->string('event_id', 100);
                $t->dateTime('event_time')->useCurrent();
                $t->string('user_id', 100)->nullable();
                $t->string('username', 120)->nullable();
                $t->string('module', 100)->nullable();
                $t->string('action', 120);
                $t->string('record_id', 190)->nullable();
                $t->string('reference_id', 190)->nullable();
                $t->longText('before_json')->nullable();
                $t->longText('after_json')->nullable();
                $t->string('status', 32)->default('SUCCESS');
                $t->text('notes')->nullable();
                $t->index('event_time', 'idx_audit_time');
                $t->index('action', 'idx_audit_action');
                $t->index('reference_id', 'idx_audit_ref');
            },
            'backup_log' => function (Blueprint $t): void {
                $t->id();
                $t->string('backup_id', 120)->unique('backup_id');
                $t->dateTime('created_at')->useCurrent();
                $t->string('mode', 40);
                $t->string('status', 32);
                $t->longText('sql_file')->nullable();
                $t->longText('snapshot_file')->nullable();
                $t->longText('manifest_file')->nullable();
                $t->integer('total_rows')->default(0);
                $t->bigInteger('size_bytes')->default(0);
                $t->string('checksum', 128)->nullable();
                $t->text('message')->nullable();
                $t->index('created_at', 'idx_backup_time');
                $t->index('mode', 'idx_backup_mode');
            },
            'document_files' => function (Blueprint $t): void {
                $t->id();
                $t->string('file_id', 120)->unique('file_id');
                $t->string('document_type', 120)->nullable();
                $t->string('reference_id', 190)->nullable();
                $t->string('file_name', 255);
                $t->longText('relative_path');
                $t->string('mime_type', 120)->nullable();
                $t->bigInteger('size_bytes')->default(0);
                $t->dateTime('created_at')->useCurrent();
                $t->index('reference_id', 'idx_doc_ref');
                $t->index('document_type', 'idx_doc_type');
            },
            'idempotency_keys' => function (Blueprint $t): void {
                $t->id();
                $t->string('company_key', 120)->nullable();
                $t->string('idempotency_key', 255);
                $t->string('module', 100)->nullable();
                $t->string('reference_id', 190)->nullable();
                $t->longText('response_json')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['company_key', 'module', 'idempotency_key'], 'uq_idempotency_scope');
            },
            'konveksi_request_receipts' => function (Blueprint $t): void {
                $t->string('request_key', 190)->primary();
                $t->string('company_key', 120);
                $t->char('request_hash', 64);
                $t->longText('response_json');
                $t->dateTime('created_at')->useCurrent();
            },
        ];
    }
}
