#!/usr/bin/env bash
#
# Install WordPress test framework for Form Runtime Engine plugin.
#
# Usage:
#   ./bin/install-wp-tests.sh [db_name] [db_user] [db_pass] [db_host] [wp_version] [--force]
#
# Defaults are configured for Local by Flywheel.
# Use --force to skip interactive prompts and reinstall everything.
#

set -e

# Check for --force flag
FORCE=false
for arg in "$@"; do
    if [ "$arg" == "--force" ]; then
        FORCE=true
    fi
done

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

print_status() {
    echo -e "${GREEN}[*]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_error() {
    echo -e "${RED}[x]${NC} $1"
}

# Get the script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_DIR="$( dirname "$SCRIPT_DIR" )"

download() {
    if [ `which curl` ]; then
        curl -s "$1" > "$2";
    elif [ `which wget` ]; then
        wget -nv -O "$2" "$1"
    fi
}

get_latest_wp_version() {
    download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
    grep -o '"version":"[^"]*"' /tmp/wp-latest.json | head -1 | sed 's/"version":"//;s/"//'
}

if [ "$WP_VERSION" == "latest" ]; then
    print_status "Fetching latest WordPress version..."
    WP_VERSION=$(get_latest_wp_version)
fi

print_status "WordPress Test Framework Installer"
echo "========================================"
echo "WP Version:     $WP_VERSION"
echo "WP Tests Dir:   $WP_TESTS_DIR"
echo "WP Core Dir:    $WP_CORE_DIR"
echo "Database:       $DB_NAME"
echo "DB User:        $DB_USER"
echo "DB Host:        $DB_HOST"
echo ""

