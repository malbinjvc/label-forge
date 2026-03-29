# LabelForge - Error Log

## Error 1: Composer not in system PATH
- **When**: Attempting `composer install`
- **Error**: `command not found: composer`
- **Cause**: Composer not installed globally. Installation to /usr/local/bin requires elevated privileges.
- **Fix**: Downloaded Composer installer to /tmp/composer via curl

## Error 2: PSR-4 autoloading - DatasetService class not found
- **When**: Running `vendor/bin/phpunit tests/`
- **Error**: `Error: Class "LabelForge\DatasetService" not found` (all 41 tests errored)
- **Cause**: PSR-4 autoloading expects one class per file, but all service classes were in Services.php.
- **Fix**: Changed from PSR-4 to classmap autoloading in composer.json, then ran composer dump-autoload.

## Error 3: PHPUnit deprecation warnings (non-blocking)
- **When**: Running tests after autoload fix
- **Result**: OK, but there were issues! Tests: 41, Assertions: 104, Deprecations: 2
- **Impact**: Non-blocking - all 41 tests pass
