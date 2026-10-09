<?php
require_once __DIR__ . '/auth.php';
requireLogin('index.html');

$role = getUserRole();
if ($role === 'SYSTEM_ADMIN') {
    redirectTo('admin_dashboard.php');
}
if ($role === 'ADMIN') {
    redirectTo('admin_dashboard.php');
}
if ($role === 'CAPTAIN') {
    redirectTo('captain_dashboard.php');
}
if ($role === 'OFFICER') {
    redirectTo('officer_dashboard.php');
}
if ($role === 'RESIDENT') {
    redirectTo('resident_dashboard.php');
}

redirectTo('index.html');
