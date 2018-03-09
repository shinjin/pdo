<?php
namespace Shinjin\Pdo;

class Db
{

    /**
     * Default connection parameters
     *
     * @var array
     */
    const DEFAULT_PARAMS = array(
        array(
            'dsn'      => null,
            'dbname'   => null,
            'host'     => null,
            'port'     => null,
            'user'     => null,
            'password' => null,
            'charset'  => 'utf8mb4'
        ),
        'mysql' => array(
            'host' => 'localhost',
            'port' => '3306',
            'user' => 'root'
        ),
        'pgsql' => array(
            'host' => 'localhost',
            'port' => '5432',
            'user' => 'postgres'
        ),
        'sqlite' => array(
            'dsn' => 'sqlite::memory:'
        )
    );

    /**
     * PDO object
     *
     * @var \PDO
     */
    private $pdo;

    /**
     * Character used to quote identifiers
     *
     * @var string
     */
    private $quote_delimiter;

    /**
     * Transaction level
     *
     * @var integer
     */
    private $transaction_level;

    /**
     * Constructor
     *
     * @param \PDO|array $pdo PDO object
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($pdo, array $options = array())
    {
        if (!$pdo instanceof \PDO && !is_array($pdo)) {
            throw new \InvalidArgumentException(
                '$pdo must be a PDO object or an array'
            );
        }

        if (is_array($pdo)) {
            $this->pdo = $this->connect($pdo, $options);
            $driver = $pdo['driver'];
        } else {
            $this->pdo = $pdo;
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        }

        $this->quote_delimiter = $driver === 'mysql' ? '`' : '"';
        $this->transaction_level = 0;
    }

    /**
     * Delegates non-existent method calls to the PDO object.
     *
     * @param string $name Method name
     * @param array  $args Method arguments
     *
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call($name, array $args)
    {
        $method = array($this->pdo, $name);

        if (!is_callable($method)) {
            throw new \BadMethodCallException("Db does not have a method '$name'");
        }

        return call_user_func_array($method, $args);
    }

    /**
     * Creates a PDO object and opens a db connection.
     *
     * @param array $params  Db connection parameters
     * @param array $options PDO options
     *
     * @return \PDO
     * @throws \InvalidArgumentException
     */
    public function connect(array $params, array $options = array())
    {
        $default_params = self::DEFAULT_PARAMS; // for php 5.6 bc
        if (!isset($params['driver']) ||
            !isset($default_params[$params['driver']])
        ) {
            throw new \InvalidArgumentException('Invalid db driver specified.');
        }

        $db = array_replace(
            self::DEFAULT_PARAMS[0],
            array_replace(self::DEFAULT_PARAMS[$params['driver']], $params)
        );

        $options = array_replace(
            array(
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION
            ),
            $options
        );

        $pdo = new \PDO(
            $this->buildConnectionString($db),
            $db['user'],
            $db['password'],
            $options
        );

        return $pdo;
    }

    /**
     * Executes a prepared statement query.
     *
     * @param \PDOStatement|string $statement PDOStatement object or query string
     * @param array|scalar         $params    Parameters to bind to query
     *
     * @return \PDOStatement
     * @throws \InvalidArgumentException
     */
    public function query($statement, $params = array()){
        if (!$statement instanceof \PDOStatement && !is_string($statement)) {
            throw new \InvalidArgumentException(
                '$statement must be a PDOStatement object or a string'
            );
        }

        if (is_string($statement)) {
            $statement = $this->pdo->prepare($statement);
        }
        $statement->execute((array)$params);

        return $statement;
    }

    /**
     * Creates an INSERT query and executes it.
     *
     * @param string $table  Table name
     * @param array  $values List of column/value pairs to INSERT
     *
     * @return integer Number of affected rows
     * @throws \InvalidArgumentException
     */
    public function insert($table, array $values)
    {
        if (empty($values)) {
            throw new \InvalidArgumentException('$values must not be empty.');
        }

        if (count($values) === count($values, COUNT_RECURSIVE)) {
            $values = array($values);
        }

        $statement = $this->buildInsertQuery($table, array_keys(current($values)));
        $affected_rows = 0;

        foreach($values as $set) {
            $statement = $this->query($statement, array_values($set));
            $affected_rows += $statement->rowCount();
        }
        
        return $affected_rows;
    }

