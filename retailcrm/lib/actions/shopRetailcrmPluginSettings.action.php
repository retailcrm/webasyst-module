<?php

class shopRetailcrmPluginSettingsAction extends waViewAction
{
    public $client;
    public $settings;

    public function execute()
    {
        $app_settings_model = new waAppSettingsModel();
        $this->settings = json_decode($app_settings_model->get(array('shop', 'retailcrm'), 'options'), true);

        $companyName = htmlspecialchars($app_settings_model->get('webasyst', 'name', 'Webasyst'), ENT_QUOTES, 'utf-8');

        if (!is_null($this->settings["url"]) && !is_null($this->settings["key"])) {
            if ($this->checkConnect($this->settings["url"], $this->settings["key"])) {
                $this->addHandbook($this->settings);
                $this->addValue();
                $this->prepareSettings();
            }
        }

        $this->view->assign('settings', $this->settings);
        $this->view->assign('companyName', $companyName);
    }

    private function checkConnect($url, $key)
    {
        $this->client = new ApiClient($url, $key);
        $client = $this->client;
        try {
            $response = $client->statusesList();
        } catch (CurlException $e) {
            $this->setError("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage());
        }

        if ($response->isSuccessful()) {
            return true;
        } else {
            return false;
        }
    }

    private function prepareSettings()
    {
        foreach ($this->settings["site"]["deliveryTypes"] as $key => $value) {
            if (!isset($this->settings["deliveryTypes"][$value["code"]]) || empty($this->settings["deliveryTypes"][$value["code"]])) {
                if (array_key_exists($value["code"], $this->settings["crm"]["deliveryTypes"])) {
                    $this->settings["deliveryTypes"][$value["code"]] = $this->settings["crm"]["deliveryTypes"][$value["code"]]["code"];
                }
            }
        }

        foreach ($this->settings["site"]["paymentTypes"] as $key => $value) {
            if (!isset($this->settings["paymentTypes"][$value["code"]]) || empty($this->settings["paymentTypes"][$value["code"]])) {
                if (array_key_exists($value["code"], $this->settings["crm"]["paymentTypes"])) {
                    $this->settings["paymentTypes"][$value["code"]] = $this->settings["crm"]["paymentTypes"][$value["code"]]["code"];
                }
            }
        }

        foreach ($this->settings["site"]["statuses"] as $key => $value) {
            if (!isset($this->settings["statuses"][$value["code"]]) || empty($this->settings["statuses"][$value["code"]])) {
                if (array_key_exists($value["code"], $this->settings["crm"]["statuses"])) {
                    $this->settings["statuses"][$value["code"]] = $this->settings["crm"]["statuses"][$value["code"]]["code"];
                }
            }
        }

        foreach ($this->settings["crm"]["contactValue"] as $key => $value) {
            if (!isset($this->settings["order"]["person"][$key]) || empty($this->settings["order"]["person"][$key])) {
                if (array_key_exists($key, $this->settings["site"]["contactValue"])) {
                    $this->settings["order"]["person"][$key] = $this->settings["site"]["contactValue"][$key];
                }
            }
            if (!isset($this->settings["order"]["company"][$key]) || empty($this->settings["order"]["company"][$key])) {
                if (array_key_exists($key, $this->settings["site"]["contactValue"])) {
                    $this->settings["order"]["company"][$key] = $this->settings["site"]["contactValue"][$key];
                }
            }
        }

        foreach ($this->settings["crm"]["addressValue"] as $key => $value) {
            if (!isset($this->settings["order"]["person"][$key]) || empty($this->settings["order"]["person"][$key])) {
                if (array_key_exists($key, $this->settings["site"]["addressValue"])) {
                    $this->settings["order"]["person"][$key] = $this->settings["site"]["addressValue"][$key];
                    $save = true;
                }
            }
            if (!isset($this->settings["order"]["company"][$key]) || empty($this->settings["order"]["company"][$key])) {
                if (array_key_exists($key, $this->settings["site"]["addressValue"])) {
                    $this->settings["order"]["company"][$key] = $this->settings["site"]["addressValue"][$key];
                }
            }
        }

        foreach ($this->settings["crm"]["addressValue"] as $key => $value) {
            if (!isset($this->settings["order"]["person"][$key]) || empty($this->settings["order"]["person"][$key])) {
                if (array_key_exists($key, $this->settings["site"]["addressValue"])) {
                    $this->settings["order"]["person"][$key] = $this->settings["site"]["addressValue"][$key];
                }
            }
            if (!isset($this->settings["order"]["company"][$key]) || empty($this->settings["order"]["company"][$key])) {
                if (array_key_exists($key, $this->settings["site"]["addressValue"])) {
                    $this->settings["order"]["company"][$key] = $this->settings["site"]["addressValue"][$key];
                }
            }
        }

        foreach ($this->settings["crm"]["offers"] as $key => $value) {
            if (!isset($this->settings["offers"][$key]) || empty($this->settings["offers"][$key])) {
                if (array_key_exists($key, $this->settings["site"]["offers"])) {
                    $this->settings["offers"][$key] = $this->settings["site"]["offers"][$key];
                }
            }
        }
    }

    private function addValue()
    {
        $this->settings["crm"]["contactValue"] = array(
            "firstName"  => "Имя контактного лица",
            "lastName"   => "Фамилия контактного лица",
            "patronymic" => "Отчество контактного лица",
            "phone"      => "Контактный телефон",
            "email"      => "Email"
        );

        $this->settings["crm"]["addressValue"] = array(
            "text"         => "Адрес (строкой)",
            "index"        => "Индекс",
            "country"      => "Страна",
            "region"       => "Регион",
            "city"         => "Город",
            "street"       => "Улица",
            "building"     => "Строение",
            "flat"         => "Квартира",
            "intercomcode" => "Домофон",
            "floor"        => "Этаж",
            "block"        => "Подъезд",
            "house"        => "Строение / корпус",
        );

        $person = waContactFields::getInfo('person');
        unset($person["name"]);
        foreach ($person as $code => $name) {
            if (empty($name["name"])) {
                continue;
            }
            if ($code != "address") {
                $this->settings["site"]["contactValue"][$code] = $name["name"];
            } else {
                foreach ($name["fields"] as $codeAddress => $namAddress) {
                    $this->settings["site"]["addressValue"][$codeAddress] = $namAddress["name"];
                }
            }
        }

        $this->settings["crm"]["offers"] = array(
            "xmlId"   => "XML ID",
            "article" => "Артикул",
            "size"    => "Размер",
            "color"   => "Цвет",
            "weight"  => "Вес",
            "vendor"  => "Производитель",
        );

        $feature_model = new shopFeatureModel();
        $features = $feature_model->getFeatures(true, null, 'id');
        $this->settings["site"]["offers"]["sku"] = "Артикул";
        foreach ($features as $code => $name) {
            $this->settings["site"]["offers"][$name["code"]] = $name["name"];
        }
    }

    private function addHandbook($settings)
    {
        $client = $this->client;

        try {
            $response = $client->deliveryTypesList();
        } catch (CurlException $e) {
            $this->setError("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage());
        }

        if ($response->isSuccessful()) {
            foreach ($response->deliveryTypes as $code => $params) {
                $this->settings["crm"]["deliveryTypes"][$code] = array(
                        "name" => $params["name"],
                        "code" => $params["code"]
                    );
            }
        } else {
            $this->setError("Ошибка получения информации: " . $e->getMessage());
        }

        $delivery = shopShipping::getList();
        foreach ($delivery as $code => $params) {
            $this->settings["site"]["deliveryTypes"][$code] = array(
                    "name" => $params["name"],
                    "code" => $code
                );
        }

        try {
            $response = $client->paymentTypesList();
        } catch (CurlException $e) {
            $this->setError("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage());
        }

        if ($response->isSuccessful()) {
            foreach ($response->paymentTypes as $code => $params) {
                $this->settings["crm"]["paymentTypes"][$code] = array(
                        "name" => $params["name"],
                        "code" => $params["code"]
                    );
            }
        } else {
            $this->setError("Ошибка получения информации: " . $e->getMessage());
        }

        $payment = waPayment::enumerate();
        foreach ($payment as $code => $params) {
            $this->settings["site"]["paymentTypes"][$code] = array(
                    "name" => $params["name"],
                    "code" => $code
                );
        }

        try {
            $response = $client->statusesList();
        } catch (CurlException $e) {
            $this->setError("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage());
        }

        if ($response->isSuccessful()) {
            foreach ($response->statuses as $code => $params) {
                $this->settings["crm"]["statuses"][$code] = array(
                        "name" => $params["name"],
                        "code" => $params["code"]
                    );
            }
        } else {
            $this->setError("Ошибка получения информации: " . $e->getMessage());
        }

        $workflow = new shopWorkflow();
        $statuses = $workflow->getAllStates();
        foreach ($statuses as $code => $params) {
            $this->settings["site"]["statuses"][$code] = array(
                    "name" => $params->name,
                    "code" => $params->id
                );
        }
    }
}
