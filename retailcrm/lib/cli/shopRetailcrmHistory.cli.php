<?php

class shopRetailcrmHistoryCli extends waCliController
{
    private $settings;
    private $client;
    private $orders;
    private $actions;

    public function execute()
    {
        $app_settings_model = new waAppSettingsModel();
        $this->settings = json_decode($app_settings_model->get(array('shop', 'retailcrm'), 'options'), true);
        if (!ini_get('date.timezone')) {
            date_default_timezone_set('Europe/Moscow');
        }
        if (isset($this->settings["status"]) && !empty($this->settings["status"])) {
            $this->client = new ApiClient($this->settings["url"], $this->settings["key"]);
            $orders = $this->getHistory();

            $config = include(dirname(__FILE__) . "/../../../../../../wa-config/apps/shop/workflow.php");
            $this->actions = array();
            foreach ($config["actions"] as $ak => $vk) {
                if (isset($vk["state"]) && !empty($vk["state"])) {
                    $this->actions[ $vk["state"] ] = $ak;
                }
            }

            foreach ($orders as $key => $order) {
                if (isset($this->settings["removeorder"]) && isset($order["deleted"]) && !isset($order["created"])) {
                    $this->removeOrder($order);
                } elseif (!isset($order["deleted"])) {
                    $this->orders = $order;
                    $customers = $this->addCustomers($order);
                    $this->addOrders($order, $customers);
                }
            }
        }
    }

    private function getHistory()
    {
        $client = $this->client;
        $startDate = (isset($this->settings["history"])) ? new DateTime($this->settings["history"]) : null;
        $endDate = new DateTime();
        try {
            $response = $client->ordersHistory($startDate, $endDate, 200, 0, true);
        } catch (CurlException $e) {
            shopRetailcrmPlugin::logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
            die();
        }

        if (!$response->isSuccessful()) {
            $message = sprintf(
                "Ошибка получения истории: [Статус HTTP-ответа %s] %s",
                $response->getStatusCode(),
                $response->getErrorMsg()
            );
            shopRetailcrmPlugin::logger($message, "history", $response["errors"]);
            die();
        } else {
            $plugin = waSystem::getInstance()->getPlugin('retailcrm');
            $settings["options"] = $this->settings;
            $settings["options"]["history"] = $response["generatedAt"];
            $plugin->saveSettings($settings);
        }

        return $response["orders"];
    }

    private function removeOrder($order)
    {
        if (isset($order["externalId"]) && !empty($order["externalId"])) {
            $model = new shopOrderModel();
            if ($model->delete($order["externalId"])) {
                shopRetailcrmPlugin::logger("Удален заказ: #" . $order["externalId"], "history-log");
            } else {
                shopRetailcrmPlugin::logger("Не удалось удалить заказ: #"  . $order["externalId"], "history-log");
            }
        }
    }

