<?php

declare(strict_types=1);

use Verdant\Storefront\SectionRegistry;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

/** Approved synthetic editorial defaults; references remain unset until owners choose existing records. */
return SectionRegistry::defaults();
