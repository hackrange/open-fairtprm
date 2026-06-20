<?php
declare(strict_types=1);

/**
 * Database Backup & Restore Service
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The "oh crap" insurance policy for your database. Dumps the entire DB to a SQL
 * file using pure PHP/PDO -- no mysqldump binary required, which is great because
 * Docker containers rarely come with it pre-installed. Produces output compatible
 * with standard mysql/mariadb CLI import.
 *
 * Also handles restore, because what good is a backup if you can't actually use it?
 * Strips DEFINER clauses on restore so you don't get privilege errors, and handles
 * the DELIMITER weirdness that PDO can't deal with natively.
 *
 * Batches INSERT statements in groups of 100 rows to keep memory usage sane,
 * because some people have tables with millions of rows and we'd rather not
 * OOM-kill the PHP process during a backup.
 */
class BackupService
{
    // 100 rows per INSERT batch -- enough to be efficient, not enough to blow up memory
    private const BATCH_SIZE = 100;

    private array $dbConfig;

    public function __construct(array $dbConfig)
    {
        $this->dbConfig = $dbConfig;
    }

    /**
     * Dump the database to a .sql file.
     * Works without any external binaries -- uses the app's existing PDO connection.
     * Tables, data, procedures, functions, and triggers all get dumped.
     *
     * @param string $filePath Where to write the SQL dump
     * @throws \Exception If the file can't be opened or the DB connection fails
     */
    public function dump(string $filePath): void
    {
        // Backups of large databases / large cells must not be killed by the
        // default 300s web execution limit, and reading big BLOB cells needs
        // headroom. These only relax limits for this long-running operation.
        @set_time_limit(0);
        @ini_set('memory_limit', '-1');

        $pdo = $this->connect();

        $fp = fopen($filePath, 'w');
        if (!$fp) {
            throw new \Exception('Unable to open backup file for writing');
        }

        $dbName = $this->dbConfig['database'];

        // Header -- metadata so future-you knows what this file is
        fwrite($fp, "-- Database Backup (PHP/PDO)\n");
        fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "-- Database: " . $dbName . "\n");
        fwrite($fp, "-- --------------------------------------------------------\n\n");
        fwrite($fp, "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n");
        fwrite($fp, "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($fp, "SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT;\n");
        fwrite($fp, "SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS;\n");
        fwrite($fp, "SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION;\n");
        fwrite($fp, "SET NAMES utf8mb4;\n\n");

        // Dump all tables: schema first, then data
        $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                continue;
            }
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);

            if (isset($createStmt['Create View'])) {
                // It's a view -- DROP first, then CREATE OR REPLACE
                fwrite($fp, "--\n-- View `{$table}`\n--\n\n");
                fwrite($fp, "DROP VIEW IF EXISTS `{$table}`;\n");
                $ddl = preg_replace('/DEFINER[ ]*=[ ]*`[^`]*`@`[^`]*`\s*/', '', $createStmt['Create View']);
                $ddl = preg_replace('/^CREATE\b/', 'CREATE OR REPLACE', $ddl);
                fwrite($fp, $ddl . ";\n\n");
                continue; // Views have no data to dump
            }

            $ddl = $createStmt['Create Table'] ?? null;
            if ($ddl === null) {
                continue;
            }

            // DROP then CREATE so restores replace existing data
            fwrite($fp, "--\n-- Table structure for `{$table}`\n--\n\n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fp, $ddl . ";\n\n");

            // Data dump with batched INSERTs for memory efficiency
            $rowCount = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            if ($rowCount > 0) {
                fwrite($fp, "--\n-- Data for `{$table}` ({$rowCount} rows)\n--\n\n");
                fwrite($fp, "LOCK TABLES `{$table}` WRITE;\n");

                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                $columns = null;
                $batch = [];

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($columns === null) {
                        $columns = array_keys($row);
                        $colList = '`' . implode('`, `', $columns) . '`';
                    }
                    $values = [];
                    foreach ($row as $val) {
                        $values[] = ($val === null) ? 'NULL' : $pdo->quote((string)$val);
                    }
                    $batch[] = '(' . implode(',', $values) . ')';

                    if (count($batch) >= self::BATCH_SIZE) {
                        fwrite($fp, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if (!empty($batch)) {
                    fwrite($fp, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                }

                fwrite($fp, "UNLOCK TABLES;\n\n");
            }
        }

        // Dump stored procedures
        $procedures = $pdo->query(
            "SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = "
            . $pdo->quote($dbName) . " AND ROUTINE_TYPE = 'PROCEDURE'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($procedures as $procName) {
            fwrite($fp, "--\n-- Procedure `{$procName}`\n--\n\n");
            fwrite($fp, "DROP PROCEDURE IF EXISTS `{$procName}`;\n");
            fwrite($fp, "DELIMITER ;;\n");
            $create = $pdo->query("SHOW CREATE PROCEDURE `{$procName}`")->fetch(\PDO::FETCH_ASSOC);
            if (!empty($create['Create Procedure'])) {
                $procSql = preg_replace('/DEFINER[ ]*=[ ]*`[^`]*`@`[^`]*`/', '', $create['Create Procedure']);
                fwrite($fp, $procSql . " ;;\n");
            }
            fwrite($fp, "DELIMITER ;\n\n");
        }

        // Dump stored functions
        $functions = $pdo->query(
            "SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = "
            . $pdo->quote($dbName) . " AND ROUTINE_TYPE = 'FUNCTION'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($functions as $funcName) {
            fwrite($fp, "--\n-- Function `{$funcName}`\n--\n\n");
            fwrite($fp, "DROP FUNCTION IF EXISTS `{$funcName}`;\n");
            fwrite($fp, "DELIMITER ;;\n");
            $create = $pdo->query("SHOW CREATE FUNCTION `{$funcName}`")->fetch(\PDO::FETCH_ASSOC);
            if (!empty($create['Create Function'])) {
                $funcSql = preg_replace('/DEFINER[ ]*=[ ]*`[^`]*`@`[^`]*`/', '', $create['Create Function']);
                fwrite($fp, $funcSql . " ;;\n");
            }
            fwrite($fp, "DELIMITER ;\n\n");
        }

        // Dump triggers
        $triggers = $pdo->query("SHOW TRIGGERS")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($triggers as $trigger) {
            $trigName = $trigger['Trigger'];
            fwrite($fp, "--\n-- Trigger `{$trigName}`\n--\n\n");
            fwrite($fp, "DROP TRIGGER IF EXISTS `{$trigName}`;\n");
            fwrite($fp, "DELIMITER ;;\n");
            $create = $pdo->query("SHOW CREATE TRIGGER `{$trigName}`")->fetch(\PDO::FETCH_ASSOC);
            if (!empty($create['SQL Original Statement'])) {
                $trigSql = preg_replace('/DEFINER[ ]*=[ ]*`[^`]*`@`[^`]*`/', '', $create['SQL Original Statement']);
                fwrite($fp, $trigSql . " ;;\n");
            }
            fwrite($fp, "DELIMITER ;\n\n");
        }

        // Footer -- restore original settings
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n");
        fwrite($fp, "SET SQL_MODE=@OLD_SQL_MODE;\n");
        fwrite($fp, "SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT;\n");
        fwrite($fp, "SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS;\n");
        fwrite($fp, "SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION;\n");
        fclose($fp);
    }

    /**
     * Restore a .sql dump file.
     * Strips DEFINER clauses so you don't get privilege errors, and handles
     * the DELIMITER blocks that PDO can't deal with natively.
     *
     * @param string $filePath The SQL file to restore
     * @throws \Exception If the file can't be read or the restore fails
     */
    public function restore(string $filePath): void
    {
        // A large restore can run for many minutes and the dump is read into
        // memory for quote-aware parsing; never let the default 300s execution
        // limit or the 2G web memory cap abort a restore in progress.
        @set_time_limit(0);
        @ini_set('memory_limit', '-1');

        $pdo = $this->connect();

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new \Exception('Unable to read backup file');
        }

        // Strip DEFINER clauses to avoid privilege errors on restore
        $sql = preg_replace('/DEFINER[ ]*=[ ]*`[^`]*`@`[^`]*`/', '', $sql);

        // Remap MySQL 8.0+ collations that MariaDB doesn't support.
        // utf8mb4_0900_ai_ci is MySQL 8.0's default but doesn't exist in MariaDB.
        $sql = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $sql);
        $sql = str_replace('utf8mb4_0900_as_ci', 'utf8mb4_unicode_ci', $sql);
        $sql = str_replace('utf8mb4_0900_as_cs', 'utf8mb4_bin', $sql);

        // Strip DELIMITER blocks -- PDO cannot handle DELIMITER commands.
        // Re-join the delimited statements with standard semicolons.
        $sql = preg_replace('/DELIMITER\s+;;\s*\n?/', '', $sql);
        $sql = preg_replace('/DELIMITER\s+;\s*\n?/', '', $sql);
        $sql = str_replace(' ;;', ';', $sql);

        // Split into individual statements using a quote-aware parser.
        // We can't just explode on ';' because encrypted data and string
        // literals frequently contain semicolons. Ask me how I know.
        $statements = self::splitStatements($sql);

        // Separate views from other statements so they run after all tables
        // exist. Views often reference tables that appear later in the dump,
        // which causes "table doesn't exist" errors during sequential replay.
        $mainStatements = [];
        $viewStatements = [];
        foreach ($statements as $stmt) {
            $trimmed = ltrim($stmt);
            if (preg_match('/^CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\S+\s+)?(SQL\s+SECURITY\s+\S+\s+)?VIEW\s+/i', $trimmed)) {
                $viewStatements[] = $stmt;
            } else {
                $mainStatements[] = $stmt;
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

        // Drop all existing views and tables first so the restore starts clean.
        // This handles old-format backups (CREATE TABLE IF NOT EXISTS / INSERT IGNORE)
        // that wouldn't otherwise replace existing data.
        $this->dropAllObjects($pdo);

        $errors = [];

        // Execute tables, inserts, procedures, triggers first
        foreach ($mainStatements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                $errors[] = $e->getMessage();
                error_log('Restore statement error: ' . $e->getMessage());
            }
        }

        // Execute views last, now that all tables exist
        foreach ($viewStatements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                $errors[] = $e->getMessage();
                error_log('Restore view error: ' . $e->getMessage());
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        if (!empty($errors)) {
            throw new \Exception(count($errors) . ' statement(s) had errors during restore. Check the error log for details.');
        }
    }

    /**
     * Drop all views, triggers, routines, and tables in the current database.
     * Views and triggers must go first (they depend on tables). Foreign key
     * checks should be disabled by the caller.
     */
    private function dropAllObjects(\PDO $pdo): void
    {
        // Drop triggers first (they reference tables)
        $triggers = $pdo->query("SHOW TRIGGERS")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($triggers as $trigger) {
            $name = $trigger['Trigger'];
            if (preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                $pdo->exec("DROP TRIGGER IF EXISTS `{$name}`");
            }
        }

        // Drop views (they reference tables)
        $dbName = $this->dbConfig['database'];
        $views = $pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = "
            . $pdo->quote($dbName)
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($views as $view) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $view)) {
                $pdo->exec("DROP VIEW IF EXISTS `{$view}`");
            }
        }

        // Drop stored procedures
        $procs = $pdo->query(
            "SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = "
            . $pdo->quote($dbName) . " AND ROUTINE_TYPE = 'PROCEDURE'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($procs as $proc) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $proc)) {
                $pdo->exec("DROP PROCEDURE IF EXISTS `{$proc}`");
            }
        }

        // Drop stored functions
        $funcs = $pdo->query(
            "SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = "
            . $pdo->quote($dbName) . " AND ROUTINE_TYPE = 'FUNCTION'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($funcs as $func) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $func)) {
                $pdo->exec("DROP FUNCTION IF EXISTS `{$func}`");
            }
        }

        // Drop all tables last (FK checks are off, so order doesn't matter)
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }
        }
    }

    /**
     * Split a SQL dump into individual statements, respecting quoted strings.
     *
     * A naive explode(';') won't cut it here -- encrypted blobs and string
     * literals are packed with semicolons, and SQL comments use -- which can
     * appear inside COMMENT clauses and quoted data. This parser walks the
     * string character by character, tracking quote state so it only splits
     * on semicolons that are actually statement terminators.
     *
     * @return string[]
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            // Single-line comment outside quotes: skip to end of line
            if ($ch === '-' && ($i + 1 < $len) && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Multi-line comment outside quotes: skip to closing */
            if ($ch === '/' && ($i + 1 < $len) && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i < $len) {
                    if ($sql[$i] === '*' && ($i + 1 < $len) && $sql[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Quoted string: copy verbatim until matching close quote
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $current .= $ch;
                $i++;
                while ($i < $len) {
                    $current .= $sql[$i];
                    if ($sql[$i] === '\\') {
                        // Escaped character -- grab the next one too
                        $i++;
                        if ($i < $len) {
                            $current .= $sql[$i];
                        }
                    } elseif ($sql[$i] === $quote) {
                        break;
                    }
                    $i++;
                }
                $i++;
                continue;
            }

            // Statement terminator -- flush current statement
            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $ch;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Apply all SQL update files after a restore to bring the schema up to date.
     *
     * Backups from older versions may be missing columns, indexes, or tables
     * that the current codebase expects. The sql_updates/ directory contains
     * idempotent migration files (ALTER TABLE IF NOT EXISTS, etc.) that are
     * safe to re-run. Uses --force so duplicate-column errors don't abort.
     *
     * @return int Number of SQL files applied
     */
    public function applySqlUpdates(): int
    {
        $sqlDir = dirname(dirname(__DIR__)) . '/sql_updates';
        if (!is_dir($sqlDir)) {
            return 0;
        }

        $files = glob($sqlDir . '/*.sql');
        if (empty($files)) {
            return 0;
        }

        // Sort by version (natural/version sort)
        usort($files, 'strnatcasecmp');

        $pdo = $this->connect();
        $count = 0;

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            $statements = self::splitStatements($sql);
            foreach ($statements as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $e) {
                    // Suppress expected idempotent errors (duplicate column/index/key)
                    $code = $e->getCode();
                    if (!in_array($code, ['42S21', '42000']) &&
                        !preg_match('/Duplicate (column|key) name/i', $e->getMessage())) {
                        error_log('SQL update error (' . basename($file) . '): ' . $e->getMessage());
                    }
                }
            }
            $count++;
        }

        return $count;
    }

    /**
     * Re-encrypt all encrypted data from an old key to the current instance key.
     *
     * When restoring a backup from a different instance, all encrypted data is
     * encrypted with the source instance's key. This method decrypts everything
     * with the old key and re-encrypts with the current key so the app works.
     *
     * @param string $oldKeyBase64 The source instance's encryption key (base64)
     * @return int Number of values re-encrypted
     */
    public function reEncryptData(string $oldKeyBase64): int
    {
        $oldEnc = new \Encryption($oldKeyBase64);
        $newEnc = new \Encryption();
        $pdo = $this->connect();
        $count = 0;

        // --- tprm_results: all encrypted FAIR/assessment columns ---
        $encryptedColumns = [
            'msa', 'scope_of_work', 'medium_of_data', 'certifications', 'compliance',
            'security_governance', 'incident_response_plan', 'continuous_monitoring',
            'supply_chain_risk_mgmt', 'security_awareness_training', 'vulnerability_management',
            'patch_management', 'access_controls', 'data_encryption', 'network_security',
            'vulnerability_data', 'configuration_data', 'compliance_data', 'risk_assessment',
            'threat_intelligence', 'vendor_risk_assessment', 'security_questionnaire',
            'compliance_questionnaire', 'data_classification', 'data_sharing', 'business_impact',
            'vendor_performance', 'third_party_vendor_list', 'third_party_risk_assessment',
            'third_party_security_questionnaire', 'third_party_compliance_questionnaire',
            'vendor_cyber_insurance_coverage', 'ale', 'loss_event_frequency', 'loss_magnitude',
            'primary_loss_magnitude', 'secondary_loss_magnitude', 'recommended_liability',
            'cost_of_outage', 'cost_of_breach', 'sec_fines', 'compliance_fines',
            'insurance_premiums', 'daily_impact', 'total_cost_of_breach',
            'pii_breach_cost', 'spii_breach_cost', 'sox_breach_cost',
        ];

        // Filter to columns that actually exist in the table
        $existingCols = $pdo->query("SHOW COLUMNS FROM `tprm_results`")->fetchAll(\PDO::FETCH_COLUMN);
        $encryptedColumns = array_intersect($encryptedColumns, $existingCols);

        if (!empty($encryptedColumns)) {
            $selectCols = 'id, ' . implode(', ', array_map(fn($c) => "`$c`", $encryptedColumns));
            $rows = $pdo->query("SELECT {$selectCols} FROM `tprm_results`")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $updates = [];
                $params = [];
                foreach ($encryptedColumns as $col) {
                    if (empty($row[$col])) {
                        continue;
                    }
                    try {
                        $plaintext = $oldEnc->decrypt($row[$col]);
                        $newCiphertext = $newEnc->encrypt($plaintext);
                        $updates[] = "`{$col}` = ?";
                        $params[] = $newCiphertext;
                        $count++;
                    } catch (\Exception $e) {
                        // Skip values that fail to decrypt (may already use current key or be corrupt)
                        error_log("reEncrypt skip tprm_results.{$col} id={$row['id']}: " . $e->getMessage());
                    }
                }
                if (!empty($updates)) {
                    $params[] = $row['id'];
                    $sql = "UPDATE `tprm_results` SET " . implode(', ', $updates) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($params);
                }
            }
        }

        // --- app_config: rows where is_encrypted = 1 ---
        $configRows = $pdo->query(
            "SELECT id, config_value FROM `app_config` WHERE is_encrypted = 1 AND config_value IS NOT NULL AND config_value != ''"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($configRows as $row) {
            try {
                $plaintext = $oldEnc->decrypt($row['config_value']);
                $newCiphertext = $newEnc->encrypt($plaintext);
                $stmt = $pdo->prepare("UPDATE `app_config` SET config_value = ? WHERE id = ?");
                $stmt->execute([$newCiphertext, $row['id']]);
                $count++;
            } catch (\Exception $e) {
                error_log("reEncrypt skip app_config id={$row['id']}: " . $e->getMessage());
            }
        }

        // --- users: totp_secret ---
        $userRows = $pdo->query(
            "SELECT id, totp_secret FROM `users` WHERE totp_secret IS NOT NULL AND totp_secret != ''"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($userRows as $row) {
            try {
                $plaintext = $oldEnc->decrypt($row['totp_secret']);
                $newCiphertext = $newEnc->encrypt($plaintext);
                $stmt = $pdo->prepare("UPDATE `users` SET totp_secret = ? WHERE id = ?");
                $stmt->execute([$newCiphertext, $row['id']]);
                $count++;
            } catch (\Exception $e) {
                error_log("reEncrypt skip users.totp_secret id={$row['id']}: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Create a fresh PDO connection using the stored config.
     * Separate from the app's singleton connection because backup/restore
     * operations might need different settings or a fresh state.
     */
    private function connect(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->dbConfig['host'],
            $this->dbConfig['port'] ?? 3306,
            $this->dbConfig['database'],
            $this->dbConfig['charset'] ?? 'utf8mb4'
        );
        return new \PDO($dsn, $this->dbConfig['username'], $this->dbConfig['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
