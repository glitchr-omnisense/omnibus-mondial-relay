<?php

namespace Omnibus\MondialRelay\Tests;

use Omnibus\Exception\UsageLimitReachedException;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use Omnibus\Registry;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\Scope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Mondial Relay's 4 to 6 tracking requests a day per shipment, declared as 6
 * a day per parcel and counted by the provider (the "trackParcel" request,
 * the tracking number named): the 7th of a parcel is refused before it is
 * sent, another parcel's goes through.
 */
final class ParcelTrackingLimitTest extends TestCase
{
    private int $sent = 0;

    protected function setUp(): void
    {
        if (!\defined(Scope::class.'::Resource')) {
            self::markTestSkipped('omnisense/contracts before 2f78f0e counts no limit per resource.');
        }
    }

    public function testTheSeventhTrackingOfAParcelInADayIsNeverSent(): void
    {
        $gateway = $this->factory()->create(['sandbox' => true]);
        for ($i = 0; $i < 6; ++$i) {
            $gateway->track('31231652');
        }
        try {
            $gateway->track('31231652');
            self::fail('A 7th tracking request of the parcel went out.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(6, $this->sent);
            self::assertSame('6/day/resource:parcel/trackParcel', $e->limit?->id());
            self::assertStringContainsString('6 a day for trackParcel per parcel', $e->getMessage());
        }
        $gateway->track('31231653');
        self::assertSame(7, $this->sent, 'another parcel is tracked');
    }

    public function testTheRegistrysGuardedGatewayAndASiteLimitCountTheRequestOnce(): void
    {
        $limiter = new InMemoryRateLimiter();
        $registry = new Registry([$this->factory()], ['mr' => ['factory' => 'mondial_relay', 'options' => ['sandbox' => true], 'policy' => ['rateLimits' => [['max' => 3, 'window' => 'day']]]]], $limiter);
        $gateway = $registry->guarded('mr');

        $gateway->track('31231652');
        $gateway->track('31231653');
        self::assertSame(1, $registry->guard('mr')->remaining('tracking'), 'a site\'s limit on every operation counts each tracking once');
        self::assertSame(5, $registry->guard('mr')->perResource()->remaining('trackParcel', ['resource' => '31231652']));
    }

    public function testThePolicyDeclaresTheLimitPerParcel(): void
    {
        $limits = MondialRelayGatewayFactory::usagePolicy()->rateLimits ?? [];

        self::assertCount(1, $limits);
        self::assertSame('6 a day for trackParcel per parcel', $limits[0]->describe());
    }

    private function factory(): MondialRelayGatewayFactory
    {
        return new MondialRelayGatewayFactory(new MockHttpClient(function (): MockResponse {
            ++$this->sent;

            return new MockResponse(Recorded::soap('WSI2_TracingColisDetaille', '<STAT>81</STAT><Tracing />'));
        }));
    }
}
