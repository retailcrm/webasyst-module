<?php

class shopRetailcrmPluginBackendSaveController extends waJsonController
{
    public $client;
    public function execute()
    {
        if (waRequest::getMethod() == 'post') {
            $plugin = waSystem::getInstance()->getPlugin('retailcrm');
            $settings = (array) $this->getRequest()->post("retailcrm");
            if ('/' != substr($settings["options"]["siteurl"], strlen($settings["options"]["siteurl"]) - 1, 1)) {
                $settings["options"]["siteurl"] .= '/';
            }
            if ('/' != substr($settings["options"]["url"], strlen($settings["options"]["url"]) - 1, 1)) {
                $settings["options"]["url"] .= '/';
            }
            try {
                $this->response = $plugin->saveSettings($settings);
                if (empty($settings["options"]["url"]) || empty($settings["options"]["key"])) {
                    $this->setError("Заполните все поля");
                } elseif ($this->checkConnect($settings["options"]["url"], $settings["options"]["key"])) {
                    $this->response['message'] = _w('Saved');
                }
            } catch (Exception $e) {
                $this->setError($e->getMessage());
            }
        }
    }

    public function checkConnect($url, $key)
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
}
