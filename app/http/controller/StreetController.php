<?php

namespace app\http\controller;

use app\core\controller\ControllerAbstract;
use app\core\controller\Request;
use app\domain\exception\ValidationException;
use app\domain\service\RequestValidatorService;
use app\domain\service\StreetMatcherService;

class StreetController extends ControllerAbstract
{
    public function __construct(
        private RequestValidatorService $validator,
        private StreetMatcherService $streetMatcher
    ) {
    }

    /**
     * Autocomplete for address forms. Only reads the local base, so it is
     * fast enough to call on every keystroke.
     */
    public function suggestions(Request $request)
    {
        $data = is_array($request->getData()) ? $request->getData() : [];

        try {
            [$street, $city, $state, $limit] = $this->validator->validateStreetSuggestionRequest($data);
        } catch (ValidationException $e) {
            return $this->respondJson(
                ["data" => ["errors" => $e->getErrors()], "message" => "Invalid data."],
                400
            );
        }

        $suggestions = $this->streetMatcher->suggest($street, $city, $state, $limit);

        return $this->respondJson(
            [
                "data" => [
                    "suggestions" => $suggestions ?? [],
                    "available" => $suggestions !== null,
                ],
                "message" => $suggestions === null ? "No street base for this city." : ""
            ],
            200
        );
    }
}