    private function addOrders($order, waContact $customers)
    {
        $workflow = new shopWorkflow();
        $client = $this->client;

        $data = $this->getData((isset($order["externalId"])) ? $order["externalId"] : null);
        $data['contact'] = $customers;

        $shippingAddress = array();
        if (!empty($data['contact'])) {
            $address = $data['contact']->getFirst('address.shipping');
            if (!$address) {
                $address = $data['contact']->getFirst('address');
            }
            if (!empty($address['data'])) {
                $shippingAddress = $address['data'];
            }
        }

        $model = new shopPluginModel();
        $emptyAddress = false;

        $delivery = $this->settings["deliveryTypes"];
        if (isset($delivery) && !empty($delivery) && isset($order["delivery"]["code"])) {
            $search = array_search($order["delivery"]["code"], $delivery);
            if ($search) {
                $shiping = $model->getByField("plugin", $search);
                $data['params']['shipping_id'] = $shiping["id"];
                $data['params']['shipping_rate_id'] = "delivery";
                $plugin = shopShipping::getPlugin($shiping["plugin"], $shiping["id"]);
                $rates = $plugin->getRates($this->getOrderItems($data['items'], $plugin->allowedWeightUnit()), $shippingAddress);

                if ($plugin->allowedAddress() === false) {
                    $emptyAddress = true;
                }

                $rate = $rates[ $data['params']['shipping_rate_id'] ];
                $data['params']['shipping_plugin'] = $plugin->getId();
                $data['params']['shipping_name'] = $plugin['name'].(!empty($rate['name']) ? ' ('.$rate['name'].')' : '');
                $data['params']['shipping_est_delivery'] = $rate['est_delivery'];
            }
        } else {
            foreach (array('id', 'rate_id', 'plugin', 'name', 'est_delivery') as $k) {
                $data['params']['shipping_'.$k] = null;
            }
        }

        $payment = $this->settings["paymentTypes"];
        if (isset($payment) && !empty($payment) && isset($order["paymentType"])) {
            $search = array_search($order["paymentType"], $payment);
            if ($search) {
                $pay = $model->getByField("plugin", $search);
                $data['params']['payment_id'] = $pay["id"];
                $data['params']['payment_plugin'] = $pay['plugin'];
                $data['params']['payment_name'] = $pay['name'];
            }
        }

        if (!empty($data['contact'])) {
            if (isset($order["externalId"]) && !empty($order["externalId"])) {
                $opm = new shopOrderParamsModel();
                foreach ($opm->get($order["externalId"]) as $k => $v) {
                    if (preg_match('~^(billing|shipping)_address\.~', $k)) {
                        $data['params'][$k] = null;
                    }
                }
            }

            if (!$emptyAddress && $shippingAddress) {
                foreach ($shippingAddress as $k => $v) {
                    $data['params']['shipping_address.'.$k] = $v;
                }
            }

            $plugin = waSystem::getInstance()->getPlugin('retailcrm');
            $apiSettings["options"] = $this->settings;
            $apiSettings["options"]["createApi"] = 0;
            $plugin->saveSettings($apiSettings);
            
            $id = null;
            if (!isset($order["externalId"]) || empty($order["externalId"]) || is_null($order["externalId"])) {
                $id = $workflow->getActionById('create')->run($data);
            } else {
                $id = $order["externalId"];
                $data['id'] = $order["externalId"];
                $workflow->getActionById('edit')->run($data);
            }

            $status = $this->settings["statuses"];
            if (isset($status) && !empty($status) && isset($order["status"])) {
                $search = array_search($order["status"], $status);
                if ($search && !empty($id) && !is_null($id) && $search != "new") {
                    $action = $workflow->getActionById($this->actions[ $search ]);
                    $action->run($id);
                }
            }

            $apiSettings["options"]["createApi"] = 1;
            $plugin->saveSettings($apiSettings);

            if (!empty($id) && !is_null($id)) {
                try {
                    $response = $client->ordersFixExternalIds(array(
                        array(
                            "id"         => $order["id"],
                            "externalId" => $id,
                        ),
                    ));
                } catch (CurlException $e) {
                    shopRetailcrmPlugin::logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
                    die();
                }

                if (!$response->isSuccessful()) {
                    $message = sprintf(
                        "Ошибка сопостовления id заказов: [Статус HTTP-ответа %s] %s",
                        $response->getStatusCode(),
                        $response->getErrorMsg()
                    );
                    shopRetailcrmPlugin::logger($message, "orders", $response["errors"]);
                    die();
                }
            }

            if (!empty($id) && !is_null($id) && isset($this->settings["number"])) {
                $app_settings_model = new waAppSettingsModel();
                $orderFormat = htmlspecialchars($app_settings_model->get('shop', 'order_format', "#100\{\$order.id\}"), ENT_QUOTES, 'utf-8');

                try {
                    $response = $client->ordersEdit(array(
                            "id"         => $order["id"],
                            "externalId" => $id,
                            "number"     => preg_replace("/(\{.*\})/", $id, $orderFormat),
                    ));
                } catch (CurlException $e) {
                    shopRetailcrmPlugin::logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
                    die();
                }

                if (!$response->isSuccessful()) {
                    $message = sprintf(
                        "Ошибка изменения номера заказа: [Статус HTTP-ответа %s] %s",
                        $response->getStatusCode(),
                        $response->getErrorMsg()
                    );
                    shopRetailcrmPlugin::logger($message, "orders", $response["errors"]);
                    die();
                }
            }
        }
    }

