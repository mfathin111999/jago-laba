<?php

namespace Tests\Unit;

use App\Http\Responses\CsvDownload;
use PHPUnit\Framework\TestCase;

final class CsvDownloadTest extends TestCase
{
    public function test_formula_triggers_are_neutralised_and_other_values_are_untouched(): void
    {
        foreach (['=SUM(A1)', '+1', '-1', '@cmd', "\tx", "\rx", "\nx"] as $dangerous) {
            $this->assertSame("'".$dangerous, CsvDownload::safe($dangerous));
        }

        $this->assertSame('Toko A', CsvDownload::safe('Toko A'));
        $this->assertSame('', CsvDownload::safe(''));
        $this->assertSame(-5.5, CsvDownload::safe(-5.5), 'numbers are not text and must stay numeric');
        $this->assertNull(CsvDownload::safe(null));
    }
}
