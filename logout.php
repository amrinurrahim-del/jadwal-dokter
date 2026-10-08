<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

auth_logout();
redirect('login.php?keluar=1');
