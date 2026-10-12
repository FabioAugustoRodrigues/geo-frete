#!/usr/bin/env php
<?php

/*
| Study: how wrong is "straight line x route factor" compared with the real
| road route? Draws random pairs of real house numbers from an imported
| city's CNEFE base, routes each pair on OSRM and prints a Markdown report.
|
|   docker compose exec api php bin/compare-distances.php SP Limeira [pairs=200] [seed=42]
*/

use app\domain\geo\AddressNormalizer;
use app\domain\geo\OsrmRoutingEngine;
use app\domain\exception\RoutingUnavailableException;
use app\domain\model\Coordinate;
use app\domain\repository\CnefeRepository;
use app\domain\service\DistanceService;
use app\domain\service\RouteFactorStudyService;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/config/config.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/compare-distances.php <UF> <municipality> [pairs=200] [seed=42]\n");
    exit(1);
}

$state = strtoupper($argv[1]);
$cityName = $argv[2];
$pairCount = max(1, (int) ($argv[3] ?? 200));
$seed = (int) ($argv[4] ?? 42);

$container = require __DIR__ . '/../app/config/container.php';
$repository = $container->get(CnefeRepository::class);
$normalizer = $container->get(AddressNormalizer::class);
// The OSRM engine itself, not the fallback wrapper: an estimate would
// silently be compared with itself.
$osrm = $container->get(OsrmRoutingEngine::class);
$distance = $container->get(DistanceService::class);

$city = $repository->findCity($state, $normalizer->normalizeText($cityName));
if ($city === null) {
    fwrite(STDERR, "{$cityName}/{$state} has no CNEFE import. Run bin/import-cnefe.php first.\n");
    exit(1);
}

$addresses = $repository->sampleAddresses($city['city_code'], $pairCount * 2, $seed);
$pairs = [];
$failed = 0;

foreach (array_chunk($addresses, 2) as $chunk) {
    if (count($chunk) < 2) {
        break;
    }

    [$a, $b] = $chunk;
    $from = new Coordinate($a['lat'], $a['lng']);
    $to = new Coordinate($b['lat'], $b['lng']);

    try {
        $route = $osrm->route($from, $to);
    } catch (RoutingUnavailableException $e) {
        $failed++;
        continue;
    }

    $pairs[] = [
        'from' => "{$a['street']}, {$a['number']}",
        'to' => "{$b['street']}, {$b['number']}",
        'straight_km' => $distance->straightLineKm($from, $to),
        'route_km' => $route->distanceKm,
    ];
}

if (empty($pairs)) {
    fwrite(STDERR, "OSRM routed no pair (is it running with a map that covers {$cityName}?).\n");
    exit(1);
}

$summary = $container->get(RouteFactorStudyService::class)->summarize($pairs, ROUTE_FACTOR);

printf("## Straight line x %.2f vs. real road route: %s/%s\n\n", ROUTE_FACTOR, $city['name'], $state);
printf("%d random pairs of CNEFE house numbers (seed %d), routed by car on OSRM", $summary['pairs'], $seed);
echo $failed > 0 ? "; {$failed} pair(s) without a route were skipped.\n\n" : ".\n\n";

echo "| | min | median | mean | p90 | max |\n|---|---|---|---|---|---|\n";
vprintf("| route / straight line | %.2f | %.2f | %.2f | %.2f | %.2f |\n\n", $summary['ratio']);

$error = $summary['estimate_error_pct'];
printf("Error of the x%.2f estimate against the real route:\n\n", ROUTE_FACTOR);
printf("- median: %.1f%%, 90th percentile: %.1f%%\n", $error['median_abs'], $error['p90_abs']);
printf("- worst underestimate: %.1f%%, worst overestimate: +%.1f%%\n", $error['max_under'], $error['max_over']);
printf("- within 10%% of the real route: %.1f%% of the pairs\n", $summary['within_10_pct']);
printf("- best single factor for this city (least squares): %.2f\n\n", $summary['best_factor']);

echo "Sample:\n\n| from | to | straight km | x" . ROUTE_FACTOR . " km | route km |\n|---|---|---|---|---|\n";
foreach (array_slice($pairs, 0, 8) as $pair) {
    printf(
        "| %s | %s | %.2f | %.2f | %.2f |\n",
        $pair['from'],
        $pair['to'],
        $pair['straight_km'],
        $pair['straight_km'] * ROUTE_FACTOR,
        $pair['route_km']
    );
}
