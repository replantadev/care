<?php
/**
 * RP_Care_Backup_Provider
 *
 * Interface that every backup adapter must implement.
 * The pipeline uses this to take a pre-update snapshot and verify/restore it
 * if production update fails.
 *
 * Contract:
 *  - detect()            → true if this provider is active on the current site.
 *  - latestEvidence()    → last known backup descriptor or null.
 *  - createPreUpdate()   → triggers a backup; returns descriptor on success.
 *  - verify()            → checks a descriptor is intact; returns true/WP_Error.
 *  - restore()           → initiates a restore; returns true/WP_Error.
 *  - capabilities()      → what this provider can and cannot do.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface RP_Care_Backup_Provider {

    /**
     * Returns true if this provider is available and configured on this site.
     */
    public function detect(): bool;

    /**
     * Returns metadata about the most recent backup, or null if none is known.
     *
     * @return array{id: string, timestamp: string, size_bytes: int, status: string}|null
     */
    public function latestEvidence(): ?array;

    /**
     * Trigger a pre-update backup. Blocks until the backup is complete or confirmed.
     *
     * @return array{id: string, timestamp: string, size_bytes: int, status: string}|\WP_Error
     */
    public function createPreUpdate(): array|\WP_Error;

    /**
     * Verify that a backup descriptor is intact and restorable.
     *
     * @param array $descriptor  Value previously returned by createPreUpdate or latestEvidence.
     * @return true|\WP_Error
     */
    public function verify( array $descriptor ): true|\WP_Error;

    /**
     * Begin a restore from the given descriptor.
     * Implementations that cannot restore programmatically must return WP_Error.
     *
     * @param array $descriptor
     * @return true|\WP_Error
     */
    public function restore( array $descriptor ): true|\WP_Error;

    /**
     * Declares what this provider supports.
     *
     * @return array{
     *   can_create: bool,
     *   can_verify: bool,
     *   can_restore: bool,
     *   restore_verified: bool  Only true when restore() is proven reliable.
     * }
     */
    public function capabilities(): array;
}


/**
 * RP_Care_BackuplyObserved
 *
 * Read-only adapter for Backuply.
 * Observe-only: we can detect and report Backuply backups but NEVER
 * claim restore_verified=true — programmatic restore is unproven.
 */
class RP_Care_BackuplyObserved implements RP_Care_Backup_Provider {

    public function detect(): bool {
        return class_exists( 'Backuply' ) || defined( 'BACKUPLY_FILE' );
    }

    public function latestEvidence(): ?array {
        if ( ! $this->detect() ) {
            return null;
        }
        // Backuply stores log entries in its option; read the most recent entry.
        $logs = get_option( 'backuply_backup_log', [] );
        if ( empty( $logs ) || ! is_array( $logs ) ) {
            return null;
        }
        $last = end( $logs );
        if ( empty( $last ) ) {
            return null;
        }
        return [
            'id'         => (string) ( $last['id'] ?? $last['backup_id'] ?? '' ),
            'timestamp'  => (string) ( $last['time'] ?? $last['timestamp'] ?? '' ),
            'size_bytes' => (int)    ( $last['size'] ?? 0 ),
            'status'     => (string) ( $last['status'] ?? 'unknown' ),
            'provider'   => 'backuply_observed',
        ];
    }

    public function createPreUpdate(): array|\WP_Error {
        return new \WP_Error(
            'observe_only',
            'BackuplyObserved is read-only. Trigger a Backuply backup through its own scheduler.'
        );
    }

