<?php

if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    require __DIR__ . '/Process.php5';
} else {
    require __DIR__ . '/Process.php7';
}
