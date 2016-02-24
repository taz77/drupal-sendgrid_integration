#!/bin/bash
# Simple script to install drupal for travis-ci running.

set -e $DRUPAL_TI_DEBUG

# Ensure the right Drupal version is installed.
echo "Ensure the right Drupal Version."
drupal_ti_ensure_drupal

# Enable simpletest module.
cd "$DRUPAL_TI_DRUPAL_DIR"
echo "Drush Enable Simpletest module"
drush --yes en simpletest

# Ensure the module is linked into the code base and enabled.
echo "Ensure the module is linked into the code base and enabled"
drupal_ti_ensure_module

# Clear caches and run a web server.
drupal_ti_clear_caches
drupal_ti_run_server
