<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['file_list'][] = dirname( __DIR__ ) . '/index.php';

return $cfg;
