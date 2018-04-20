<?php
namespace Shinjin\Pdo;

use Shinjin\Pdo\Exception\BadArgumentException;
use Shinjin\Pdo\Exception\BadFilterException;
use Shinjin\Pdo\Exception\BadValueException;

class Db
{
    /**
     * List of supported drivers and parameters
     *
     * @const array
     */
    const DRIVERS = array(
        'default' => array(
            'pdo_params' => array(
                'dsn'      => null,
                'dbname'   => null,
                'host'     => null,
                'port'     => null,
                'user'     => null,
                'password' => null,
                'charset'  => 'utf8mb4'
            ),
            'pdo_options' => array(
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false
            ),
            'quote_delimiter' => '"'
        ),
        'mysql' => array(
            'pdo_params' => array(
                'host' => 'localhost',
                'port' => '3306',
                'user' => 'root'
            ),
            'quote_delimiter' => '`'
        ),
        'pgsql' => array(
            'pdo_params' => array(
                'host' => 'localhost',
                'port' => '5432',
                'user' => 'postgres'
            )
        ),
        'sqlite' => array(
            'pdo_params' => array(
                'dsn' => 'sqlite::memory:'
            )
        )
    );

    const JOINS = array(
        'JOIN',
        'INNER JOIN',
        'FULL JOIN',
        'FULL OUTER JOIN',
        'LEFT JOIN',
        'LEFT OUTER JOIN',
        'RIGHT JOIN', 
        'RIGHT OUTER JOIN',
        'CROSS JOIN'
    );

    /**
     * PDO object
     *
     * @var \PDO
     */
    private $pdo;