# Install WordPress Test Library
install_wp_tests() {
    print_status "Installing WordPress test library..."

    # Set up testing directory
    if [ -d "$WP_TESTS_DIR" ]; then
        if [ "$FORCE" = true ]; then
            print_status "Force flag set, removing existing installation..."
            rm -rf "$WP_TESTS_DIR"
        else
            print_warning "Test library already exists at $WP_TESTS_DIR"
            read -p "Remove and reinstall? [y/N] " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                rm -rf "$WP_TESTS_DIR"
            else
                print_status "Skipping download, using existing installation."
                return
            fi
        fi
    fi

    mkdir -p "$WP_TESTS_DIR"

    # Determine SVN URL based on version
    if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
        WP_TESTS_TAG="branches/$WP_VERSION"
    elif [[ "$WP_VERSION" =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
        if [[ "$WP_VERSION" =~ [0-9]+\.[0-9]+\.[0] ]]; then
            # Version like 6.4.0 should use the branch
            WP_TESTS_TAG="branches/${WP_VERSION%??}"
        else
            WP_TESTS_TAG="tags/$WP_VERSION"
        fi
    elif [ "$WP_VERSION" == "trunk" ]; then
        WP_TESTS_TAG="trunk"
    else
        WP_TESTS_TAG="branches/$WP_VERSION"
    fi

    print_status "Downloading test library from SVN (tag: $WP_TESTS_TAG)..."

    # Check if svn is available
    if ! command -v svn &> /dev/null; then
        print_error "SVN is not installed. Installing via Homebrew..."
        if command -v brew &> /dev/null; then
            brew install svn
        else
            print_error "Please install SVN: brew install svn"
            exit 1
        fi
    fi

    svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

    print_status "Test library installed successfully!"
}

# Install WordPress Core (for integration tests)
install_wp_core() {
    print_status "Installing WordPress core..."

    if [ -d "$WP_CORE_DIR" ]; then
        if [ "$FORCE" = true ]; then
            print_status "Force flag set, removing existing WordPress core..."
            rm -rf "$WP_CORE_DIR"
        else
            print_warning "WordPress core already exists at $WP_CORE_DIR"
            read -p "Remove and reinstall? [y/N] " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                rm -rf "$WP_CORE_DIR"
            else
                print_status "Skipping download, using existing installation."
                return
            fi
        fi
    fi

    mkdir -p "$WP_CORE_DIR"

    # Download WordPress
    if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
        download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" /tmp/wordpress.tar.gz
    else
        download "https://wordpress.org/wordpress-latest.tar.gz" /tmp/wordpress.tar.gz
    fi

    tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"

    print_status "WordPress core installed successfully!"
}

# Create wp-tests-config.php
create_config() {
    print_status "Creating wp-tests-config.php..."

    # Download sample config if it doesn't exist
    if [ ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]; then
        download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
    fi

    cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
/**
 * WordPress Test Configuration
 *
 * Generated by Form Runtime Engine install script.
 * Created: $(date)
 */

// Path to WordPress codebase
define( 'ABSPATH', '$WP_CORE_DIR/' );

// Test database settings
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// Test-specific settings
\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Form Runtime Engine Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// Debug settings
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
EOF

    print_status "Config file created at $WP_TESTS_DIR/wp-tests-config.php"
}

# Create test database
create_database() {
    print_status "Creating test database '$DB_NAME'..."

    # Try to find Local by Flywheel MySQL binary
    LOCAL_MYSQL_BIN=$(find "/Applications/Local.app" -name "mysql" -type f 2>/dev/null | grep bin | head -1)

    # Check for Local by Flywheel MySQL socket
    LOCAL_MYSQL_SOCKET="$HOME/Library/Application Support/Local/run/*/mysql/mysqld.sock"
    SOCKET_PATH=$(ls $LOCAL_MYSQL_SOCKET 2>/dev/null | head -1)

    if [ -n "$LOCAL_MYSQL_BIN" ] && [ -n "$SOCKET_PATH" ]; then
        print_status "Found Local MySQL binary: $LOCAL_MYSQL_BIN"
        print_status "Found Local MySQL socket: $SOCKET_PATH"
        MYSQL_CMD="'$LOCAL_MYSQL_BIN' --socket='$SOCKET_PATH' -u$DB_USER"
        if [ -n "$DB_PASS" ] && [ "$DB_PASS" != "" ]; then
            MYSQL_CMD="$MYSQL_CMD -p$DB_PASS"
        fi
    elif command -v mysql &> /dev/null; then
        # Standard MySQL connection
        MYSQL_CMD="mysql -h$DB_HOST -u$DB_USER"
        if [ -n "$DB_PASS" ] && [ "$DB_PASS" != "" ]; then
            MYSQL_CMD="$MYSQL_CMD -p$DB_PASS"
        fi
    else
        print_error "MySQL client not found."
        print_warning "You may need to create the database manually using Local by Flywheel's Site Shell."
        echo ""
        echo "Open Local by Flywheel → Right-click your site → Open Site Shell → Run:"
        echo ""
        echo "  mysql -uroot -proot -e 'CREATE DATABASE IF NOT EXISTS $DB_NAME;'"
        echo ""
        return 1
    fi

    # Create database
    eval "$MYSQL_CMD -e 'CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;'" 2>/dev/null || {
        print_error "Failed to create database."
        print_warning "You may need to create the database manually using Local by Flywheel's Site Shell."
        echo ""
        echo "Open Local by Flywheel → Right-click your site → Open Site Shell → Run:"
        echo ""
        echo "  mysql -uroot -proot -e 'CREATE DATABASE IF NOT EXISTS $DB_NAME;'"
        echo ""
        return 1
    }

    print_status "Database '$DB_NAME' created successfully!"
}

# Main installation process
main() {
    echo ""
    print_status "Starting WordPress test framework installation..."
    echo ""

    install_wp_tests
    install_wp_core
    create_config
    create_database

    echo ""
    echo "========================================"
    print_status "Installation complete!"
    echo ""
    echo "To run integration tests:"
    echo ""
    echo "  cd $PLUGIN_DIR"
    echo "  WP_TESTS_DIR=$WP_TESTS_DIR ./vendor/bin/phpunit --testsuite Integration"
    echo ""
    echo "Or using composer:"
    echo ""
    echo "  composer test:integration"
    echo ""
    echo "========================================"
}

# Run main installation
main
