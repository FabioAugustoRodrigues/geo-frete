<?php

namespace app\http\controller;

use app\core\controller\ControllerAbstract;
use app\core\controller\Request;

class WebController extends ControllerAbstract
{
    public function documentation(Request $request)
    {
        header('Content-Type: text/html; charset=utf-8');

        return $this->view('documentation');
    }
}
