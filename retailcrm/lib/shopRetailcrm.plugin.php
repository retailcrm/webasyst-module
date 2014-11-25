<?php
class shopRetailcrmPlugin extends shopPlugin
{
    private $client;

    public function explodeFIO($fio)
    {
        $fio = (!$fio) ? false : explode(" ", $fio, 3);
        switch (count($fio)) {
            default:
            case 0:
                $fio['firstName']  = 'ФИО  не указано';
                break;
            case 1:
                $fio['firstName']  = $fio[0];
                break;
            case 2:
                $fio = array(
                    'lastName'  => $fio[0],
                    'firstName' => $fio[1]
                );
                break;
            case 3:
                $fio = array(
                    'lastName'   => $fio[0],
                    'firstName'  => $fio[1],
                    'patronymic' => $fio[2]
                );
                break;
        }

        return $fio;
    }

    public function logger($message, $type, $errors = null)
    {
        if (!file_exists(dirname(__FILE__) . "/../../../../../wa-log/retailcrm/")) {
            mkdir(dirname(__FILE__) . "/../../../../../wa-log/retailcrm/", 0755);
        }
        $format = "[" . date('Y-m-d H:i:s') . "]";
        if (!is_null($errors) && is_array($errors)) {
            $message .= ":\n";
            foreach ($errors as $error) {
                $message .= "\t" . $error . "\n";
            }
        } else {
            $message .= "\n";
        }
        switch ($type) {
            case 'connect':
                $path = dirname(__FILE__) . "/../../../../../wa-log/retailcrm/connect-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'customers':
                $path = dirname(__FILE__) . "/../../../../../wa-log/retailcrm/customers-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'orders':
                $path = dirname(__FILE__) . "/../../../../../wa-log/retailcrm/orders-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'history':
                $path = dirname(__FILE__) . "/../../../../../wa-log/retailcrm/history-error.log";
                error_log($format . " " . $message, 3, $path);
                break;
            case 'history-log':
                $path = dirname(__FILE__) . "/../../../../../wa-log/retailcrm/history.log";
                error_log($format . " " . $message, 3, $path);
                break;
        }

        $app_settings_model = new waAppSettingsModel();
        $settings = json_decode($app_settings_model->get(array('shop', 'retailcrm'), 'options'), true);

        $headers = "MIME-Version: 1.0\r\n" .
                   "Content-type:text/html;charset=UTF-8\r\n" .
                   "X-Priority: 1 (Highest)\r\n" .
                   "X-MSMail-Priority: High\r\n" .
                   "Importance: High\r\n" .
                   "From: support@retailcrm.com\r\n" .
                   "Reply-To: support@retailcrm.com\r\n";

        if (isset($settings["siteurl"]) && !empty($settings["siteurl"])) {
            $headers .= "X-URL:" . $settings["siteurl"] . "\r\n";
        }

        if ($type != 'history-log') {
            mail($settings["email"], "Ошибка обмена retailCRM", $message, $headers);
        }
    }

    public function orderAdd(&$params)
    {
        error_reporting(E_ERROR);
        ini_set("error_reporting", "E_ERROR");
        $app_settings_model = new waAppSettingsModel();
        $settings = json_decode($app_settings_model->get(array('shop', 'retailcrm'), 'options'), true);

        if (isset($settings["status"]) && !empty($settings["status"]) && !isset($settings["createApi"])) {
            $this->client = new ApiClient($settings["url"], $settings["key"]);
            $customers = $this->getCustomers($settings);
            $orders = $this->getOrders($customers, $settings);
            $edit = $this->orderPrepare($customers, $orders, $params);

            $this->edit($edit);
            $this->session($edit);
        }
    }

    private function session($edit)
    {
        $_SESSION["retailcrm"]["id"] = $edit["order"]["externalId"];
        $_SESSION["retailcrm"]["total"] = $this->calcTotal($edit["order"]);
        foreach ($edit["order"]["items"] as $key => $val) {
            $_SESSION["retailcrm"]["items"][ $key ]["price"] = $val["initialPrice"];
            $_SESSION["retailcrm"]["items"][ $key ]["quantity"] = $val["quantity"];
        }
    }

    private function calcTotal($data)
    {
        $total = 0;
        foreach ($data['items'] as $item) {
            $total += $item['initialPrice'] * (int) $item['quantity'];
        }
        if ($total == 0) {
            return $total;
        }
        $discount = 0;
        if (isset($data['discount'])) {
            $discount = $data['discount'];
        }
        $delivery = 0;
        if (isset($data["delivery"]["cost"])) {
            $delivery = $data["delivery"]["cost"];
        }

        return $total - $discount + $delivery;
    }

