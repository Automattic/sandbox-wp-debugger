#!/bin/bash

# Fix PHP 8.4 compatibility issues in WordPress Coding Standards
# Issues:
# 1. trim() no longer accepts null values in PHP 8.4
# 2. Implicit nullable parameters are deprecated in PHP 8.4

set -e

echo "🔧 Fixing PHP 8.4 compatibility issues in WordPress Coding Standards..."

# Fix 1: PrefixAllGlobalsSniff.php - trim() with null values
PHPCS_FILE1="vendor/wp-coding-standards/wpcs/WordPress/Sniffs/NamingConventions/PrefixAllGlobalsSniff.php"

if [[ -f "$PHPCS_FILE1" ]]; then
    echo "📝 Patching $PHPCS_FILE1..."
    
    # Replace the problematic trim() call with null-safe version
    sed -i.bak 's/\$cl_prefixes = trim( PHPCSHelper::get_config_data( '\''prefixes'\'' ) );/$cl_prefixes = trim( PHPCSHelper::get_config_data( '\''prefixes'\'' ) ?? '\'''\'' );/' "$PHPCS_FILE1"
    
    if grep -q "trim( PHPCSHelper::get_config_data( 'prefixes' ) ?? '' )" "$PHPCS_FILE1"; then
        echo "✅ Successfully patched $PHPCS_FILE1"
    else
        echo "⚠️  Could not verify patch for $PHPCS_FILE1"
    fi
else
    echo "⚠️  File $PHPCS_FILE1 not found, skipping..."
fi

# Fix 2: I18nSniff.php - trim() with null values
PHPCS_FILE2="vendor/wp-coding-standards/wpcs/WordPress/Sniffs/WP/I18nSniff.php"

if [[ -f "$PHPCS_FILE2" ]]; then
    echo "📝 Patching $PHPCS_FILE2..."
    
    # Fix line 194 - trim() call that can receive null
    sed -i.bak 's/\$cl_text_domain = trim( PHPCSHelper::get_config_data( '\''text_domain'\'' ) );/$cl_text_domain = trim( PHPCSHelper::get_config_data( '\''text_domain'\'' ) ?? '\'''\'' );/' "$PHPCS_FILE2"
    
    if grep -q "trim( PHPCSHelper::get_config_data( 'text_domain' ) ?? '' )" "$PHPCS_FILE2"; then
        echo "✅ Successfully patched $PHPCS_FILE2"
    else
        echo "⚠️  Could not verify patch for $PHPCS_FILE2"
    fi
else
    echo "⚠️  File $PHPCS_FILE2 not found, skipping..."
fi

# Fix 3: PHPCSHelper.php - implicit nullable parameter
PHPCS_FILE3="vendor/wp-coding-standards/wpcs/WordPress/PHPCSHelper.php"

if [[ -f "$PHPCS_FILE3" ]]; then
    echo "📝 Patching $PHPCS_FILE3..."
    
    # Fix line 98 - implicit nullable parameter
    sed -i.bak 's/public static function ignore_annotations( File \$phpcsFile = null )/public static function ignore_annotations( ?File $phpcsFile = null )/' "$PHPCS_FILE3"
    
    if grep -q "ignore_annotations( ?File \$phpcsFile = null )" "$PHPCS_FILE3"; then
        echo "✅ Successfully patched $PHPCS_FILE3"
    else
        echo "⚠️  Could not verify patch for $PHPCS_FILE3"
    fi
else
    echo "⚠️  File $PHPCS_FILE3 not found, skipping..."
fi

echo ""
echo "🎉 All PHP 8.4 compatibility patches applied!"
echo "📋 Summary:"
echo "   - Fixed trim() null value issues in PrefixAllGlobalsSniff.php and I18nSniff.php"
echo "   - Fixed implicit nullable parameter in PHPCSHelper.php"
echo ""
echo "ℹ️  Backup files (.bak) were created for each patched file"
echo "ℹ️  Run this script again after each 'composer install' to reapply patches"