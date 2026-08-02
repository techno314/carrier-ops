<?php

declare(strict_types=1);

require __DIR__ . '/lib/core.php';

fc_end_session();
fc_redirect(fc_url('login.php'));
