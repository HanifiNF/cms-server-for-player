<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Web\EntryController::index');
$routes->get('setup', 'Web\SetupController::index');
$routes->post('setup', 'Web\SetupController::create');
$routes->get('login', 'Web\AuthController::index');
$routes->post('login', 'Web\AuthController::login');
$routes->post('logout', 'Web\AuthController::logout', ['filter' => 'web-admin']);

$routes->group('control', ['filter' => 'web-admin'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Web\DashboardController::index');
    $routes->get('operators', 'Web\OperatorController::index');
    $routes->post('operators', 'Web\OperatorController::create');
    $routes->post('operators/(:num)/update', 'Web\OperatorController::update/$1');
    $routes->post('operators/(:num)/status', 'Web\OperatorController::status/$1');
    $routes->post('operators/(:num)/password', 'Web\OperatorController::password/$1');
    $routes->get('devices', 'Web\DeviceController::index');
    $routes->get('devices/(:segment)/assets', 'Web\DeviceController::assets/$1');
    $routes->post('devices', 'Web\DeviceController::create');
    $routes->post('devices/(:segment)/assignment', 'Web\DeviceController::assignment/$1');
    $routes->post('devices/(:segment)/revoke', 'Web\DeviceController::revoke/$1');
    $routes->post('devices/(:segment)/delete', 'Web\DeviceController::delete/$1');
    $routes->get('assets', 'Web\AssetController::index');
    $routes->post('assets/upload', 'Web\AssetController::upload');
    $routes->post('assets/(:segment)/assign', 'Web\AssetController::assign/$1');
    $routes->post('assets/(:segment)/unassign/(:segment)', 'Web\AssetController::unassign/$1/$2');
    $routes->post('assets/(:segment)/remove/(:segment)', 'Web\AssetController::unassignAndRemove/$1/$2');
    $routes->post('assets/(:segment)/delete', 'Web\AssetController::delete/$1');
    $routes->get('schedules', 'Web\ScheduleController::index');
    $routes->post('schedules', 'Web\ScheduleController::create');
    $routes->post('schedules/(:segment)/update', 'Web\ScheduleController::update/$1');
    $routes->post('schedules/(:segment)/status', 'Web\ScheduleController::status/$1');
    $routes->post('schedules/(:segment)/delete', 'Web\ScheduleController::delete/$1');
});
$routes->group('api', static function (RouteCollection $routes): void {
    $routes->get('health', 'Api\HealthController::index');
    $routes->post('auth/login', 'Api\AuthController::login');
    $routes->post('auth/logout', 'Api\AuthController::logout');
    $routes->get('auth/me', 'Api\AuthController::me');

    $routes->get('operator/devices/available', 'Api\Operator\DeviceController::available');
    $routes->post('operator/devices/(:segment)/control-access', 'Api\Operator\DeviceController::controlAccess/$1');
    $routes->post('operator/devices', 'Api\Operator\DeviceController::create');

    $routes->group('admin', ['filter' => 'admin-api'], static function (RouteCollection $routes): void {
        $routes->post('devices/enroll', 'Api\Admin\DeviceController::enroll');
        $routes->get('devices', 'Api\Admin\DeviceController::index');
        $routes->get('devices/(:segment)', 'Api\Admin\DeviceController::show/$1');
    });

    $routes->post('player/register', 'Api\Player\RegistrationController::register');
    $routes->post('player/claim', 'Api\Player\RegistrationController::claim');
    $routes->post('player/heartbeat', 'Api\Player\HeartbeatController::create');
    $routes->post('player/assets/sync', 'Api\Player\AssetController::sync');
    $routes->get('player/assets/assigned', 'Api\Player\AssetController::assigned');
    $routes->get('player/assets/removals', 'Api\Player\AssetController::removals');
    $routes->post('player/assets/(:segment)/removed', 'Api\Player\AssetController::removed/$1');
    $routes->get('player/assets/(:segment)/download', 'Api\Player\AssetController::download/$1');
    $routes->get('player/schedules', 'Api\Player\ScheduleController::index');
});
