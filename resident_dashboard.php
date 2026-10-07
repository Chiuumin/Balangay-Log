<?php
require_once __DIR__ . '/auth.php';
requireRole(['RESIDENT']);
readfile(__DIR__ . '/dashboard.html');
