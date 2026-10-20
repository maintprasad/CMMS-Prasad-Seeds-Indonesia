<?php
declare(strict_types=1);

/**
 * Satu-satunya entry point API:  api/index.php?r=<route>
 *   XAMPP : http://localhost/cmms/cmms/api/index.php?r=health
 *   Vercel: https://<project>.vercel.app/api/index.php?r=health
 * Rute dipetakan ke modul di api/modules/*.php.
 */

require __DIR__ . '/_lib/bootstrap.php';

const ROUTES = [
    // Auth & user
    'POST auth/login'           => ['UsersApi', 'login'],
    'POST auth/logout'          => ['UsersApi', 'logout'],
    'GET auth/me'               => ['UsersApi', 'me'],
    'POST auth/password'        => ['UsersApi', 'changePassword'],
    'GET users'                 => ['UsersApi', 'list'],
    'POST users/save'           => ['UsersApi', 'save'],
    'POST auth/profile'         => ['UsersApi', 'profile'],
    'POST auth/logout-all'      => ['UsersApi', 'logoutAll'],
    'GET technicians'           => ['UsersApi', 'technicians'],
    'POST technicians/save'     => ['UsersApi', 'saveTechnician'],
    'POST technicians/delete'   => ['UsersApi', 'deleteTechnician'],
    // Equipment Database
    'GET meta'                  => ['EquipmentApi', 'meta'],
    'GET equipment'             => ['EquipmentApi', 'list'],
    'GET equipment/get'         => ['EquipmentApi', 'get'],
    'GET equipment/history'     => ['EquipmentApi', 'history'],
    'GET equipment/parts-used'  => ['EquipmentApi', 'partsUsed'],
    'POST equipment/owners'     => ['EquipmentApi', 'setOwners'],
    'POST equipment/save'       => ['EquipmentApi', 'save'],
    'POST equipment/delete'     => ['EquipmentApi', 'delete'],
    'POST sparepart/save'       => ['EquipmentApi', 'saveSparepart'],
    'POST sparepart/delete'     => ['EquipmentApi', 'deleteSparepart'],
    'POST area/save'            => ['EquipmentApi', 'saveArea'],
    'POST area/delete'          => ['EquipmentApi', 'deleteArea'],
    'POST unit/save'            => ['EquipmentApi', 'saveUnit'],
    // MaintWare (dibaca WO)
    'GET mw/meta'               => ['MaintWareApi', 'meta'],
    'GET mw/items'              => ['MaintWareApi', 'items'],
    'GET mw/item'               => ['MaintWareApi', 'item'],
    'POST mw/item/save'         => ['MaintWareApi', 'saveItem'],
    'POST mw/item/delete'       => ['MaintWareApi', 'deleteItem'],
    'POST mw/stock'             => ['MaintWareApi', 'stock'],
    'GET mw/log'                => ['MaintWareApi', 'log'],
    'GET mw/dashboard'          => ['MaintWareApi', 'dashboard'],
    'GET mw/monthly'            => ['MaintWareApi', 'monthly'],
    'GET mw/reorder'            => ['MaintWareApi', 'reorder'],
    'GET mw/config'             => ['MaintWareApi', 'config'],
    'GET mw/ro'                 => ['MaintWareApi', 'roList'],
    'POST mw/ro/save'           => ['MaintWareApi', 'roSave'],
    'POST mw/ro/status'         => ['MaintWareApi', 'roStatus'],
    // Daily Monitoring (parameter diatur di Equipment Database)
    'GET dm/types'              => ['MonitoringApi', 'types'],
    'GET dm/templates'          => ['MonitoringApi', 'templates'],
    'POST dm/type/save'         => ['MonitoringApi', 'typeSave'],
    'POST dm/type/delete'       => ['MonitoringApi', 'typeDelete'],
    'POST dm/template/save'     => ['MonitoringApi', 'templateSave'],
    'POST dm/template/delete'   => ['MonitoringApi', 'templateDelete'],
    'POST dm/template/reorder'  => ['MonitoringApi', 'templateReorder'],
    'GET eqparam'               => ['MonitoringApi', 'equipmentParams'],
    'POST eqparam/save'         => ['MonitoringApi', 'paramSave'],
    'POST eqparam/delete'       => ['MonitoringApi', 'paramDelete'],
    'POST eqparam/reorder'      => ['MonitoringApi', 'paramReorder'],
    'POST eqparam/template'     => ['MonitoringApi', 'paramApplyTemplate'],
    'POST eqparam/copy'         => ['MonitoringApi', 'paramCopy'],
    'GET dm/board'              => ['MonitoringApi', 'board'],
    'GET dm/form'               => ['MonitoringApi', 'form'],
    'POST dm/save'              => ['MonitoringApi', 'save'],
    'POST dm/delete'            => ['MonitoringApi', 'delete'],
    'GET dm/history'            => ['MonitoringApi', 'history'],
    'GET dm/session'            => ['MonitoringApi', 'session'],
    'GET dm/findings'           => ['MonitoringApi', 'findings'],
    'POST dm/wo'                => ['MonitoringApi', 'createWo'],
    'GET dm/dashboard'          => ['MonitoringApi', 'dashboard'],
    // Preventive Maintenance
    'GET pm/types'              => ['PreventiveApi', 'types'],
    'GET pm/templates'          => ['PreventiveApi', 'templates'],
    'POST pm/template/save'     => ['PreventiveApi', 'templateSave'],
    'POST pm/type/save'         => ['PreventiveApi', 'typeSave'],
    'POST pm/type/delete'       => ['PreventiveApi', 'typeDelete'],
    'GET pm/schedules'          => ['PreventiveApi', 'scheduleList'],
    'POST pm/schedule/save'     => ['PreventiveApi', 'scheduleSave'],
    'POST pm/schedule/delete'   => ['PreventiveApi', 'scheduleDelete'],
    'POST pm/takeover'          => ['PreventiveApi', 'takeover'],
    'GET pm/takeovers'          => ['PreventiveApi', 'takeovers'],
    'GET pm/form'               => ['PreventiveApi', 'form'],
    'POST pm/report/save'       => ['PreventiveApi', 'reportSave'],
    'POST pm/report/delete'     => ['PreventiveApi', 'reportDelete'],
    'GET pm/reports'            => ['PreventiveApi', 'reports'],
    'GET pm/report'             => ['PreventiveApi', 'report'],
    'POST pm/sign'              => ['PreventiveApi', 'sign'],
    'POST pm/wo'                => ['PreventiveApi', 'createWo'],
    'GET pm/dashboard'          => ['PreventiveApi', 'dashboard'],
    'GET pm/resume'             => ['PreventiveApi', 'resume'],
    'GET pm/calendar'           => ['PreventiveApi', 'calendar'],
    // Pencarian global, notifikasi, pusat laporan
    'GET search'                => ['ReportApi', 'search'],
    'GET notifications'         => ['ReportApi', 'notifications'],
    'GET report/build'          => ['ReportApi', 'build'],
    // Work Order
    'GET wo'                    => ['WorkOrderApi', 'list'],
    'GET wo/get'                => ['WorkOrderApi', 'get'],
    'POST wo/create'            => ['WorkOrderApi', 'create'],
    'POST wo/action'            => ['WorkOrderApi', 'action'],
    'POST wo/note'              => ['WorkOrderApi', 'addNote'],
    'POST wo/part'              => ['WorkOrderApi', 'addPart'],
    'POST wo/part/delete'       => ['WorkOrderApi', 'removePart'],
    'POST wo/task'              => ['WorkOrderApi', 'addTask'],
    'POST wo/task/toggle'       => ['WorkOrderApi', 'toggleTask'],
    'POST wo/task/delete'       => ['WorkOrderApi', 'removeTask'],
    'POST wo/file'             => ['WorkOrderApi', 'uploadFile'],
    'GET wo/file'              => ['WorkOrderApi', 'downloadFile'],
    'POST wo/file/delete'      => ['WorkOrderApi', 'deleteFile'],
    'POST wo/history'           => ['WorkOrderApi', 'saveHistory'],
    'GET wo/history'            => ['WorkOrderApi', 'historyList'],
    'GET wo/stats'              => ['WorkOrderApi', 'stats'],
    'GET wo/comparison'         => ['WorkOrderApi', 'comparison'],
    'GET wo/mttr'               => ['WorkOrderApi', 'mttrReport'],
    'GET wo/technicians'        => ['WorkOrderApi', 'techReport'],
];

try {
    $route = trim((string) Http::query('r', ''), '/');
    $key = Http::method() . ' ' . $route;

    if ($key === 'GET health') {
        Auth::seedAdmin();
        Http::json(['ok' => true, 'driver' => Db::driverName(), 'time' => date('c')]);
    }
    if (!isset(ROUTES[$key])) {
        throw new HttpError('Rute tidak ditemukan: ' . $key, 404);
    }
    [$class, $method] = ROUTES[$key];
    Http::json(['ok' => true] + $class::$method());
} catch (HttpError $e) {
    Http::json(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[cmms] ' . $e);
    Http::json(['ok' => false, 'error' => 'Terjadi kesalahan server.'], 500);
}
