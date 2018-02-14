# Pdo

[![Build Status][ico-travis]][link-travis]

A PDO wrapper, because the internet needed another PDO wrapper.

Use shinjin/pdo if you need a thin wrapper that:
* uses arrays to unpack query values
* uses prepared statements to execute queries and bulk operations
* supports nested transactions

## Install

Via Composer

``` bash
$ composer require shinjin/pdo
```

## Usage

``` php
$connection_parameters = array(
    'driver' => 'mysql',
    'dbname' => 'dbtest',
    'user' => 'shinjin',
    'password' => 'awesomepasswd'
);

$db = new Db($connection_parameters);

$statement  = 'SELECT * FROM guestbook WHERE id = ?';
$parameters = array(1);

$result = $db->query($statement, $parameters)->fetchAll();
```
Please see [Usage](docs/Usage.md) for a complete list of examples.

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Testing

``` bash
$ composer test
```

## Contributing

Bugfixes and updates to support new db drivers are welcome. Please submit pull requests to [Github][link-github].

## Authors

- [Rick Shin][link-author]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-travis]: https://img.shields.io/travis/shinjin/pdo/master.svg?style=flat-square

[link-author]: https://github.com/shinjin
[link-github]: https://github.com/shinjin/pdo
[link-travis]: https://travis-ci.org/shinjin/pdo
