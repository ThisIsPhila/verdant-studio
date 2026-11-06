<?php
$visionPage = true;
$visionComponentStyles = ['discovery'];
require_once __DIR__ . '/components/_helpers.php';
$isHomes = isset($collection['collection_code']) && $collection['collection_code'] === 'homes';
if ($isHomes) {
    require __DIR__ . '/vision/homes.php';
    return;
}
if ($collection === null) {
    require __DIR__ . '/vision/collection-directory.php';
    return;
}
require __DIR__ . '/vision/collection-detail.php';
