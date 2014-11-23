<?php

$config = include(dirname(__FILE__) . "/../../../../../../wa-config/apps/shop/workflow.php");
$handlers = array();
foreach ($config["actions"] as $ak => $vk) {
    $handlers["order_action." . $ak] = 'orderAdd';
}
$handlers["frontend_head"] = "analyticsAdd";
return array(
    'name'          => 'Retailcrm',
    'description'   => 'Автоматизация интернет-продаж',
    'vendor'        => '1009747',
    'version'       => '3.0.0',
    'img'           => 'img/icon.png',
    'shop_settings' => true,
    'frontend'      => true,
    'handlers'      => $handlers
);
