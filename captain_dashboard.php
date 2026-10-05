<?php
require_once __DIR__ . '/auth.php';
requireRole(['CAPTAIN']);
readfile(__DIR__ . '/dashboard.html');
