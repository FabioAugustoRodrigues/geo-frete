<?php

namespace app\domain\exception;

use RuntimeException;

/**
 * The routing engine could not produce a route: it is unreachable, or it
 * answered without one (e.g. a point outside the loaded map).
 */
class RoutingUnavailableException extends RuntimeException
{
}
