<?php
/**
 * Security Configuration for Replanta Care
 *
 * Copy config-sample.php to config.php and set secure values.
 */

if (!defined('ABSPATH')) {
    exit;
}

// GitHub Token for private repository auto-updates.
if (!defined('RPCARE_GITHUB_TOKEN')) {
    define('RPCARE_GITHUB_TOKEN', '');
}

// Optional override for update repository URL and branch.
if (!defined('RPCARE_GITHUB_REPO_URL')) {
    define('RPCARE_GITHUB_REPO_URL', 'https://github.com/replantadev/care/');
}

if (!defined('RPCARE_GITHUB_BRANCH')) {
    define('RPCARE_GITHUB_BRANCH', 'main');
}

// Enable verbose debug behavior for development.
if (!defined('RPCARE_DEBUG')) {
    define('RPCARE_DEBUG', false);
}
