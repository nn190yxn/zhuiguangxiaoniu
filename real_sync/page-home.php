<?php
declare(strict_types=1);

// Legacy WordPress marketing home template retired in favor of static index.html.
wp_safe_redirect(home_url('/index.html'), 301);
exit;
