<?php

declare(strict_types = 1);

namespace Benchmarks;

use Benchmarks\Support\IdentityNormalizer;
use PhpBench\Attributes as Bench;
use SineMacula\Foundation\Normalizers\Normalizer;

/**
 * Benchmarks for the normalizer hot paths.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited
 */
#[Bench\OutputTimeUnit('microseconds')]
final class NormalizerBench
{
    /**
     * Benchmark the whitespace scrub underpinning every normalizer.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchClean(): void
    {
        Normalizer::clean("  john   \t smith  ");
    }

    /**
     * Benchmark name normalization.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchName(): void
    {
        Normalizer::name('SMITH, JOHN MICHAEL');
    }

    /**
     * Benchmark email normalization.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchEmail(): void
    {
        Normalizer::email('John. Smith@Example.COM ');
    }

    /**
     * Benchmark phone normalization through libphonenumber.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(500)]
    #[Bench\Warmup(2)]
    public function benchPhone(): void
    {
        Normalizer::phone('(555) 123-4567', 'US');
    }

    /**
     * Benchmark address line normalization.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchAddressLine(): void
    {
        Normalizer::addressLine('123 MAIN STREET, APT 4B,');
    }

    /**
     * Benchmark country normalization through the country repository.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(500)]
    #[Bench\Warmup(2)]
    public function benchCountry(): void
    {
        Normalizer::country('United States');
    }

    /**
     * Benchmark date normalization through the strict format loop.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchDate(): void
    {
        Normalizer::date('January 1, 2024');
    }

    /**
     * Benchmark timezone normalization through the identifier scan.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchTimezone(): void
    {
        Normalizer::timezone('america/new_york');
    }

    /**
     * Benchmark postal code normalization.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchPostalCode(): void
    {
        Normalizer::postalCode(' sw1a 1aa ');
    }

    /**
     * Benchmark administrative area normalization through the subdivision
     * repository.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(500)]
    #[Bench\Warmup(2)]
    public function benchAdministrativeArea(): void
    {
        Normalizer::administrativeArea('california', 'US');
    }

    /**
     * Benchmark company name normalization.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchCompanyName(): void
    {
        Normalizer::companyName('acme widgets llc.');
    }

    /**
     * Benchmark job title normalization through the acronym and stop word
     * providers.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchJobTitle(): void
    {
        Normalizer::jobTitle('senior software engineer');
    }

    /**
     * Benchmark currency normalization through the intl currency list.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchCurrency(): void
    {
        Normalizer::currency('usd');
    }

    /**
     * Benchmark SSN normalization.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchSsn(): void
    {
        Normalizer::ssn('123-45-6789');
    }

    /**
     * Bench setUp — register the identity normalizer for the registry bench.
     *
     * @return void
     */
    public function setUpRegistry(): void
    {
        Normalizer::register('identity', IdentityNormalizer::class);
    }

    /**
     * Benchmark dispatch of a registered custom normalizer.
     *
     * @return void
     */
    #[Bench\BeforeMethods('setUpRegistry')]
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchRegisteredNormalizer(): void
    {
        Normalizer::__callStatic('identity', ['hello world']);
    }
}
