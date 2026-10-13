<?php

namespace app\http\controller;

use app\core\controller\ControllerAbstract;
use app\core\controller\Request;
use app\domain\service\CityService;

class CityController extends ControllerAbstract
{
    public function __construct(private CityService $cityService)
    {
    }

    public function index(Request $request)
    {
        return $this->respondJson(
            ["data" => ["cities" => $this->cityService->listImported()], "message" => ""],
            200
        );
    }
}
