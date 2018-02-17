# Usage

## Connecting

The Db class accepts a PDO object or list of parameters. The parameter list must contain at minimum, a supported driver (eg. mysql, pgsql, and sqlite).
``` php
$connection_parameters = array(
    'driver'   => 'mysql', // required
    'dbname'   => 'dbtest',
    'host'     => 'localhost',
    'port'     => '3306',
    'user'     => 'shinjin',
    'password' => 'awesomepasswd'
);

$db = new Db($connection_parameters);
```
The parameter list can also specify the PDO DSN connection string, in which case, parameters like dbname, host, and port are ignored.
``` php
$connection_parameters = array(
    'driver' => 'sqlite',
    'dsn'    => 'sqlite::memory:',
    'dbname' => 'dbtest' // ignored
);

$db = new Db($connection_parameters);
```
## Querying
Fetch records using the **query** method. A successful query returns a PDOStatement object.
``` php
$statement  = 'SELECT author FROM guestbook WHERE id = ?';
$parameters = array(1);

$sth = $db->query($statement, $parameters);

// apply any PDOStatement method to extract the results
$result = $sth->fetchAll();
```

Insert new records using the **insert** method.
``` php
$table  = 'guestbook';
$values = array(
    'id'      => 4,
    'author'  => 'quinn',
    'content' => 'Hello world!',
    'created' => '2016-04-13'
);

$affected_rows = $db->insert($table, $values);
```

Execute bulk inserts by nesting datasets, one for each record.
``` php
$table  = 'guestbook';
$values = array(
    array(
        'id'      => 4,
        'author'  => 'quinn',
        'content' => 'Hello world!',
        'created' => '2016-04-13'
    ),
    array(
        'id'      => 5,
        'author'  => 'shinjin',
        'content' => 'Welcome!',
        'created' => '2016-04-13'
    )
);

$affected_rows = $db->insert($table, $values);

// 2 affected rows
```

Update existing records using the **update** method.
``` php
$table   = 'guestbook';
$values  = array('author' => 'joey');
$filters = array('id' => 1);

$affected_rows = $db->update($table, $values, $filters);
```

Delete records using the **delete** method.
``` php
$table   = 'guestbook';
$filters = array('id' => 1);

$affected_rows = $db->delete($table, $filters);
```

## Filtering Queries
The update and delete methods must include the **filters** argument. Filters compare key/value pairs by equality.
``` php
$filters = array('id' => 1);

// (id = 1)
```

Filter by other operators (eg. <>, >, >=) by specifying them in the key.
``` php
$filters = array('id <>' => 1);

// (id <> 1)
```

By default, multiple filters are separated with the AND operator.
``` php
$filters = array(
    'id'        => 1,
    'created >' => '2010-04-30'
);

// (id = 1 AND created > '2010-4-30')
```

Separate filters with the OR operator by including an 'or' value between filters.
``` php
$filters = array(
    'id'        => 1,
    'or',
    'created >' => '2010-04-30'
);

// (id = 1 OR created > '2010-4-30')
```

Organize filters into groups using nested arrays.
``` php
$filters = array(
    'id' => 1,
    array(
        'author'    => 'joe',
        'or',
        'created >' => '2010-04-30'
    )
;

// (id = 1 AND (author = 'joe' OR created > '2010-4-30'))
```

Nested arrays can also be used for filters with conflicting keys.
``` php
$filters = array(
    'id' => 1,
    array(
        'author' => 'joe',
        'or',
        array('author' => 'nancy')
    )
;

// (id = 1 AND (author = 'joe' OR (author = 'nancy')))
```
## Complex Queries
Use the **query** method for more complex queries. The method's **statement** argument accepts a query statement or PDO statement object allowing performant bulk operations.
``` php
$statement = 'UPDATE guestbook SET author = ? WHERE id = ?';
$param_sets = array(
    array('joey', 1),
    array('nance', 2)
);

foreach($param_sets as $params) {
    $statement = $db->query($statement, $params);
    // $statement is now a pdo statement object
}

// pdo prepare is called on the first iteration only
```

The Db class delegates all other method calls to the PDO object.
``` php
$db = new Db($connection_parameters);

$db->errorCode();
$db->errorInfo();
$db->exec($statement);
etc.

// these will all work
```
## Transactions
Nested transactions will work as expected (unlike the PDO methods).
``` php
$db->beginTransaction();
$db->beginTransaction();

// bunch of transactional queries

$db->commit(); // second transaction
$db->commit(); // first transaction
```

