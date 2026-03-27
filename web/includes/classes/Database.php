<?php
/**
 * Database Connection Manager - The Data Whisperer
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Singleton wrapper around PDO that handles both MySQL and SQLite connections.
 * Every query goes through prepared statements because we're civilized people
 * who don't concatenate user input into SQL strings like it's 2003. Provides
 * a clean CRUD interface (query, fetchOne, fetchAll, insert, update, delete)
 * plus transaction support for when you need atomicity in your life.
 * If the database is the heart of the app, this class is the pacemaker.
 */

class Database {
    // One instance to rule them all -- singleton pattern
    private static $instance = null;

    // The PDO connection object -- our actual lifeline to the database
    private $pdo;

    // Config reference so we know where to connect
    private $config;

    /**
     * Private constructor -- grabs the config and initiates the DB connection.
     * If this fails, nothing else works, so we let it blow up loudly.
     */
    private function __construct() {
        $this->config = Config::getInstance();
        $this->connect();
    }

    /**
     * Singleton accessor. First call creates the connection, subsequent calls
     * just return the same instance. No sense opening 47 connections per request
     * like some kind of maniac.
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish the actual PDO connection.
     *
     * Supports both SQLite and MySQL/MariaDB. SQLite gets foreign keys enabled
     * manually via PRAGMA because SQLite is that friend who's great but needs
     * a little extra nudging to do the right thing. MySQL connections build
     * a full DSN with host, port, database, and charset.
     *
     * If connection fails, we log the actual error but throw a generic message
     * to the user -- no leaking connection strings to the great unwashed.
     */
    private function connect() {
        try {
            $dbConfig = $this->config->get('database');
            $dbType = $dbConfig['type'] ?? 'mysql';

            if ($dbType === 'sqlite') {
                // SQLite: simple path-based connection. Great for dev and testing.
                $dsn = sprintf('sqlite:%s', $dbConfig['path']);
                $this->pdo = new PDO($dsn, null, null, $dbConfig['options']);

                // SQLite doesn't enforce foreign keys by default. Classic SQLite.
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                // MySQL/MariaDB: the production workhorse.
                // Build a proper DSN with all the trimmings.
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $dbConfig['host'],
                    $dbConfig['port'],
                    $dbConfig['database'],
                    $dbConfig['charset']
                );

                $this->pdo = new PDO(
                    $dsn,
                    $dbConfig['username'],
                    $dbConfig['password'],
                    $dbConfig['options']
                );
            }

        } catch (PDOException $e) {
            // Log the real error, throw a sanitized one
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please check configuration.');
        }
    }

    /**
     * Get the raw PDO connection.
     * For those rare moments when you need to go off-road and talk to PDO directly.
     * Use with caution -- with great power comes great SQL injection potential.
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Execute a query with prepared statement parameters.
     *
     * This is the core method that everything else funnels through.
     * Always uses prepare/execute so Bobby Tables can't ruin our day.
     * Logs both the error message and SQL error code on failure
     * because debugging without error codes is like debugging blindfolded.
     *
     * @param string $query SQL query with named placeholders (:param style)
     * @param array $params Associative array of parameters to bind
     * @return PDOStatement The executed statement, ready for fetching
     */
    public function query($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('Query failed: ' . $e->getMessage());
            error_log('SQL Error Code: ' . $e->getCode());
            throw new Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch a single row from the database.
     * Perfect for "SELECT * FROM users WHERE id = :id" type queries.
     * Returns false if nothing matches, so always check your return value
     * or enjoy a fun evening debugging null reference errors.
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array|false Associative array of the row, or false if not found
     */
    public function fetchOne($query, $params = []) {
        $stmt = $this->query($query, $params);
        return $stmt->fetch();
    }

    /**
     * Fetch all rows matching a query.
     * Returns an array of associative arrays. Empty array if nothing matches,
     * which is polite -- no false, no null, just an empty array you can
     * safely iterate over without crying.
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array Array of rows
     */
    public function fetchAll($query, $params = []) {
        $stmt = $this->query($query, $params);
        return $stmt->fetchAll();
    }

    /**
     * Validate a SQL identifier (table or column name) to prevent injection.
     *
     * Only allows alphanumeric characters and underscores, and the name
     * must start with a letter or underscore. This is a safeguard against
     * any code path that might inadvertently pass user input as a table
     * or column name.
     *
     * @param string $identifier The table or column name to validate
     * @throws Exception If the identifier contains invalid characters
     */
    private function validateIdentifier($identifier) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new Exception('Invalid SQL identifier: ' . preg_replace('/[^a-zA-Z0-9_]/', '', $identifier));
        }
    }

    /**
     * Insert a record into a table.
     *
     * Takes an associative array and builds the INSERT statement for you.
     * Column names become the column list, values become named placeholders.
     * Returns the last insert ID so you know what you just created.
     *
     * Table and column names are validated to contain only safe characters,
     * preventing SQL injection through identifier names.
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return int Last insert ID
     */
    public function insert($table, $data) {
        $this->validateIdentifier($table);
        // Build column list and matching placeholder list
        $columns = array_keys($data);
        foreach ($columns as $col) {
            $this->validateIdentifier($col);
        }
        $placeholders = array_map(function($col) { return ':' . $col; }, $columns);

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        // Map data values to their :placeholder counterparts
        $params = [];
        foreach ($data as $col => $val) {
            $params[':' . $col] = $val;
        }

        $this->query($query, $params);
        return $this->pdo->lastInsertId();
    }

    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }

    /**
     * Update records in a table.
     *
     * Builds the SET clause from the data array and appends your WHERE clause.
     * The WHERE clause and its params are kept separate from the SET params
     * to avoid naming collisions. Returns the number of affected rows, which
     * is handy for knowing if your update actually hit anything.
     *
     * @param string $table Table name
     * @param array $data Associative array of column => new value
     * @param string $where WHERE clause (e.g., 'id = :id')
     * @param array $whereParams Parameters for the WHERE clause
     * @return int Number of affected rows
     */
    public function update($table, $data, $where, $whereParams = []) {
        $this->validateIdentifier($table);

        $sets = [];
        $params = [];

        // Build "column = :column" pairs for the SET clause
        foreach ($data as $col => $val) {
            $this->validateIdentifier($col);
            $sets[] = $col . ' = :' . $col;
            $params[':' . $col] = $val;
        }

        $query = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            $where
        );

        // Merge SET params with WHERE params. Fingers crossed there are no name collisions.
        $params = array_merge($params, $whereParams);

        $stmt = $this->query($query, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete records from a table.
     * Pretty straightforward -- build a DELETE with your WHERE clause.
     * Returns affected row count. Please always include a WHERE clause
     * unless you really hate your data.
     *
     * @param string $table Table name
     * @param string $where WHERE clause
     * @param array $params WHERE parameters
     * @return int Number of deleted rows
     */
    public function delete($table, $where, $params = []) {
        $this->validateIdentifier($table);
        $query = sprintf('DELETE FROM %s WHERE %s', $table, $where);
        $stmt = $this->query($query, $params);
        return $stmt->rowCount();
    }

    /**
     * Begin a database transaction.
     * Everything after this either ALL commits or ALL rolls back.
     * Like Vegas -- what happens in the transaction stays in the transaction
     * (until you commit).
     */
    public function beginTransaction() {
        $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction. Makes all changes permanent.
     * The moment of truth. No take-backs after this.
     */
    public function commit() {
        $this->pdo->commit();
    }

    /**
     * Rollback the current transaction.
     * The "undo" button. Pretend nothing happened.
     * Your data goes back to how it was before beginTransaction().
     */
    public function rollback() {
        $this->pdo->rollBack();
    }

    /**
     * Check if we're currently inside a transaction.
     * Useful for avoiding nested transaction headaches.
     *
     * @return bool True if a transaction is active
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }

    // No cloning allowed -- singletons are not sheep
    private function __clone() {}

    // No unserializing -- you can't just defrost a database connection
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
