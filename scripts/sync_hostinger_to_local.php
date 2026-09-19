<?php
/**
 * Copy Hostinger database data into the local Laragon database.
 *
 * Source: Hostinger database (SELECT only)
 * Target: local database from config/db_config.php
 *
 * Required source environment variables:
 *   HOSTINGER_DB_HOST, HOSTINGER_DB_NAME, HOSTINGER_DB_USER, HOSTINGER_DB_PASS
 *
 * Run with --dry-run first, then --apply to upsert the latest source rows.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/db_config.php';

$apply = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true) || !$apply;

$source = [
    'host' => getenv('HOSTINGER_DB_HOST') ?: '',
    'name' => getenv('HOSTINGER_DB_NAME') ?: '',
    'user' => getenv('HOSTINGER_DB_USER') ?: '',
    'pass' => getenv('HOSTINGER_DB_PASS') ?: '',
    'port' => (int)(getenv('HOSTINGER_DB_PORT') ?: 3306),
];

foreach (['host', 'name', 'user'] as $key) {
    if ($source[$key] === '') {
        fwrite(STDERR, "Missing HOSTINGER_DB_" . strtoupper($key) . " environment variable.\n");
        exit(1);
    }
}

$connectSource = static function () use ($source): mysqli {
    $connection = new mysqli($source['host'], $source['user'], $source['pass'], $source['name'], $source['port']);
    if ($connection->connect_errno) {
        throw new RuntimeException("Hostinger DB connection failed: {$connection->connect_error}");
    }
    $connection->set_charset(DB_CHARSET);
    return $connection;
};

$src = $connectSource();

$dst = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($dst->connect_errno) {
    fwrite(STDERR, "Local DB connection failed: {$dst->connect_error}\n");
    exit(1);
}
$dst->set_charset(DB_CHARSET);
// Hostinger may contain legacy zero dates (for example 0000-00-00). Relax
// strict mode on the local session only so those source values can be copied
// without changing the Hostinger database or the local schema.
$dst->query("SET SESSION sql_mode = ''");

// Only base tables are copied. Views/triggers are part of the local schema, not data.
$tables = [];
$result = $src->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
if (!$result) {
    fwrite(STDERR, "Could not list Hostinger tables: {$src->error}\n");
    exit(1);
}
while ($row = $result->fetch_array(MYSQLI_NUM)) {
    $tables[] = $row[0];
}
$result->free();

echo "Source: {$source['host']}/{$source['name']}\n";
echo "Target: " . DB_HOST . "/" . DB_NAME . "\n";
echo "Tables: " . count($tables) . "\n";
if ($dryRun) {
    echo "Dry run only. Use --apply to upsert latest rows; local rows are preserved.\n";
    exit(0);
}

$dst->query('SET FOREIGN_KEY_CHECKS=0');
$copied = 0;

try {
    foreach ($tables as $table) {
        // Hostinger may close an idle connection while a large local table is
        // being inserted. Reconnect for each table; the source is read-only.
        $src->close();
        $src = $connectSource();
        $qTable = '`' . $dst->real_escape_string($table) . '`';

        // Skip tables that do not exist locally; this script copies data only.
        $localCheck = $dst->query("SHOW TABLES LIKE '" . $dst->real_escape_string($table) . "'");
        if (!$localCheck || $localCheck->num_rows === 0) {
            echo "SKIP {$table}: not present locally\n";
            continue;
        }
        $localCheck->free();

        $sourceColumns = [];
        $primaryColumns = [];
        $columnResult = $src->query("SHOW COLUMNS FROM `" . $src->real_escape_string($table) . "`");
        if (!$columnResult) {
            throw new RuntimeException("Could not read columns for {$table}: {$src->error}");
        }
        while ($column = $columnResult->fetch_assoc()) {
            // Generated columns cannot be inserted explicitly.
            if (stripos((string)$column['Extra'], 'GENERATED') !== false) {
                continue;
            }
            $sourceColumns[] = $column['Field'];
            if ($column['Key'] === 'PRI') {
                $primaryColumns[] = $column['Field'];
            }
        }
        $columnResult->free();
        if (!$sourceColumns) {
            echo "SKIP {$table}: no insertable columns\n";
            continue;
        }

        // Copy only shared columns; this script never changes the local schema.
        $localColumns = [];
        $localColumnResult = $dst->query("SHOW COLUMNS FROM {$qTable}");
        while ($localColumn = $localColumnResult->fetch_assoc()) {
            $localColumns[$localColumn['Field']] = true;
        }
        $localColumnResult->free();
        $columns = array_values(array_filter($sourceColumns, static fn($column) => isset($localColumns[$column])));
        $primaryColumns = array_values(array_filter($primaryColumns, static fn($column) => in_array($column, $columns, true)));
        if (!$columns) {
            echo "SKIP {$table}: no shared columns\n";
            continue;
        }

        $columnSql = implode(', ', array_map(static fn($column) => '`' . $dst->real_escape_string($column) . '`', $columns));
        $sourceQuery = "SELECT " . implode(', ', array_map(static fn($column) => '`' . $src->real_escape_string($column) . '`', $columns))
            . " FROM `" . $src->real_escape_string($table) . "`";
        // Buffer each table before writing locally. With MYSQLI_USE_RESULT the
        // source connection can sit idle while a large table is inserted locally
        // and Hostinger may close it for inactivity.
        $sourceRows = $src->query($sourceQuery);
        if (!$sourceRows) {
            throw new RuntimeException("Could not read {$table}: {$src->error}");
        }

        $synced = 0;
        $batch = [];
        $flush = static function () use (&$batch, $dst, $qTable, $columnSql, $columns, $primaryColumns, $table): void {
            if (!$batch) {
                return;
            }
            $sql = "INSERT INTO {$qTable} ({$columnSql}) VALUES " . implode(', ', $batch);
            if ($primaryColumns) {
                $updates = [];
                foreach ($columns as $column) {
                    if (!in_array($column, $primaryColumns, true)) {
                        $qColumn = '`' . $dst->real_escape_string($column) . '`';
                        $updates[] = "{$qColumn}=VALUES({$qColumn})";
                    }
                }
                if ($updates) {
                    $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
                } else {
                    $sql = "INSERT IGNORE INTO {$qTable} ({$columnSql}) VALUES " . implode(', ', $batch);
                }
            } else {
                $sql = "INSERT IGNORE INTO {$qTable} ({$columnSql}) VALUES " . implode(', ', $batch);
            }
            if (!$dst->query($sql)) {
                throw new RuntimeException("Upsert failed for {$table}: {$dst->error}");
            }
            $batch = [];
        };
        while ($data = $sourceRows->fetch_row()) {
            $values = [];
            foreach ($data as $value) {
                $values[] = $value === null ? 'NULL' : "'" . $dst->real_escape_string((string)$value) . "'";
            }
            $batch[] = '(' . implode(', ', $values) . ')';
            $synced++;
            if (count($batch) >= 100) {
                $flush();
            }
        }
        $flush();
        $sourceRows->free();
        $copied++;
        echo "OK {$table}: {$synced} latest rows\n";
    }
} finally {
    $dst->query('SET FOREIGN_KEY_CHECKS=1');
    $src->close();
    $dst->close();
}

echo "Completed. Copied {$copied} tables into the local database only.\n";
