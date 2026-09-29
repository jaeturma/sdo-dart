<?php

namespace Tests\Feature;

use App\Services\DocumentSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers DocumentSigner::ensureSufficientDiskSpace() — the guard added
 * alongside the disk-full crash fix (see DocumentSigner::sign()'s
 * try/catch/finally). Deliberately does NOT attempt to exercise a full
 * sign() failure via TCPDF internals: this vendor's tcpdf_config.php sets
 * K_TCPDF_THROW_EXCEPTION_ERROR to false, so TCPDF's own Error() calls
 * die() instead of throwing — triggering that from a test would abort the
 * whole PHPUnit process, not just fail one test. The guard itself is a
 * small, pure function, so it's tested directly via reflection instead.
 */
class DocumentSignerFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_runtime_exception_when_source_file_implies_more_space_needed_than_is_free(): void
    {
        // A sparse file: ftruncate() to a huge logical size (2TB — well
        // within NTFS/ext4/APFS file-size limits, but larger than any real
        // test/CI machine's free disk space) without actually writing that
        // many bytes, so filesize() reports something no real machine's
        // free space could cover, without the test needing real disk or time.
        $hugeSourceFile = tempnam(sys_get_temp_dir(), 'ds_huge_source_');
        $fp = fopen($hugeSourceFile, 'w');
        $ok = ftruncate($fp, 2 * 1024 ** 4);
        fclose($fp);

        if (! $ok) {
            @unlink($hugeSourceFile);
            $this->markTestSkipped('This filesystem does not support sparse-extending a file via ftruncate().');
        }

        $method = new ReflectionMethod(DocumentSigner::class, 'ensureSufficientDiskSpace');
        $method->setAccessible(true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('low on disk space');

            $method->invoke(new DocumentSigner, sys_get_temp_dir(), $hugeSourceFile);
        } finally {
            @unlink($hugeSourceFile);
        }
    }

    public function test_does_not_block_signing_when_disk_free_space_is_undeterminable(): void
    {
        $method = new ReflectionMethod(DocumentSigner::class, 'ensureSufficientDiskSpace');
        $method->setAccessible(true);

        $smallSourceFile = tempnam(sys_get_temp_dir(), 'ds_small_source_');

        // A directory that doesn't exist: disk_free_space() returns false,
        // which must be treated as "can't tell" and allowed to proceed
        // rather than blocking a legitimate signing attempt.
        try {
            $method->invoke(new DocumentSigner, sys_get_temp_dir().'/does-not-exist-'.uniqid(), $smallSourceFile);
            $this->assertTrue(true);
        } finally {
            @unlink($smallSourceFile);
        }
    }
}
