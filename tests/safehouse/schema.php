<?php
declare(strict_types=1);
require __DIR__.'/../../src/Civ13/Safehouse/Client.php';
require __DIR__.'/../../src/Civ13/Safehouse/Controller.php';
echo json_encode(\Civ13\Safehouse\Controller::schema(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
