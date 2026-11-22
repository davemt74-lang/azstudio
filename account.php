<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
az_require_auth();
az_redirect('user.php');
