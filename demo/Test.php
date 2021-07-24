<?php


namespace fuyelk\wechat;
require __DIR__ . '/../src/Wechat.php';
require __DIR__ . '/../src/WechatException.php';

use fuyelk\wechat\WechatException;

try {
    $wechat = new Wechat([]);
} catch (WechatException $e) {
    var_dump($e->getMessage());
    exit();
}