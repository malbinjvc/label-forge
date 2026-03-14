# LabelForge - Error Log

## Error 1: Composer Not in PATH
- **Issue**: Composer binary was not found in the system PATH after installation
- **Details**: Composer was installed to `/tmp/composer` which is not a standard PATH location
- **Resolution**: Used the full path `/tmp/composer` to run Composer commands, or copied the binary to a PATH-accessible location

## Error 2: PSR-4 Autoloading Failure (DatasetService Class Not Found)
- **Issue**: `Class "LabelForge\DatasetService" not found` error at runtime
- **Cause**: All service classes (DatasetService, LabelService, AgreementService, ExportService, StatsService) were defined in a single file `src/Services.php`. PSR-4 autoloading expects one class per file with the filename matching the class name (e.g., `DatasetService.php` for `DatasetService`)
- **Resolution**: Switched from PSR-4 autoloading to `classmap` autoloading in `composer.json`. The classmap approach scans all files in `src/` and registers every class it finds, regardless of filename:
  ```json
  "autoload": {
      "classmap": ["src/"]
  }
  ```
- **Note**: After changing the autoload strategy, `composer dump-autoload` was run to regenerate the autoloader

## Error 3: PHPUnit Deprecation Warnings
- **Issue**: 2 deprecation warnings emitted during PHPUnit test execution
- **Impact**: Non-blocking -- all tests passed successfully despite the warnings
- **Details**: These are typically related to PHPUnit 11.x deprecating older assertion patterns or test configuration options. The warnings do not affect test results or application functionality