    private function orderPrepare($customers, $orders, $params)
    {
        $result = array();

        $result["order"] = (isset($orders[$params["order_id"]])) ? $orders[$params["order_id"]] : "";
        $result["customer"] = (isset($customers[$result["order"]["customerId"]])) ? $customers[$result["order"]["customerId"]] : "";

        return $result;
    }

    private function edit($edit)
    {
        $client = $this->client;
        if (!is_null($edit["customer"])) {
            try {
                $response = $client->customersEdit($edit["customer"]);
            } catch (CurlException $e) {
                $this->logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
                die();
            }

            if (!$response->isSuccessful()) {
                $message = sprintf(
                    "Ошибка создания клиента: [Статус HTTP-ответа %s] %s",
                    $response->getStatusCode(),
                    $response->getErrorMsg()
                );
                $this->logger($message, "customers", $response["errors"]);
            }
        }

        if (!is_null($edit["order"])) {
            try {
                $response = $client->ordersEdit($edit["order"]);
            } catch (CurlException $e) {
                $this->logger("Сетевые проблемы. Ошибка подключения к retailCRM: " . $e->getMessage(), "connect");
                die();
            }

            if (!$response->isSuccessful()) {
                $message = sprintf(
                    "Ошибка создания заказа: [Статус HTTP-ответа %s] %s",
                    $response->getStatusCode(),
                    $response->getErrorMsg()
                );
                $this->logger($message, "customers", $response["errors"]);
            }
        }
    }

    public function getCustomers($parentSetting, $filter)
    {
        $contact = new waContactsCollection();
        $customers = array();

        $country = new waContactCountryField();
        $country = $country->getOptions();

        $region = new waRegionModel;
        $region = $region->getAll();
        $regions = array();
        foreach ($region as $key => $value) {
            $regions[ $value["code"] ] = $value["name"];
        }

        foreach ($contact->getContacts("*", 0, 99999) as $key => $value) {
            $customer = array();
            $customer["externalId"] = $value["id"];
            $customer["createdAt"] = $value["create_datetime"];

            $settings = array();
            if ($value["is_company"] == 0) {
                $settings = $parentSetting["order"]["person"];
                $customer["contragentType"] = "individual";
            } else {
                $settings = $parentSetting["order"]["company"];
                $customer["contragentType"] = "legal-entity";
            }

            if (!isset($settings["lastName"]) || empty($settings["lastName"]) ||
                !isset($value[ $settings["lastName"] ]) || empty($value[ $settings["lastName"] ])) {
                if (isset($settings["firstName"]) && !empty($settings["firstName"]) &&
                    isset($value[ $settings["firstName"] ]) && !empty($value[ $settings["firstName"] ])) {
                    $customer = array_merge($customer,
                        $this->explodeFIO($value[ $settings["firstName"] ]));
                } else {
                    $customer['firstName']  = 'ФИО не указано';
                }
            } else {
                if (isset($settings["lastName"]) && !empty($settings["lastName"]) && !empty($value[ $settings["lastName"] ])) {
                    $customer["lastName"] = $value[ $settings["lastName"] ];
                }
                if (isset($settings["firstName"]) && !empty($settings["firstName"]) && !empty($value[ $settings["firstName"] ])) {
                    $customer["firstName"] = $value[ $settings["firstName"] ];
                }
                if (isset($settings["patronymic"]) && !empty($settings["patronymic"]) && !empty($value[ $settings["patronymic"] ])) {
                    $customer["patronymic"] = $value[ $settings["patronymic"] ];
                }
            }

            if (isset($settings["email"]) && !empty($settings["email"]) && !empty($value[ $settings["email"] ])) {
                $customer["email"] = (is_array($value[ $settings["email"] ])) ?
                $value[ $settings["email"] ][0] :
                $value[ $settings["email"] ];
            }

            if (isset($settings["phone"]) && !empty($settings["phone"]) && !empty($value[ $settings["phone"] ])) {
                if (is_array($value[ $settings["phone"] ])) {
                    foreach ($value[ $settings["phone"] ] as $kp => $vp) {
                        $customer["phones"][]["number"] = $vp["value"];
                    }
                } else {
                    $customer["phones"][]["number"] = $value[ $settings["phone"] ];
                }
            }

            $address = $value["address"][0]["data"];
            if (isset($settings["text"]) && !empty($settings["text"]) && !empty($address[ $settings["text"] ])) {
                $customer["address"]["text"] = $address[ $settings["text"] ];
            }

            if (isset($settings["index"]) && !empty($settings["index"]) && !empty($address[ $settings["index"] ])) {
                $customer["address"]["index"] = $address[ $settings["index"] ];
            }

            if (isset($settings["country"]) && !empty($settings["country"]) && !empty($address[ $settings["country"] ])) {
                $customer["address"]["country"] = (array_key_exists($address[ $settings["country"] ], $country)) ?
                $country[ $address[ $settings["country"] ] ] :
                $address[ $settings["country"] ];
            }

            if (isset($settings["region"]) && !empty($settings["region"]) && !empty($address[ $settings["region"] ])) {
                $customer["address"]["region"] = (array_key_exists($address[ $settings["region"] ], $regions)) ?
                $regions[ $address[ $settings["region"] ] ] :
                $address[ $settings["region"] ];
            }

            if (isset($settings["city"]) && !empty($settings["city"]) && !empty($address[ $settings["city"] ])) {
                $customer["address"]["city"] = $address[ $settings["city"] ];
            }

            if (isset($settings["street"]) && !empty($settings["street"]) && !empty($address[ $settings["street"] ])) {
                $customer["address"]["street"] = $address[ $settings["street"] ];
            }

            if (isset($settings["building"]) && !empty($settings["building"]) && !empty($address[ $settings["building"] ])) {
                $customer["address"]["building"] = $address[ $settings["building"] ];
            }

            if (isset($settings["flat"]) && !empty($settings["flat"]) && !empty($address[ $settings["flat"] ])) {
                $customer["address"]["flat"] = $address[ $settings["flat"] ];
            }

            if (isset($settings["intercomcode"]) && !empty($settings["intercomcode"]) && !empty($address[ $settings["intercomcode"] ])) {
                $customer["address"]["intercomCode"] = $address[ $settings["intercomcode"] ];
            }

            if (isset($settings["floor"]) && !empty($settings["floor"]) && !empty($address[ $settings["floor"] ])) {
                $customer["address"]["floor"] = $address[ $settings["floor"] ];
            }

            if (isset($settings["block"]) && !empty($settings["block"]) && !empty($address[ $settings["block"] ])) {
                $customer["address"]["block"] = $address[ $settings["block"] ];
            }

            if (isset($settings["house"]) && !empty($settings["house"]) && !empty($address[ $settings["house"] ])) {
                $customer["address"]["house"] = $address[ $settings["house"] ];
            }

            $customers[ $value["id"] ] = $customer;
        }

        return $customers;
    }