    /**
     * Creates an UPDATE query and executes it.
     *
     * @param string $table   Table name
     * @param array  $values  List of column/value pairs to UPDATE
     * @param array  $filters List of column/value pairs to filter by in
     *                        WHERE clause
     *
     * @return integer Number of affected rows
     * @throws \InvalidArgumentException
     */
    public function update($table, array $values, array $filters)
    {
        if (empty($values)) {
            throw new \InvalidArgumentException('$values must not be empty.');
        }

        if (empty($filters)) {
            throw new \InvalidArgumentException('$filters must not be empty.');
        }

        $set = '';
        $params = array();

        foreach ($values as $column => $value) {
            list($column, $operator) = array_pad(explode(' ', $column), 2, '=');
            $set .= $this->quoteIdentifier($column) . ' ' . $operator . ' ?,';
            array_push($params, (string)$value);
        }

        $statement = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            rtrim($set, ','),
            $this->buildQueryFilter($filters, $params)
        );

        return $this->query($statement, $params)->rowCount();
    }

    /**
     * Creates a DELETE query and executes it.
     *
     * @param string $table   Table name
     * @param array  $filters List of column/value pairs to filter by in
     *                        WHERE clause
     *
     * @return integer Number of affected rows
     * @throws \InvalidArgumentException
     */
    public function delete($table, array $filters)
    {
        if (empty($filters)) {
            throw new \InvalidArgumentException('$filters must not be empty.');
        }

        $params = array();
        $statement = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $this->buildQueryFilter($filters, $params)
        );

        return $this->query($statement, $params)->rowCount();
    }

    /**
     * Starts a PDO transaction.
     *
     * @return boolean True on success, false on failure
     */
    public function beginTransaction()
    {
        if ($this->transaction_level === 0) {
            $result = $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT LEVEL' . $this->transaction_level);
            $result = true;
        }

        $this->transaction_level++;
        return $result;
    }

    /**
     * Commits a PDO transaction.
     *
     * @return boolean True on success, false on failure
     */
    public function commit()
    {
        if (--$this->transaction_level === 0) {
            return $this->pdo->commit();
        }

        $this->pdo->exec('RELEASE SAVEPOINT LEVEL' . $this->transaction_level);
        return true;
    }

    /**
     * Rolls back a PDO transaction.
     *
     * @return boolean True on success, false on failure
     */
    public function rollback()
    {
        if (--$this->transaction_level === 0) {
            return $this->pdo->rollBack();
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT LEVEL' . $this->transaction_level);
        return true;
    }

    /**
     * Creates an INSERT query.
     *
     * @param string $table   Table name
     * @param array  $columns List of column names
     *
     * @return string INSERT query statement
     */
    public function buildInsertQuery($table, array $columns)
    {
        $statement = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(',', array_map(array($this, 'quoteIdentifier'), array_values($columns))),
            implode(',', array_fill(0, count($columns), '?'))
        );

        return $statement;
    }

    /**
     * Constructs a string containing column and placeholder pairs for a
     * query's WHERE clause.
     *
     * @param array $filters List of column/value pairs to filter by in
     *                       WHERE clause
     * @param array $params  List of values that correspond to the placeholders
     *                       in the filter string
     *
     * @return string Query filter string
     * @throws \InvalidArgumentException
     */
    public function buildQueryFilter(array $filters, array &$params = array()){
        $filter = '(';

        $and_or = null;
        foreach($filters as $column => $value) {
            if (is_integer($column) && is_string($value)) {
                if (in_array(strtoupper($value), array('AND', 'OR'))) {
                    if ($and_or === null) {
                        throw new \InvalidArgumentException(
                            'Filter must not start with operator.'
                        );
                    }

                    $and_or = strtoupper($value);
                    continue;
                }
            }

            if (!empty($and_or)) {
                $filter .= sprintf(' %s ', $and_or);
            }

            if (is_string($column)) {
                list($column, $operator) = array_pad(explode(' ', $column), 2, '=');
                $filter .= $this->quoteIdentifier($column) . ' ' . $operator . ' ?';
                array_push($params, $value);
            } else {
                if (is_array($value)) {
                    $filter .= $this->buildQueryFilter($value, $params);
                } else {
                    throw new \InvalidArgumentException(
                        'Filter must be a key/value pair or array.'
                    );
                }
            }

            $and_or = 'AND';
        }

        return $filter . ')';
    }

    /**
     * Quotes a table or column name.
     *
     * @param string $identifier Value to be quoted
     *
     * @return string The quoted value
     */
    public function quoteIdentifier($identifier)
    {
        $d = $this->quote_delimiter;
        return $d . str_replace($d, $d.$d, $identifier) . $d;
    }

    /**
     * Creates a dsn connection string.
     *
     * @param array $db_params Db connection parameters
     *
     * @return string DSN connection string
     */
    private function buildConnectionString(array $db_params)
    {
        $dsn = $db_params['dsn'];

        if (empty($dsn)) {
            $dsn = $db_params['driver'] . ':';

            $dsn_params = array_intersect_key(
                array_filter($db_params),
                array_flip(array('dbname', 'host', 'port', 'charset'))
            );

            $dsn .= urldecode(http_build_query($dsn_params, null, ';'));
        }

        return $dsn;
    }
}
