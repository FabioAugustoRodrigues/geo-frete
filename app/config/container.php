<?php

use app\domain\geo\EstimateRoutingEngine;
use app\domain\geo\GeocoderInterface;
use app\domain\geo\HttpClient;
use app\domain\geo\NominatimGeocoder;
use app\domain\geo\OsrmRoutingEngine;
use app\domain\geo\RoutingEngineInterface;
use app\domain\geo\RoutingEngineWithFallback;
use app\domain\service\AddressResolverService;
use app\domain\service\DistanceCalculationService;
use app\domain\service\DistanceService;
use app\tests\Fake\GeocoderFake;
use app\tests\Fake\RoutingEngineFake;
use DI\ContainerBuilder;

use function DI\autowire;
use function DI\create;
use function DI\get;

$containerBuilder = new ContainerBuilder();

$definitions = [
    HttpClient::class => create(HttpClient::class)
        ->constructor(HTTP_TIMEOUT, APP_USER_AGENT, APP_CONTACT),

    NominatimGeocoder::class => autowire()
        ->constructorParameter('baseUrl', NOMINATIM_URL),

    OsrmRoutingEngine::class => autowire()
        ->constructorParameter('baseUrl', OSRM_URL)
        ->constructorParameter('timeoutSeconds', OSRM_TIMEOUT),

    AddressResolverService::class => autowire()
        ->constructorParameter('cacheMaxAgeDays', GEOCODING_CACHE_DAYS),

    DistanceCalculationService::class => autowire()
        ->constructorParameter('snapWarningKm', SNAP_WARNING_KM),

    DistanceService::class => create(DistanceService::class)
        ->constructor(ROUTE_FACTOR),
];

/*
|--------------------------------------------------------------------------
| External providers
|--------------------------------------------------------------------------
|
| Under APP_ENV=testing the real providers are swapped for fakes, so the
| test suite never depends on the network or third-party services. The
| fallback wrapper stays real in both cases, so tests exercise it too.
|
*/
if (APP_ENV === 'testing') {
    $definitions[GeocoderInterface::class] = autowire(GeocoderFake::class);
    $primaryRoutingEngine = get(RoutingEngineFake::class);
} else {
    $definitions[GeocoderInterface::class] = get(NominatimGeocoder::class);
    $primaryRoutingEngine = get(OsrmRoutingEngine::class);
}

$definitions[RoutingEngineInterface::class] = create(RoutingEngineWithFallback::class)
    ->constructor($primaryRoutingEngine, get(EstimateRoutingEngine::class));

$containerBuilder->addDefinitions($definitions);

return $containerBuilder->build();