    public function getOrders($customers, $parentSetting)
    {
        $shopOrders = null;
        if (class_exists("shopOrdersCollection")) {
            $shopOrders = new shopOrdersCollection();
        } else {
            $shopOrders = new retailcrmOrdersCollection();
        }
        $orders = array();

        $app_settings_model = new waAppSettingsModel();
        $orderFormat = htmlspecialchars($app_settings_model->get('shop', 'order_format', "#100\{\$order.id\}"), ENT_QUOTES, 'utf-8');

        foreach ($shopOrders->getOrders("*", 0, 99999) as $key => $value) {
            $order = array();
            $setting = array();

            $setting = $parentSetting;

            $order_items_model = new shopOrderItemsModel();
            $value["items"] = $order_items_model->getItems($value['id']);

            $order_params_model = new shopOrderParamsModel();
            $value["params"] = $order_params_model->get($value['id']);

            $order["externalId"] = $value["id"];
            $order["number"] = preg_replace("/(\{.*\})/", $value["id"], $orderFormat);
            $order["createdAt"] = $value["create_datetime"];

            if ($value["discount"] > 0) {
                $order["discount"] = $value["discount"];
            }

            $order["customerId"] = $value["contact_id"];

            $customer = array();
            if (isset($customers[ $order["customerId"] ]) && is_array($customers[ $order["customerId"] ])) {
                $customer = $customers[ $order["customerId"] ];
            }

            if ($customer["contragentType"] == "individual") {
                $order["orderType"] = "eshop-individual";
            } else {
                $order["orderType"] = "eshop-legal";
            }

            if (isset($customer["lastName"]) && !empty($customer["lastName"])) {
                $order["lastName"] = $customer["lastName"];
            }

            if (isset($customer["firstName"]) && !empty($customer["firstName"])) {
                $order["firstName"] = $customer["firstName"];
            }

            if (isset($customer["patronymic"]) && !empty($customer["patronymic"])) {
                $order["patronymic"] = $customer["patronymic"];
            }

            if (isset($customer["phones"]) && is_array($customer["phones"])) {
                $order["phone"] = $customer["phones"][0]["number"];
                if (count($customer["phones"]) > 1 && isset($customer["phones"][1]["number"])) {
                    $order["additionalPhone"] = $customer["phones"][1]["number"];
                }
            }

            if (isset($customer["email"]) && !empty($customer["email"])) {
                $order["email"] = $customer["email"];
            }

            if (!empty($value["comment"])) {
                $order["customerComment"] = $value["comment"];
            }

            $order["orderMethod"] = "shopping-cart";

            if (isset($setting["paymentTypes"][ $value["params"]["payment_plugin"] ]) && !empty($setting["paymentTypes"][ $value["params"]["payment_plugin"] ])) {
                $order["paymentType"] = $setting["paymentTypes"][ $value["params"]["payment_plugin"] ];
            }

            if (isset($setting["statuses"][ $value["state_id"] ]) && !empty($setting["statuses"][ $value["state_id"] ])) {
                $order["status"] = $setting["statuses"][ $value["state_id"] ];
            }

            if (isset($setting["deliveryTypes"][ $value["params"]["shipping_plugin"] ]) && !empty($setting["deliveryTypes"][ $value["params"]["shipping_plugin"] ])) {
                $order["delivery"]["code"] = $setting["deliveryTypes"][ $value["params"]["shipping_plugin"] ];
            }

            if ($value["shipping"] > 0) {
                $order["delivery"]["cost"] = $value["shipping"];
            }

            if (isset($customer["address"]) && is_array($customer["address"])) {
                $order["delivery"]["address"] = $customer["address"];
            }

            foreach ($value["items"] as $ik => $iv) {
                $items = array();
                if (!empty($iv["price"])) {
                    $items["initialPrice"] = $iv["price"];
                }
                if (!empty($iv["purchase_price"]) && $iv["purchase_price"] > 0) {
                    $items["purchasePrice"] = $iv["purchase_price"];
                }
                if (!empty($iv["quantity"]) && $iv["quantity"] > 0) {
                    $items["quantity"] = $iv["quantity"];
                }
                if (!empty($iv["name"])) {
                    $items["productName"] = $iv["name"];
                }
                if (!empty($iv["sku_id"])) {
                    $items["productId"] = $iv["sku_id"];
                } elseif (!empty($iv["product_id"])) {
                    $items["productId"] = $iv["product_id"];
                }

                $order["items"][] = $items;
            }

            $orders[$value["id"]] = $order;
        }

        return $orders;
    }

