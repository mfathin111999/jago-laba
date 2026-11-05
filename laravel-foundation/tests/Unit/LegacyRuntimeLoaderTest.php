<?php

namespace Tests\Unit;

use App\Marketplace\LegacyRuntime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyRuntimeLoaderTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function unsafeNames(): array
    {
        return ['parent directory' => ['../../config/database'], 'nested path' => ['Sub/Evil'], 'empty' => [''], 'dots' => ['..'], 'null byte' => ["Name\0"]];
    }

    #[DataProvider('unsafeNames')]
    public function test_service_names_that_could_leave_the_services_folder_are_refused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        LegacyRuntime::service($name);
    }

    public function test_a_real_legacy_service_loads_once_and_defines_its_class(): void
    {
        LegacyRuntime::service('MarketplaceOrderPresentation');
        LegacyRuntime::service('MarketplaceOrderPresentation.php');

        $this->assertTrue(class_exists(\MarketplaceOrderPresentation::class, false));
    }
}
