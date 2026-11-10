<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

// Active BPLO API routes. Legacy student/product demo routes have been removed.
$router->match('/api/bplo/auth/{action}', 'BploAuthController::auth', 'POST|GET|OPTIONS');
$router->match('/api/bplo/profile', 'BploAuthController::profile', 'PUT|OPTIONS');
$router->match('/api/bplo/applications', 'BploApplicationsController::applications', 'GET|POST|OPTIONS');
$router->match('/api/bplo/applications/{ref}', 'BploApplicationsController::application', 'GET|PUT|DELETE|OPTIONS');
$router->match('/api/bplo/applications/{ref}/status', 'BploApplicationsController::status', 'PUT|OPTIONS');
$router->match('/api/bplo/applications/{ref}/inspection', 'BploApplicationsController::inspection', 'PUT|OPTIONS');
$router->match('/api/bplo/applications/{ref}/uploads', 'BploDocumentsController::uploads', 'POST|OPTIONS');
$router->match('/api/bplo/applications/{ref}/documents', 'BploDocumentsController::documents', 'PUT|OPTIONS');
$router->match('/api/bplo/applications/{ref}/document-metadata', 'BploDocumentsController::metadata', 'PUT|OPTIONS');
$router->match('/api/bplo/applications/{ref}/documents/{id}/{action}', 'BploDocumentsController::documentFile', 'GET|DELETE|OPTIONS');
$router->match('/api/bplo/applicants', 'BploApplicantsController::applicants', 'GET|POST|OPTIONS');
$router->match('/api/bplo/applicants/{id}', 'BploApplicantsController::applicants', 'PUT|DELETE|OPTIONS');
$router->match('/api/bplo/notifications', 'BploNotificationsController::notifications', 'GET|OPTIONS');
$router->match('/api/bplo/notifications/read-all', 'BploNotificationsController::readAll', 'PUT|OPTIONS');
$router->match('/api/bplo/notifications/{id}/read', 'BploNotificationsController::notifications', 'PUT|OPTIONS');
$router->match('/api/bplo/audit-logs', 'BploAdminController::auditLogs', 'GET|OPTIONS');
$router->match('/api/bplo/settings', 'BploAdminController::settings', 'GET|PUT|OPTIONS');
$router->match('/api/bplo/payments','BploPaymentsController::index', 'GET|OPTIONS');
$router->match('/api/bplo/applications/{ref}/payment', 'BploPaymentsController::application', 'GET|OPTIONS');
$router->match('/api/bplo/applications/{ref}/payment/assessment', 'BploPaymentsController::assessment', 'PUT|OPTIONS');
$router->match('/api/bplo/applications/{ref}/payment/notice', 'BploPaymentsController::resendNotice', 'POST|OPTIONS');
$router->match('/api/bplo/applications/{ref}/payment/submissions', 'BploPaymentsController::submit', 'POST|OPTIONS');
$router->match('/api/bplo/applications/{ref}/payment/submissions/{id}/review', 'BploPaymentsController::review', 'PUT|OPTIONS');
$router->match('/api/bplo/applications/{ref}/payment/submissions/{id}/proof', 'BploPaymentsController::proof', 'GET|OPTIONS');

