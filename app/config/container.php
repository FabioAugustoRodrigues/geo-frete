<?php

use app\domain\geo\GeocoderInterface;
use app\domain\geo\HttpClient;
use app\domain\geo\NominatimGeocoder;
use app\domain\service\AddressResolverService;
use app\domain\service\DistanceService;
use app\tests\Fake\GeocoderFake;
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

    AddressResolverService::class => autowire()
        ->constructorParameter('cacheMaxAgeDays', GEOCODING_CACHE_DAYS),

    DistanceService::class => create(DistanceService::class)
        ->constructor(ROUTE_FACTOR),
];

/*
|--------------------------------------------------------------------------
| External providers
|--------------------------------------------------------------------------
|
| Under APP_ENV=testing the real providers are swapped for fakes, so the
| test suite never depends on the network or third-party services.
|
*/
if (APP_ENV === 'testing') {
    $definitions[GeocoderInterface::class] = autowire(GeocoderFake::class);
} else {
    $definitions[GeocoderInterface::class] = get(NominatimGeocoder::class);
}

$containerBuilder->addDefinitions($definitions);

return $containerBuilder->build();
