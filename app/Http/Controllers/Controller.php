<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use Illuminate\Http\Request;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected $data = [];
    protected $uploadsFolder = 'uploads/';

    protected $rajaOngkirApiKey = null;
    protected $rajaOngkirBaseUrl = null;
    protected $rajaOngkirOrigin = null;
    protected $couriers = [
        'jne' => 'JNE',
        'pos' => 'POS Indonesia',
        'tiki' => 'Titipan Kilat'
    ];

    protected $provinces = [];

    public function __construct()
    {
        $this->rajaOngkirApiKey = config('ongkir.api_key');
        $this->rajaOngkirBaseUrl = config('ongkir.base_url');
        $this->rajaOngkirOrigin = config('ongkir.origin');
    }

    protected function rajaOngkirRequest($resource, $params = [], $method = 'GET')
    {
        $client = new Client();

        $headers = ['key' => $this->rajaOngkirApiKey];
        $requestParams = ['headers' => $headers];
        
        $url = $this->rajaOngkirBaseUrl . $resource;
        if ($params && $method == 'POST') {
            $requestParams['form_params'] = $params;
        } else if ($params && $method == 'GET') {
            $query = is_array($params) ? '?' . http_build_query($params) : '';
            $url = $this->rajaOngkirBaseUrl . $resource . $query;
        }

        $response = $client->request($method, $url, $requestParams);

        return json_decode($response->getBody(), true);
    }

    protected function getProvinces()
    {
        $provinceFile = 'provinces.txt';
        $provinceFilePath = $this->uploadsFolder . 'files/' . $provinceFile;

        $isExistProvinceJson = Storage::disk('local')->exists($provinceFilePath);

        if (!$isExistProvinceJson) {
            $response = $this->rajaOngkirRequest('province');
            Storage::disk('local')->put($provinceFilePath, serialize($response['rajaongkir']['results']));
        }

        $province = unserialize(Storage::get($provinceFilePath));

        $provinces = [];
        if (!empty($province)) {
            foreach ($province as $provinceItem) {
                $provinces[$provinceItem['province_id']] = strtoupper($provinceItem['province']);
            }
        }
        
        return $provinces;
    }

    protected function getCities($provinceId)
    {
        $cityFile = 'cities_at_' . $provinceId . '.txt';
        $cityFilePath = $this->uploadsFolder . 'files/' . $cityFile;

        $isExistCitiesJson = Storage::disk('local')->exists($cityFilePath);

        if (!$isExistCitiesJson) {
            $response = $this->rajaOngkirRequest('city', ['province' => $provinceId]);
            Storage::disk('local')->put($cityFilePath, serialize($response['rajaongkir']['results']));
        }

        $cityList = unserialize(Storage::get($cityFilePath));
        
        $cities = [];
        if (!empty($cityList)) {
            foreach ($cityList as $city) {
                $cities[$city['city_id']] = strtoupper($city['type'] . ' ' . $city['city_name']);
            }
        }
        
        return $cities;
    }

    protected function getShippingCost($destination, $weight)
    {
        $params = [
            'origin' => $this->rajaOngkirOrigin,
            'destination' => $destination,
            'weight' => $weight,
        ];

        $results = [];
        foreach ($this->couriers as $code => $courier) {
            $params['courier'] = $code;
            $response = $this->rajaOngkirRequest('cost', $params, 'POST');

            if (!empty($response['rajaongkir']['results'])) {
                foreach ($response['rajaongkir']['results'] as $cost) {
                    if (!empty($cost['costs'])) {
                        foreach ($cost['costs'] as $costDetail) {
                            $serviceName = strtoupper($cost['code']) . ' - ' . $costDetail['service'];
                            $costAmount = $costDetail['cost'][0]['value'];
                            $etd = $costDetail['cost'][0]['etd'];

                            $result = [
                                'service' => $serviceName,
                                'cost' => $costAmount,
                                'etd' => $etd,
                                'courier' => $code,
                            ];

                            $results[] = $result;
                        }
                    }
                }
            }
        }

        $response = [
            'origin' => $params['origin'],
            'destination' => $destination,
            'weight' => $weight,
            'results' => $results,
        ];
        
        return $response;
    }

    protected function initPaymentGateway()
    {
        \Midtrans\Config::$serverKey = config('midtrans.serverKey');
        \Midtrans\Config::$isProduction = false;
        \Midtrans\Config::$isSanitized = true;
        \Midtrans\Config::$is3ds = true;
    }
}
