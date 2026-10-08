<?php
require_once __DIR__ . '/auth.php';
include __DIR__ . '/views/settings_partial.php';
requireRole(['RESIDENT']);
readfile(__DIR__ . '/dashboard.html');
