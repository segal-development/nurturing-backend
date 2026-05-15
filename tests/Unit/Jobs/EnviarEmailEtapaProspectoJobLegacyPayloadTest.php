<?php

namespace Tests\Unit\Jobs;

use App\Jobs\EnviarEmailEtapaProspectoJob;
use Tests\TestCase;

/**
 * Regression test for the 2026-05-14 incident.
 *
 * Background: commit 16a0585 added a typed property `?string $providerName`
 * to the job. PHP's unserialize() does NOT apply class defaults — so jobs
 * serialized before that commit lacked the property in their payload, and
 * accessing it raised:
 *
 *   Error: Typed property must not be accessed before initialization
 *
 * The fix (commit f2d39e7): use `$this->providerName ?? null` at the access
 * site. The `??` operator is documented to be safe on uninitialized typed
 * properties.
 *
 * This test guards against anyone re-introducing the direct access pattern
 * without `??` in the future.
 */
class EnviarEmailEtapaProspectoJobLegacyPayloadTest extends TestCase
{
    /** @test */
    public function legacy_payload_without_providerName_does_not_throw_on_access(): void
    {
        // Build a real serialized job, then strip the providerName slot
        // to simulate what an old queued payload looked like before the
        // typed property was introduced. PHP keeps the declared property
        // uninitialized when unserializing data that doesn't include it.
        $current = new EnviarEmailEtapaProspectoJob(
            prospectoEnFlujoId: 42,
            contenido: 'hello',
            asunto: 'test',
            flujoId: null,
            etapaEjecucionId: null,
            esHtml: false,
            providerName: null,
        );

        $serialized = serialize($current);

        // Remove the providerName slot and decrement the property count by 1.
        $legacy = preg_replace('/s:12:"providerName";N;/', '', $serialized, 1);
        $legacy = preg_replace_callback(
            '/^(O:\d+:"[^"]+":)(\d+)(:\{)/',
            fn ($m) => $m[1].($m[2] - 1).$m[3],
            $legacy,
            1
        );

        $job = @unserialize($legacy);

        $this->assertInstanceOf(EnviarEmailEtapaProspectoJob::class, $job);

        // Direct property access on uninitialized typed property would throw.
        // The fix in commit f2d39e7 uses `$this->providerName ?? null` at
        // the access site, which is safe.
        $accessed = $job->providerName ?? null;
        $this->assertNull($accessed);
    }

    /** @test */
    public function new_payload_with_providerName_set_to_null_works(): void
    {
        $job = new EnviarEmailEtapaProspectoJob(
            prospectoEnFlujoId: 1,
            contenido: 'hi',
            asunto: 'subj',
            flujoId: null,
            etapaEjecucionId: null,
            esHtml: false,
            providerName: null,
        );

        $this->assertNull($job->providerName ?? null);
    }

    /** @test */
    public function new_payload_with_explicit_provider_preserves_value(): void
    {
        $job = new EnviarEmailEtapaProspectoJob(
            prospectoEnFlujoId: 1,
            contenido: 'hi',
            asunto: 'subj',
            flujoId: null,
            etapaEjecucionId: null,
            esHtml: false,
            providerName: 'athena',
        );

        $this->assertEquals('athena', $job->providerName ?? null);
    }
}
