<?php

class shopRetailcrmUploadCli extends waCliController
{
    private $settings;
    private $client;

    public function execute()
    {
        $app_settings_model = new waAppSettingsModel();
        $this->settings = json_decode($app_settings_model->get(array('shop', 'retailcrm'), 'options'), true);

        if (isset($this->settings["status"]) && !empty($this->settings["status"])) {
            $this->client = new ApiClient($this->settings["url"], $this->settings["key"]);
            $customers = shopRetailcrmPlugin::getCustomers($this->settings);
            $orders = shopRetailcrmPlugin::getOrders($customers, $this->settings);
            $this->upload($customers, $orders);
        }
    }

    private function upload($customers, $orders)
    {
        $client = $this->client;

        if (count($customers) > 0) {
            $customers = array_chunk($customers, 50);
            foreach ($customers as $customer) {
                try {
                    $response = $client->customersUpload($customer);
                    time_nanosleep(0, 250000000);
                } catch (CurlException $e) {
                    shopRetailcrmPlugin::logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
                    die();
                }

                if (!$response->isSuccessful()) {
                    $message = sprintf(
                                    "Ошибка пакетной загрузки клиентов: [Статус HTTP-ответа %s] %s",
                                    $response->getStatusCode(),
                                    $response->getErrorMsg()
                                );
                    shopRetailcrmPlugin::logger($message, "customers", $response["errors"]);
                }
            }
        }

        if (count($orders) > 0) {
            $orders = array_chunk($orders, 50);
            foreach ($orders as $order) {
                try {
                    $response = $client->ordersUpload($order);
                    time_nanosleep(0, 250000000);
                } catch (CurlException $e) {
                    shopRetailcrmPlugin::logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
                    die();
                }

                if (!$response->isSuccessful()) {
                    $message = sprintf(
                        "Ошибка пакетной загрузки заказов: [Статус HTTP-ответа %s] %s",
                        $response->getStatusCode(),
                        $response->getErrorMsg()
                    );
                    shopRetailcrmPlugin::logger($message, "orders", $response["errors"]);
                }
            }
        }
    }
}
