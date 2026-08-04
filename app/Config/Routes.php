<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->group('api', static function (RouteCollection $routes): void {
    $routes->get('health', 'Api\HealthController::index');

    $routes->group('admin', ['filter' => 'admin-api'], static function (RouteCollection $routes): void {
        $routes->post('devices/enroll', 'Api\Admin\DeviceController::enroll');
        $routes->get('devices', 'Api\Admin\DeviceController::index');
        $routes->get('devices/(:segment)', 'Api\Admin\DeviceController::show/$1');
    });

    $routes->post('player/register', 'Api\Player\RegistrationController::register');
    $routes->post('player/heartbeat', 'Api\Player\HeartbeatController::create');
    $routes->post('player/unregister', 'Api\Player\RegistrationController::unregister');
});
