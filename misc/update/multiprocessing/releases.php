<?php

declare(ticks=1);
require_once dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

use nntmux\libraries\Forking;

(new Forking())->processWorkType('releases');
