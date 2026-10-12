<?php

namespace app\domain\model;

final class Coordinate
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng
    ) {
    }
}
