<?php
require_once __DIR__ . '/../auth.php';

if (func_num_args() > 0) {
    $roles = func_get_args();
    requireRole($roles);
}