    public function analyticsAdd()
    {
        $app_settings_model = new waAppSettingsModel();
        $settings = json_decode($app_settings_model->get(array('shop', 'retailcrm'), 'options'), true);
        $js = "";
        if (isset($settings["status"]) && isset($settings["analytics"]["status"]) && isset($settings["analytics"]["id"])) {
            $js = "<script type=\"text/javascript\">
                        (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
                        (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
                        m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
                        })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

                        ga('create', '%s', 'auto');

                        function getCookie(name) {
                            var matches = document.cookie.match(new RegExp(
                                \"(?:^|; )\" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + \"=([^;]*)\"
                            ));

                            return matches ? decodeURIComponent(matches[1]) : \"\";
                        }

                        ga('set', 'dimension1', getCookie(\"_ga\"));
                        ga('send', 'pageview');\n";

            if (isset($_SESSION['retailcrm'])) {
                $js .= "ga('require', 'ecommerce', 'ecommerce.js');\n";
                $js .= sprintf("ga('ecommerce:addTransaction', {
                                    'id': %s,
                                    'affiliation': '%s', // заменить на реальное доменное имя
                                    'revenue': %s}
                                });\n",
                                $_SESSION['retailcrm']['id'],
                                parse_url($settings["siteurl"],
                                PHP_URL_HOST), $_SESSION['retailcrm']['total']);
                foreach ($_SESSION['retailcrm'] as $item) {
                    $js .= sprintf("ga('ecommerce:addItem', {
                                        'id': %s,
                                        'price': %s,
                                        'quantity': %s
                                    });\n", $_SESSION['retailcrm']['id'], $item["price"], $item["quantity"]);
                }
                $js .= "ga('ecommerce:send');\n";
                unset($_SESSION['retailcrm']);
            }

            $js .= "</script>";
            $js = sprintf($js, $settings["analytics"]["id"]);
        };

        return $js;
    }
}
