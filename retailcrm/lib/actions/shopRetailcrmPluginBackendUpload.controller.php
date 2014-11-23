<?php

class shopRetailcrmPluginBackendUploadController extends waJsonController
{
    public $client;
    public function execute()
    {
        $app_settings_model = new waAppSettingsModel();
        $settings = json_decode($app_settings_model->get(array('shop', 'retailcrm'), 'options'), true);
        $this->client = new ApiClient($settings["url"], $settings["key"]);

        $type = $this->getRequest()->get("upload");
        switch ($type) {
            case "deliveryTypes":
                $this->uploadDeliveryTypes();
                break;
            case "paymentTypes":
                $this->uploadPaymentTypes();
                break;
        }
        $this->response['message'] = _w('Saved');
    }

    public function uploadDeliveryTypes()
    {
        $client = $this->client;
        $delivery = shopShipping::getList();

        foreach ($delivery as $code => $params) {
            try {
                $client->deliveryTypesEdit(array(
                "name" => $params["name"],
                "code" => $code,
                "description" => $params["description"],
            ));
            } catch (CurlException $e) {
                $this->setError("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage());
            }
        }
    }

    public function uploadPaymentTypes()
    {
        $client = $this->client;
        $payment = waPayment::enumerate();

        foreach ($payment as $code => $params) {
            try {
                $client->paymentTypesEdit(array(
                    "name" => $params["name"],
                    "code" => $code,
                    "description" => $params["description"],
                ));
            } catch (CurlException $e) {
                $this->setError("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage());
            }
        }
    }
}