    private function getOrderItems($items, $weight_unit)
    {
        $product_ids = array();
        foreach ($items as $item) {
            $product_ids[] = $item['product_id'];
        }
        $product_ids = array_unique($product_ids);
        $feature_model = new shopFeatureModel();
        $f = $feature_model->getByCode('weight');
        if (!$f) {
            $values = array();
        } else {
            $values_model = $feature_model->getValuesModel($f['type']);
            $values = $values_model->getProductValues($product_ids, $f['id']);
        }

        $m = null;
        if ($weight_unit) {
            $dimension = shopDimension::getInstance()->getDimension('weight');
            if ($weight_unit != $dimension['base_unit']) {
                $m = $dimension['units'][$weight_unit]['multiplier'];
            }
        }

        foreach ($items as &$item) {
            if ($item['type'] == 'product') {
                if (isset($values['skus'][$item['sku_id']])) {
                    $w = $values['skus'][$item['sku_id']];
                } else {
                    $w = isset($values[$item['product_id']]) ? $values[$item['product_id']] : 0;
                }
                if ($m !== null) {
                    $w = $w / $m;
                }
                $item['weight'] = $w;
            } else {
                $item['weight'] = 0;
            }
        }
        unset($item);

        return $items;
    }

    private function getData($id)
    {
        $data = array(
                    'currency' => $this->getConfig()->getCurrency(),
                    'rate'     => 1,
                    'items'    => $this->getItems()
                    );

        $data['comment'] = (isset($this->orders["customerComment"])) ? $this->orders["customerComment"] : "";
        $data['shipping'] = (isset($this->orders["delivery"]["cost"])) ? $this->orders["delivery"]["cost"] : 0;
        $data['discount'] = (isset($this->orders["discount"])) ? $this->orders["discount"] : 0;
        $data['tax'] = 0;
        $data['total'] = (isset($this->orders["totalSumm"])) ? $this->orders["totalSumm"] : $this->calcTotal($data);

        return $data;
    }

    private function calcTotal($data)
    {
        $total = 0;
        foreach ($data['items'] as $item) {
            $total += $this->cast($item['price']) * (int) $item['quantity'];
        }
        if ($total == 0) {
            return $total;
        }

        return $total - $this->cast($data['discount']) + $this->cast($data['shipping']);
    }

    private function cast($value)
    {
        if (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }

        return str_replace(',', '.', (double) $value);
    }

    private function getItems()
    {
        $data = array();

        if (!isset($this->orders["items"]) || empty($this->orders["items"])) {
            return $data;
        }

        foreach ($this->orders["items"] as $ik => $iv) {
            if (!isset($iv["deleted"])) {
                $data[] = array(
                    'name'               => $iv["offer"]["name"],
                    'product_id'         => $iv["id"],
                    'sku_id'             => $iv["offer"]["externalId"],
                    'service_id'         => null,
                    'price'              => $iv["initialPrice"],
                    'currency'           => $this->getConfig()->getCurrency(),
                    'quantity'           => $iv["quantity"],
                    'service_variant_id' => null,
                    'stock_id'           => null
                );
            }
        }

        return $data;
    }

