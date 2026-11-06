<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Columns the database computes itself (STORED GENERATED) reject explicit values on MySQL/MariaDB, but SQLite test
 * tables would accept them and hide the bug. Application code must therefore never write them.
 */
final class GeneratedColumnsTest extends TestCase
{
    /** @return list<string> */
    private function generated(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2).'/database/schema/marketplace.sql');
        preg_match_all('/^\s*`(\w+)`[^\n]*\bGENERATED ALWAYS AS\b/mi', $sql, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function test_the_schema_has_generated_columns_we_know_about(): void
    {
        $this->assertContains('net_margin_profit', $this->generated());
    }

    public function test_application_code_never_writes_a_generated_column(): void
    {
        $writes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            foreach ($this->generated() as $column) {
                // The marketing dashboard only returns a computed ROAS in its response; it is not stored.
                if ($column === 'roas' && str_ends_with(str_replace('\\', '/', $file->getPathname()), 'Marketing/MarketingDashboard.php')) {
                    continue;
                }
                // `column=?` inside SQL, or a `'column' => value` entry of an insert/update array.
                if (preg_match('/\b'.$column.'\s*=\s*\?|[\'"]'.$column.'[\'"]\s*=>/', $code) === 1) {
                    $writes[] = $file->getPathname().' writes '.$column;
                }
            }
        }

        $this->assertSame([], $writes);
    }
}
