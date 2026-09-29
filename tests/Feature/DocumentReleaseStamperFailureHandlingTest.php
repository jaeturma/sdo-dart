<?php

namespace Tests\Feature;

use App\Services\DocumentReleaseStamper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * See DocumentSignerFailureHandlingTest — identical guard, duplicated per
 * class. Tested the same way and for the same reason: this vendor's
 * tcpdf_config.php sets K_TCPDF_THROW_EXCEPTION_ERROR to false, so TCPDF's
 * own Error() calls die() instead of throwing, which would abort the whole
 * PHPUnit process if triggered from a test — so the guard is exercised
 * directly via reflection instead of through a full stamp() failure.
 */
class DocumentReleaseStamperFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_runtime_exception_when_source_file_implies_more_space_needed_than_is_free(): void
    {
        $hugeSourceFile = tempnam(sys_get_temp_dir(), 'drs_huge_source_');
        $fp = fopen($hugeSourceFile, 'w');
        $ok = ftruncate($fp, 2 * 1024 ** 4);
        fclose($fp);

        if (! $ok) {
            @unlink($hugeSourceFile);
            $this->markTestSkipped('This filesystem does not support sparse-extending a file via ftruncate().');
        }

        $method = new ReflectionMethod(DocumentReleaseStamper::class, 'ensureSufficientDiskSpace');
        $method->setAccessible(true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('low on disk space');

            $method->invoke(new DocumentReleaseStamper, sys_get_temp_dir(), $hugeSourceFile);
        } finally {
            @unlink($hugeSourceFile);
        }
    }

    public function test_does_not_block_releasing_when_disk_free_space_is_undeterminable(): void
    {
        $method = new ReflectionMethod(DocumentReleaseStamper::class, 'ensureSufficientDiskSpace');
        $method->setAccessible(true);

        $smallSourceFile = tempnam(sys_get_temp_dir(), 'drs_small_source_');

        try {
            $method->invoke(new DocumentReleaseStamper, sys_get_temp_dir().'/does-not-exist-'.uniqid(), $smallSourceFile);
            $this->assertTrue(true);
        } finally {
            @unlink($smallSourceFile);
        }
    }
}