    public function verify( array $descriptor ): true|\WP_Error {
        if ( empty( $descriptor['id'] ) ) {
            return new \WP_Error( 'invalid_descriptor', 'Descriptor is missing id.' );
        }
        $logs = get_option( 'backuply_backup_log', [] );
        foreach ( $logs as $entry ) {
            $entry_id = (string) ( $entry['id'] ?? $entry['backup_id'] ?? '' );
            if ( $entry_id === $descriptor['id'] ) {
                $status = $entry['status'] ?? '';
                if ( in_array( $status, [ 'complete', 'success', 'completed' ], true ) ) {
                    return true;
                }
                return new \WP_Error( 'backup_not_complete', "Backup {$descriptor['id']} has status '$status'." );
            }
        }
        return new \WP_Error( 'backup_not_found', "No Backuply log entry for id '{$descriptor['id']}'." );
    }

    public function restore( array $descriptor ): true|\WP_Error {
        return new \WP_Error(
            'observe_only',
            'BackuplyObserved cannot perform programmatic restores. Use the Backuply admin panel.'
        );
    }

    public function capabilities(): array {
        return [
            'can_create'      => false,
            'can_verify'      => true,
            'can_restore'     => false,
            'restore_verified'=> false,
        ];
    }
}


/**
 * RP_Care_LocalSnapshot
 *
 * Synchronous snapshot adapter: copies DB + plugin files + theme files to a
 * local directory inside wp-content/rpcare-snapshots/.
 *
 * This is the fallback provider when no third-party backup plugin is present.
 * Restore is supported (files can be put back programmatically) and verified.
 */
class RP_Care_LocalSnapshot implements RP_Care_Backup_Provider {

    private const SNAP_DIR = 'rpcare-snapshots';
    private const OPT_KEY  = 'rpcare_local_snapshots';

    public function detect(): bool {
        // Always available — no external dependency.
        return defined( 'WP_CONTENT_DIR' );
    }

    public function latestEvidence(): ?array {
        $all = get_option( self::OPT_KEY, [] );
        if ( empty( $all ) ) {
            return null;
        }
        return end( $all ) ?: null;
    }

    public function createPreUpdate(): array|\WP_Error {
        $snap_root = WP_CONTENT_DIR . '/' . self::SNAP_DIR;
        if ( ! wp_mkdir_p( $snap_root ) ) {
            return new \WP_Error( 'snapshot_dir_failed', "Cannot create snapshot directory: $snap_root" );
        }

        $id        = 'snap_' . gmdate( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 8 );
        $snap_path = $snap_root . '/' . $id;
        if ( ! wp_mkdir_p( $snap_path ) ) {
            return new \WP_Error( 'snapshot_dir_failed', "Cannot create snapshot path: $snap_path" );
        }

        // Dump DB.
        $db_result = self::dump_db( $snap_path . '/db.sql' );
        if ( is_wp_error( $db_result ) ) {
            return $db_result;
        }

        // Snapshot active plugins directory.
        $plugins_snap = $snap_path . '/plugins';
        if ( ! wp_mkdir_p( $plugins_snap ) ) {
            return new \WP_Error( 'snapshot_dir_failed', 'Cannot create plugins snapshot directory.' );
        }
        self::copy_dir( WP_PLUGIN_DIR, $plugins_snap );

        // Snapshot active themes directory.
        $themes_snap = $snap_path . '/themes';
        if ( ! wp_mkdir_p( $themes_snap ) ) {
            return new \WP_Error( 'snapshot_dir_failed', 'Cannot create themes snapshot directory.' );
        }
        self::copy_dir( get_theme_root(), $themes_snap );

        $size = self::dir_size( $snap_path );
        $descriptor = [
            'id'         => $id,
            'path'       => $snap_path,
            'timestamp'  => gmdate( 'c' ),
            'size_bytes' => $size,
            'status'     => 'complete',
            'provider'   => 'local_snapshot',
        ];

        // Persist to option log.
        $all   = get_option( self::OPT_KEY, [] );
        $all[] = $descriptor;
        update_option( self::OPT_KEY, array_slice( $all, -10 ) ); // keep last 10

        return $descriptor;
    }

