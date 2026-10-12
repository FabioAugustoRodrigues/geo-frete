<?php

namespace app\http\controller;

use app\core\controller\ControllerAbstract;
use app\core\controller\Request;
use app\domain\exception\AddressNotFoundException;
use app\domain\exception\GeocoderUnavailableException;
use app\domain\exception\ValidationException;
use app\domain\service\DistanceCalculationService;
use app\domain\service\RequestValidatorService;

class DistanceController extends ControllerAbstract
{
    public function __construct(
        private RequestValidatorService $validator,
        private DistanceCalculationService $distanceCalculation
    ) {
    }

    public function calculate(Request $request)
    {
        $data = is_array($request->getData()) ? $request->getData() : [];

        try {
            [$origin, $destination] = $this->validator->validateDistanceRequest($data);

            $result = $this->distanceCalculation->calculate($origin, $destination);

            return $this->respondJson(["data" => $result, "message" => ""], 200);
        } catch (ValidationException $e) {
            return $this->respondJson(
                ["data" => ["errors" => $e->getErrors()], "message" => "Invalid data."],
                400
            );
        } catch (AddressNotFoundException $e) {
            return $this->respondJson(
                [
                    "data" => ["reason" => "not_found", "address" => $e->getLabel()],
                    "message" => "Could not locate the {$e->getLabel()} address. Send lat/lng to proceed."
                ],
                422
            );
        } catch (GeocoderUnavailableException $e) {
            error_log("[GEOCODER UNAVAILABLE] " . $e->getMessage());

            return $this->respondJson(
                ["data" => null, "message" => "Geocoding providers are unavailable. Try again in a moment."],
                503
            );
        }
    }
}
