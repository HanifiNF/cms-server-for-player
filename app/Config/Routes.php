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
$routes->post('logout', 'Web\AuthController::logout', ['filter' => 'web-auth']);

$routes->group('control', ['filter' => 'web-admin'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Web\DashboardController::index');
    $routes->get('operators', 'Web\OperatorController::index');
    $routes->get('operators/collection', 'Web\OperatorController::collection');
    $routes->post('operators', 'Web\OperatorController::create');
    $routes->post('operators/(:num)/update', 'Web\OperatorController::update/$1');
    $routes->post('operators/(:num)/status', 'Web\OperatorController::status/$1');
    $routes->post('operators/(:num)/password', 'Web\OperatorController::password/$1');
    $routes->get('locations', 'Web\LocationController::index');
    $routes->get('locations/collection', 'Web\LocationController::collection');
    $routes->post('locations', 'Web\LocationController::create');
    $routes->post('locations/(:segment)/update', 'Web\LocationController::update/$1');
    $routes->post('locations/(:segment)/status', 'Web\LocationController::status/$1');
    $routes->post('locations/(:segment)/delete', 'Web\LocationController::delete/$1');
    $routes->get('locations/(:segment)', 'Web\LocationController::show/$1');
    $routes->get('locations/(:segment)/studios/collection', 'Web\LocationController::studioCollection/$1');
    $routes->get('locations/(:segment)/assets/collection', 'Web\LocationController::assetAssignmentCollection/$1');
    $routes->post('locations/(:segment)/studios', 'Web\LocationController::createStudio/$1');
    $routes->post('locations/(:segment)/studios/(:segment)/details', 'Web\LocationController::updateStudio/$1/$2');
    $routes->post('locations/(:segment)/studios/(:segment)/assignment', 'Web\LocationController::assignStudio/$1/$2');
    $routes->post('locations/(:segment)/studios/(:segment)/operators', 'Web\LocationController::createOperator/$1/$2');
    $routes->post('locations/(:segment)/studios/(:segment)/assets', 'Web\LocationController::assignAssets/$1/$2');
    $routes->post('locations/(:segment)/studios/(:segment)/reset-pairing', 'Web\LocationController::resetStudioPairing/$1/$2');
    $routes->post('locations/(:segment)/studios/(:segment)/revoke', 'Web\LocationController::revokeStudio/$1/$2');
    $routes->post('locations/(:segment)/studios/(:segment)/delete', 'Web\LocationController::deleteStudio/$1/$2');
    $routes->get('devices', 'Web\DeviceController::index');
    $routes->get('devices/(:segment)/assets', 'Web\DeviceController::assets/$1');
    $routes->get('devices/(:segment)/assets/collection', 'Web\DeviceController::assetCollection/$1');
    $routes->post('devices', 'Web\DeviceController::create');
    $routes->post('devices/(:segment)/details', 'Web\DeviceController::details/$1');
    $routes->post('devices/(:segment)/assignment', 'Web\DeviceController::assignment/$1');
    $routes->post('devices/(:segment)/revoke', 'Web\DeviceController::revoke/$1');
    $routes->post('devices/(:segment)/delete', 'Web\DeviceController::delete/$1');
    $routes->post('assets/(:segment)/assign', 'Web\AssetController::assign/$1');
    $routes->post('assets/(:segment)/assign-selection', 'Web\AssetController::assignSelection/$1');
    $routes->post('assets/(:segment)/assign-global', 'Web\AssetController::assignGlobal/$1');
    $routes->post('assets/(:segment)/unassign-selection', 'Web\AssetController::unassignSelection/$1');
    $routes->post('assets/(:segment)/unassign-global', 'Web\AssetController::unassignGlobal/$1');
    $routes->post('assets/(:segment)/unassign/(:segment)', 'Web\AssetController::unassign/$1/$2');
    $routes->post('assets/(:segment)/remove/(:segment)', 'Web\AssetController::unassignAndRemove/$1/$2');
    $routes->post('assets/(:segment)/delete', 'Web\AssetController::delete/$1');
    $routes->post('assets/(:segment)/approve', 'Web\AssetController::approve/$1');
    $routes->post('assets/(:segment)/reject', 'Web\AssetController::reject/$1');
    $routes->post('genres', 'Web\AssetController::createGenre');
    $routes->post('genres/(:segment)/status', 'Web\AssetController::genreStatus/$1');
    $routes->get('storage', 'Web\StorageController::index');
    $routes->post('storage', 'Web\StorageController::create');
    $routes->post('storage/(:segment)/default', 'Web\StorageController::makeDefault/$1');
    $routes->post('storage/(:segment)/status', 'Web\StorageController::status/$1');
    $routes->post('storage/(:segment)/test', 'Web\StorageController::test/$1');
    $routes->post('storage/(:segment)/update', 'Web\StorageController::update/$1');
    $routes->post('storage/(:segment)/delete', 'Web\StorageController::delete/$1');
    $routes->get('schedules', 'Web\ScheduleController::index');
    $routes->get('schedules/collection', 'Web\ScheduleController::collection');
    $routes->get('schedules/bulk-collection', 'Web\ScheduleController::bulkCollection');
    $routes->post('schedules', 'Web\ScheduleController::create');
    $routes->post('schedules/(:segment)/update', 'Web\ScheduleController::update/$1');
    $routes->post('schedules/(:segment)/status', 'Web\ScheduleController::status/$1');
    $routes->post('schedules/(:segment)/delete', 'Web\ScheduleController::delete/$1');
    $routes->post('schedules/bulk-disable', 'Web\ScheduleController::bulkDisable');
    $routes->post('schedules/bulk-delete', 'Web\ScheduleController::bulkDelete');
});
$routes->group('control', ['filter' => 'web-assets'], static function (RouteCollection $routes): void {
    $routes->get('assets', 'Web\AssetController::index');
    $routes->get('library', 'Web\MediaLibraryController::index');
    $routes->get('library/collection', 'Web\MediaLibraryController::collection');
    $routes->get('library/(:segment)', 'Web\MediaLibraryController::show/$1');
    $routes->get('library/(:segment)/assignments/collection', 'Web\MediaLibraryController::assignmentCollection/$1');
    $routes->get('library/(:segment)/versions/collection', 'Web\MediaLibraryController::versionCollection/$1');
    $routes->get('library/(:segment)/schedules/collection', 'Web\MediaLibraryController::scheduleCollection/$1');
    $routes->post('assets/upload', 'Web\AssetController::upload');
    $routes->get('assets/(:segment)/poster', 'Web\AssetController::poster/$1');
    $routes->post('assets/(:segment)/metadata', 'Web\AssetController::updateMetadata/$1');
    $routes->post('assets/(:segment)/resubmit', 'Web\AssetController::resubmit/$1');
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