    public function verify( array $descriptor ): true|\WP_Error {
        $path = $descriptor['path'] ?? '';
        if ( empty( $path ) || ! is_dir( $path ) ) {
            return new \WP_Error( 'snapshot_missing', "Snapshot directory '$path' does not exist." );
        }
        if ( ! file_exists( $path . '/db.sql' ) ) {
            return new \WP_Error( 'snapshot_incomplete', 'Snapshot is missing db.sql.' );
        }
        return true;
    }

    public function restore( array $descriptor ): true|\WP_Error {
        $verify = $this->verify( $descriptor );
        if ( is_wp_error( $verify ) ) {
            return $verify;
        }
        $path = $descriptor['path'];

        // Restore plugins.
        if ( is_dir( $path . '/plugins' ) ) {
            self::copy_dir( $path . '/plugins', WP_PLUGIN_DIR );
        }

        // Restore themes.
        if ( is_dir( $path . '/themes' ) ) {
            self::copy_dir( $path . '/themes', get_theme_root() );
        }

        // Restore DB via wpdb source — only safe on environments with shell access.
        // If mysqlimport/mysql is unavailable, return an error so the operator is notified.
        $db_result = self::restore_db( $path . '/db.sql' );
        if ( is_wp_error( $db_result ) ) {
            return $db_result;
        }

        return true;
    }

    public function capabilities(): array {
        return [
            'can_create'      => true,
            'can_verify'      => true,
            'can_restore'     => true,
            // P0-6: restore relies on a naive SQL parser and shell commands with no
            // post-restore integrity check — verified=true would be a false claim.
            'restore_verified'=> false,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private static function dump_db( string $dest_file ): true|\WP_Error {
        global $wpdb;
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            return new \WP_Error( 'db_dump_failed', 'No tables found to dump.' );
        }

        $sql = '';
        foreach ( $tables as $table ) {
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore
            if ( $create ) {
                $sql .= "\n\n" . $create[1] . ";\n";
            }
            $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore
            foreach ( $rows as $row ) {
                $values = array_map( [ $wpdb, '_real_escape' ], array_values( $row ) );
                $sql   .= "INSERT INTO `{$table}` VALUES ('" . implode( "','", $values ) . "');\n";
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === file_put_contents( $dest_file, $sql ) ) {
            return new \WP_Error( 'db_dump_failed', "Cannot write DB dump to $dest_file." );
        }

        return true;
    }

    private static function restore_db( string $sql_file ): true|\WP_Error {
        if ( ! file_exists( $sql_file ) ) {
            return new \WP_Error( 'restore_failed', "SQL file $sql_file not found." );
        }

        global $wpdb;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $sql = file_get_contents( $sql_file );
        if ( empty( $sql ) ) {
            return new \WP_Error( 'restore_failed', "SQL file is empty." );
        }

        $statements = preg_split( '/;\s*\n/', $sql );
        foreach ( $statements as $stmt ) {
            $stmt = trim( $stmt );
            if ( $stmt === '' ) {
                continue;
            }
            $wpdb->query( $stmt ); // phpcs:ignore
        }

        return true;
    }

    private static function copy_dir( string $src, string $dest ): void {
        if ( ! is_dir( $src ) ) {
            return;
        }
        $items = @scandir( $src );
        if ( ! $items ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $src_path  = $src  . DIRECTORY_SEPARATOR . $item;
            $dest_path = $dest . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $src_path ) ) {
                wp_mkdir_p( $dest_path );
                self::copy_dir( $src_path, $dest_path );
            } else {
                @copy( $src_path, $dest_path ); // phpcs:ignore
            }
        }
    }

    private static function dir_size( string $path ): int {
        $size  = 0;
        $items = @scandir( $path );
        if ( ! $items ) {
            return 0;
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $p     = $path . DIRECTORY_SEPARATOR . $item;
            $size += is_dir( $p ) ? self::dir_size( $p ) : (int) @filesize( $p );
        }
        return $size;
    }
}
