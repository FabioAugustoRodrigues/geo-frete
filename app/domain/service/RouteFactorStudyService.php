<?php

namespace app\domain\service;

/**
 * Statistics for bin/compare-distances.php: how far real road routes are
 * from the straight line, and how wrong a fixed route factor is.
 */
class RouteFactorStudyService extends ServiceAbstract
{
    /**
     * @param array<int, array{straight_km: float, route_km: float}> $pairs
     * @param float $factor the fixed factor being evaluated (e.g. 1.3)
     *
     * @return array{
     *     pairs: int,
     *     ratio: array{min: float, median: float, mean: float, p90: float, max: float},
     *     estimate_error_pct: array{median_abs: float, p90_abs: float, max_under: float, max_over: float},
     *     within_10_pct: float,
     *     best_factor: float
     * }
     */
    public function summarize(array $pairs, float $factor): array
    {
        // Pairs closer than this are dominated by snapping to the road, not by
        // the street layout, and would distort the ratios.
        $pairs = array_values(array_filter($pairs, fn ($pair) => $pair['straight_km'] >= 0.2));

        if (empty($pairs)) {
            throw new \InvalidArgumentException('No pairs at least 200 m apart to summarize.');
        }

        $ratios = array_map(fn ($pair) => $pair['route_km'] / $pair['straight_km'], $pairs);

        // Signed error of the estimate relative to the real route: negative = estimate too short.
        $errors = array_map(
            fn ($pair) => ($pair['straight_km'] * $factor - $pair['route_km']) / $pair['route_km'] * 100,
            $pairs
        );
        $absErrors = array_map('abs', $errors);

        // Least-squares factor through the origin: sum(straight * route) / sum(straight^2).
        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($pairs as $pair) {
            $numerator += $pair['straight_km'] * $pair['route_km'];
            $denominator += $pair['straight_km'] ** 2;
        }

        return [
            'pairs' => count($pairs),
            'ratio' => [
                'min' => round(min($ratios), 3),
                'median' => round($this->percentile($ratios, 50), 3),
                'mean' => round(array_sum($ratios) / count($ratios), 3),
                'p90' => round($this->percentile($ratios, 90), 3),
                'max' => round(max($ratios), 3),
            ],
            'estimate_error_pct' => [
                'median_abs' => round($this->percentile($absErrors, 50), 1),
                'p90_abs' => round($this->percentile($absErrors, 90), 1),
                'max_under' => round(min($errors), 1),
                'max_over' => round(max($errors), 1),
            ],
            'within_10_pct' => round(count(array_filter($absErrors, fn ($e) => $e <= 10)) / count($pairs) * 100, 1),
            'best_factor' => round($numerator / $denominator, 3),
        ];
    }

    /**
     * Linear interpolation between closest ranks.
     */
    private function percentile(array $values, float $percent): float
    {
        sort($values);
        $position = ($percent / 100) * (count($values) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($position - $lower);
    }
}
