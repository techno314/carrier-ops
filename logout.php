<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

fc_end_session();
fc_redirect(fc_url('login.php'));
