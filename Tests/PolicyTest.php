<?php

namespace Omnilab\Ncbi\Tests;

use Omnilab\Ncbi\NcbiSourceFactory;
use Omnilab\Source\HttpSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** NCBI's two APIs as its policy: E-utilities 3 a second, Datasets 5; 10 each with a key. */
final class PolicyTest extends TestCase
{
    public function testEachApiHasItsFigureAndAKeyRaisesBoth(): void
    {
        $keyless = (new NcbiSourceFactory(new MockHttpClient()))->create();
        \assert($keyless instanceof HttpSource);
        self::assertSame('ncbi', $keyless->guard()->policy()->provider);
        self::assertSame(3, $keyless->guard()->remaining('eutils'));
        self::assertSame(5, $keyless->guard()->remaining('datasets'));

        $keyed = (new NcbiSourceFactory(new MockHttpClient()))->create(['api_key' => 'test-key']);
        \assert($keyed instanceof HttpSource);
        self::assertSame(10, $keyed->guard()->remaining('eutils'));
        self::assertSame(10, $keyed->guard()->remaining('datasets'));
    }
}
