<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Router.php';

$router = new Router();
$router->dispatch();
