<?php

namespace app\http\middleware;

use app\core\controller\Request;
use app\exception\http\ApplicationHttpException;
use app\http\ApiKeys;

class ApiKeyMiddleware extends Middleware
{
    public function before(Request $request)
    {
        if (!ApiKeys::isEnabled()) {
            error_log("[API KEY WARNING] Authentication disabled: API_KEYS is empty.");
            return;
        }

        $key = ApiKeys::fromRequest($request);

        if ($key === null) {
            throw new ApplicationHttpException("Missing API key.", 401);
        }

        if (!ApiKeys::isValid($key)) {
            throw new ApplicationHttpException("Invalid API key.", 401);
        }
    }
}
