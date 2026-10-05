<?php
require_once __DIR__ . '/auth.php';
requireRole(['OFFICER']);
readfile(__DIR__ . '/dashboard.html');