    /**
     * PDO driver params
     *
     * @var array
     */
    private $driver;

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
     * @throws \Shinjin\Pdo\Exception\BadArgumentException
     */
    public function __construct($pdo, array $options = array())
    {
        if ($pdo instanceof \PDO) {
            $this->pdo = $pdo;
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } elseif(is_array($pdo)) {
            $this->pdo = $this->connect($pdo, $options);
            $driver = $pdo['driver'];
        } else {
            throw new BadArgumentException(
                'PDO argument must be a PDO object or an array.'
            );
        }

        // explicitly set error mode to exception
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->driver = array_replace_recursive(
            self::DRIVERS['default'],
            self::DRIVERS[$driver]
        );
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
            throw new \BadMethodCallException("Db does not have a method '$name'.");
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
     * @throws \Shinjin\Pdo\Exception\BadArgumentException
     */
    public function connect(array $params, array $options = array())
    {
        if (empty($params['driver']) ||
            !array_key_exists($params['driver'], self::DRIVERS)) {
            throw new BadArgumentException('Invalid db driver provided.');
        }

        $db = array_replace(
            self::DRIVERS['default']['pdo_params'],
            array_replace(self::DRIVERS[$params['driver']]['pdo_params'], $params)
        );

        $dsn = $this->buildConnectionString($db);

        $options = array_replace(
            self::DRIVERS['default']['pdo_options'],
            $options
        );

        return new \PDO($dsn, $db['user'], $db['password'], $options);
    }

    /**
     * Executes a prepared statement query.
     *
     * @param \PDOStatement|string $statement PDOStatement object or query string
     * @param array|scalar         $params    Parameters to bind to query
     *
     * @return \PDOStatement
     * @throws \Shinjin\Pdo\Exception\BadArgumentException
     */
    public function query($statement, $params = array())
    {
        if (!$statement instanceof \PDOStatement && !is_string($statement)) {
            throw new BadArgumentException(
                'Query statement must be a PDOStatement object or a string.'
            );
        }

        if (is_string($statement)) {
            $statement = $this->pdo->prepare($statement);
        }

        $statement->execute((array)$params);

        return $statement;
    }

    /**
     * Creates a SELECT query and executes it.
     *
     * @param string|array $columns       One or more columns to fetch
     * @param string|array $tables        One or more tables to fetch from
     * @param array        $filters       List of column/value pairs to filter
     *                                    by in WHERE clause
     * @param array        $order_columns List of columns to order the results
     *
     * @return \PDOStatement
     */
    public function select(
        $columns,
        $tables,
        array $filters = array(),
        array $order_columns = array())
    {
        $statement = sprintf(
            'SELECT %s FROM %s',
            implode(',', array_map(array($this, 'quote'), (array)$columns)),
            $this->buildQueryTables((array)$tables)
        );
        $params = array();

        if (!empty($filters)) {
            $statement .= ' WHERE ' . $this->buildQueryFilter($filters, $params);
        }

        if (!empty($order_columns)) {
            $order_columns = array_map(array($this, 'quote'), $order_columns);
            $statement .= ' ORDER BY ' . implode(',', $order_columns);
        }

        return $this->query($statement, $params);
    }

    /**
     * Creates an INSERT query and executes it.
     *
     * @param string       $table  Table name
     * @param array        $values List of column/value pairs to INSERT
     * @param string|array $key    Key column(s). If provided, method will
     *                             attempt to update on duplicate key error.
     *
     * @return integer Number of affected rows
     * @throws \Shinjin\Pdo\Exception\BadValueException
     */
    public function insert($table, array $values, $key = null)
    {
        if (empty($values)) {
            throw new BadValueException('Insert values must not be empty.');
        }

        if (count($values) === count($values, COUNT_RECURSIVE)) {
            $values = array($values);
        }

        $statement = $this->buildInsertQuery($table, array_keys(current($values)));
        $affected_rows = 0;

        foreach($values as $set) {
            try {
                $statement = $this->query($statement, array_values($set));
                $affected_rows += $statement->rowCount();
            } catch (\PDOException $e) {
                // state codes for constraint violations
                if (($e->errorInfo[0] === '23000' ||
                     $e->errorInfo[0] === '23505') && $key !== null) {
                    $keys = array_flip((array)$key);
                    $filters = array_intersect_key($set, $keys);

                    if (!in_array(null, $filters, true)) {
                        $affected_rows += $this->update(
                            $table,
                            array_diff_key($set, $keys),
                            $filters    
                        );
                    } else {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }
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
     * @throws \Shinjin\Pdo\Exception\BadValueException
     * @throws \Shinjin\Pdo\Exception\BadFilterException
     */
    public function update($table, array $values, array $filters)
    {
        if (empty($values)) {
            throw new BadValueException('Update values must not be empty.');
        }

        if (empty($filters)) {
            throw new BadFilterException('Update filters must not be empty.');
        }

        $set    = array();
        $params = array();

        foreach ($values as $column => $value) {
            list($column, $operator) = array_pad(explode(' ', $column), 2, '=');
            $column = $this->quote($column);

            if ($operator === '+=' || $operator === '-=') {
                $operator = sprintf('= %s %s', $column, rtrim($operator, '='));
            }

            array_push($set, sprintf('%s %s ?', $column, $operator));
            array_push($params, $value);
        }

        $statement = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quote($table),
            implode(',', $set),
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
     * @throws \Shinjin\Pdo\Exception\BadFilterException
     */
    public function delete($table, array $filters)
    {
        if (empty($filters)) {
            throw new BadFilterException('Delete filters must not be empty.');
        }

        $params = array();
        $statement = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quote($table),
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
        $columns = array_map(function($column){
            return $this->quote(rtrim($column, ' +-='));
        }, $columns);

        $statement = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($table),
            implode(',', $columns),
            implode(',', array_fill(0, count($columns), '?'))
        );

        return $statement;
    }

    /**
     * Constructs a query's WHERE clause.
     *
     * @param array $filters List of column/value pairs to filter by in
     *                       WHERE clause
     * @param array $params  List of values that correspond to the placeholders
     *                       in the filter string
     *
     * @return string Query filter string
     * @throws \Shinjin\Pdo\Exception\BadValueException
     * @throws \Shinjin\Pdo\Exception\BadFilterException
     */
    public function buildQueryFilter(array $filters, array &$params = null)
    {
        $filter = '(';

        $and_or = null;
        foreach($filters as $column => $value) {
            if (is_integer($column) && is_string($value)) {
                if (in_array(strtoupper($value), array('AND', 'OR'))) {
                    if ($and_or === null) {
                        throw new BadFilterException(
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
                $filter .= $this->quote($column) . ' ';

                if (is_scalar($value)) {
                    $filter .= $operator . ' ';
                    if ($params === null) {
                        $filter .= $value;
                    } else {
                        $filter .= '?';
                        array_push($params, $value);
                    }
                } elseif(is_array($value) && is_array($params)) {
                    $filter .= 'IN (' . str_repeat('?,', count($value) - 1) . '?)';
                    $params = array_merge($params, $value);
                } else {
                    throw new BadValueException(
                        'Value must be a scalar or array.'
                    );
                }
            } else {
                if (is_array($value)) {
                    $filter .= $this->buildQueryFilter($value, $params);
                } else {
                    throw new BadFilterException(
                        'Filter must be a key/value pair or array.'
                    );
                }
            }

            $and_or = 'AND';
        }

        return $filter . ')';
    }

    /**
     * Constructs a select query's FROM clause.
     *
     * @param array $tables List of tables and columns to join
     *
     * @return string Table string
     * @throws \Shinjin\Pdo\Exception\BadValueException
     */
    public function buildQueryTables(array $tables)
    {
        $from = $this->quote(array_shift($tables));
        $join = 'INNER JOIN';

        foreach($tables as $table => $value) {
            if (is_integer($table) && is_string($value)) {
                if (in_array(strtoupper($value), self::JOINS)) {
                    $join = strtoupper($value);
                    continue;
                } else {
                    throw new BadValueException(
                        "Value: $value is an invalid join type."
                    );                                    
                }
            }

            if (!is_string($table)) {
                throw new BadValueException('Table name must be a string.');
            }

            if (!is_array($value)) {
                throw new BadValueException('Value must be an array.');
            }

            $from .= sprintf(
                ' %s %s ON %s',
                $join,
                $this->quote($table),
                $this->buildQueryFilter($value)
            );

            $join = 'INNER JOIN';
        }

        return $from;
    }

    /**
     * Quotes an identifier (e.g. column or table name)
     *
     * @param string $value Value to be quoted
     *
     * @return string The quoted value
     */
    public function quote($value)
    {
        if (!ctype_alnum(str_replace('_', '', $value))) {
            if ($value === '*') {
                return $value;
            } elseif (strpos($value, '.') !== false) {
                list($qualifier, $column) = explode('.', $value);
                return $this->quote($qualifier) . '.' . $this->quote($column);
            } elseif (strpos($value, ' ') !== false) {
                list($identifier, $suffix) = explode(' ', $value, 2);
                return $this->quote($identifier) . ' ' . $suffix;
            } else {
                throw new BadValueException(
                    "Value: $value is an invalid identifier."
                );
            }
        }

        $d = $this->driver['quote_delimiter'];
        return $d . str_replace($d, $d.$d, $value) . $d;
    }

    /**
     * Creates a dsn connection string.
     *
     * @param array $params Db connection parameters
     *
     * @return string DSN connection string
     */
    private function buildConnectionString(array $params)
    {
        if (!empty($params['dsn'])) {
            return $params['dsn'];
        }

        $dsn = $params['driver'] . ':';

        $dsn_params = array_intersect_key(
            array_filter($params),
            array_flip(array('dbname', 'host', 'port', 'charset'))
        );

        return $dsn . urldecode(http_build_query($dsn_params, null, ';'));
    }
}