    private function addCustomers($order)
    {
        $client = $this->client;
        $settings = $this->settings;

        if ($order["customer"]["contragentType"] == "legal-entity") {
            $settings = $settings["order"]["company"];
        } else {
            $settings = $settings["order"]["person"];
        }

        if (!isset($order["customerId"]) || is_null($order["customerId"])) {
            $order["customerId"] = 0;
        }

        if (!is_null($order["customerId"])) {
            $contact = new waContact($order["customerId"]);

            $country = new waContactCountryField;
            $country = waContactCountryField::getOptions();

            $region = new waRegionModel;
            $region = $region->getAll();
            $regions = array();
            foreach ($region as $key => $value) {
                $regions[ $value["code"] ] = $value["name"];
            }

            $shippingAddress = array();
            if (isset($settings["text"]) && !empty($settings["text"]) && isset($order["customer"]["address"]["text"])) {
                $shippingAddress[ $settings["text"] ] = $order["customer"]["address"]["text"];
            }

            if (isset($settings["index"]) && !empty($settings["index"]) && isset($order["customer"]["address"]["index"])) {
                $shippingAddress[ $settings["index"] ] = $order["customer"]["address"]["index"];
            }

            if (isset($settings["country"]) && !empty($settings["country"]) && isset($order["customer"]["address"]["country"])) {
                $search = array_search($order["customer"]["address"]["country"], $country);
                $shippingAddress[ $settings["country"] ] = ($search) ? $search : $order["customer"]["address"]["country"];
            }

            if (isset($settings["region"]) && !empty($settings["region"]) && isset($order["customer"]["address"]["region"])) {
                $search = array_search($order["customer"]["address"]["region"], $regions);
                $shippingAddress[ $settings["region"] ] = ($search) ? $search : $order["customer"]["address"]["region"];
            }

            if (isset($settings["city"]) && !empty($settings["city"]) && isset($order["customer"]["address"]["city"])) {
                $shippingAddress[ $settings["city"] ] = $order["customer"]["address"]["city"];
            }

            if (isset($settings["street"]) && !empty($settings["street"]) && isset($order["customer"]["address"]["street"])) {
                $shippingAddress[ $settings["street"] ] = $order["customer"]["address"]["street"];
            }

            if (isset($settings["building"]) && !empty($settings["building"]) && isset($order["customer"]["address"]["building"])) {
                $shippingAddress[ $settings["building"] ] = $order["customer"]["address"]["building"];
            }

            if (isset($settings["flat"]) && !empty($settings["flat"]) && isset($order["customer"]["address"]["flat"])) {
                $shippingAddress[ $settings["flat"] ] = $order["customer"]["address"]["flat"];
            }

            if (isset($settings["intercomcode"]) && !empty($settings["intercomcode"]) && isset($order["customer"]["address"]["intercomCode"])) {
                $shippingAddress[ $settings["intercomcode"] ] = $order["customer"]["address"]["intercomCode"];
            }

            if (isset($settings["floor"]) && !empty($settings["floor"]) && isset($order["customer"]["address"]["floor"])) {
                $shippingAddress[ $settings["floor"] ] = $order["customer"]["address"]["floor"];
            }

            if (isset($settings["block"]) && !empty($settings["block"]) && isset($order["customer"]["address"]["block"])) {
                $shippingAddress[ $settings["block"] ] = $order["customer"]["address"]["block"];
            }

            if (isset($settings["house"]) && !empty($settings["house"]) && isset($order["customer"]["address"]["house"])) {
                $shippingAddress[ $settings["house"] ] = $order["customer"]["address"]["house"];
            }

            $contact['address.shipping'] = $shippingAddress;

            if (isset($settings["lastName"]) && !empty($settings["lastName"]) && isset($order["customer"]["lastName"])) {
                $contact[ $settings["lastName"] ] = $order["customer"]["lastName"];
            }

            if (isset($settings["firstName"]) && !empty($settings["firstName"]) && isset($order["customer"]["firstName"])) {
                $contact[ $settings["firstName"] ] = $order["customer"]["firstName"];
            }

            if (isset($settings["patronymic"]) && !empty($settings["patronymic"]) && isset($order["customer"]["patronymic"])) {
                $contact[ $settings["patronymic"] ] = $order["customer"]["patronymic"];
            }

            if (isset($settings["email"]) && !empty($settings["email"]) && isset($order["customer"]["email"])) {
                $contact[ $settings["email"] ] = $order["customer"]["email"];
            }

            if (isset($settings["phone"]) && !empty($settings["phone"]) && isset($order["customer"]["phones"][0]["number"])) {
                $contact[ $settings["phone"] ] = $order["customer"]["phones"][0]["number"];
            }

            $contact->save();

            if ($order["customerId"] != $contact["id"]) {
                try {
                    $response = $client->customersFixExternalIds(array(
                        array(
                        "id"         => $order["customer"]["id"],
                        "externalId" => $contact["id"],
                        ),
                    ));
                } catch (CurlException $e) {
                    shopRetailcrmPlugin::logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
                    die();
                }
                
                if (!$response->isSuccessful()) {
                    $message = sprintf(
                        "Ошибка сопостовления id клиентов: [Статус HTTP-ответа %s] %s",
                        $response->getStatusCode(),
                        $response->getErrorMsg()
                    );
                    shopRetailcrmPlugin::logger($message, "customers", $response["errors"]);
                    die();
                }
            }

            return $contact;
        }
    }
}
