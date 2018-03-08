<?php
namespace Shinjin\Pdo\Tests;

use Shinjin\Pdo\Db;

class DbTest extends \PHPUnit_Extensions_Database_TestCase
{

    private static $pdo = null;

    private $conn = null;
    private $db;

    final public function getConnection()
    {
        if ($this->conn === null) {
            if (self::$pdo === null) {
                $driver = 'db.' . getenv('DB');

                if (empty($GLOBALS[$driver . '.dsn'])) {
                    $dsn = sprintf(
                        '%s:host=%s;port=%s;dbname=%s',
                        getenv('DB'),
                        $GLOBALS[$driver . '.host'],
                        $GLOBALS[$driver . '.port'],
                        $GLOBALS[$driver . '.dbname']
                    );
                } else {
                    $dsn = $GLOBALS[$driver . '.dsn'];
                }

                self::$pdo = new \PDO(
                    $dsn,
                    $GLOBALS[$driver . '.user'],
                    $GLOBALS[$driver . '.password'],
                    array(
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_EMULATE_PREPARES => false,
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                    )
                );
            }

            self::$pdo->query('CREATE TABLE IF NOT EXISTS guestbook (
                id      integer primary key,
                content varchar(255),
                author  varchar(255),
                created date
            )');

            $this->conn = $this->createDefaultDBConnection(self::$pdo);
        }

        return $this->conn;
    }

    public function getDataSet()
    {
        return $this->createArrayDataSet(array(
            'guestbook' => array(
                array('id' => 1, 'content' => 'Hello buddy!', 'author' => 'joe', 'created' => '2010-04-24'),
                array('id' => 2, 'content' => 'I like it!', 'author' => 'nancy', 'created' => '2010-04-26'),
                array('id' => 3, 'content' => 'Hello world!', 'author' => 'suzy', 'created' => '2010-05-01')
            ),
        ));
    }

    public function setUp()
    {
        parent::setUp();

        $this->db = new Db(self::$pdo);
    }

    public function tearDown()
    {
        parent::tearDown();

        self::$pdo = null;
    }

    /**
     * @covers \Shinjin\Pdo\Db::__construct
     */
    public function testIsConstructedWhenPdoArgumentIsObject()
    {
        $this->assertInstanceOf('Shinjin\\Pdo\\Db', $this->db);
    }

    /**
     * @covers \Shinjin\Pdo\Db::__construct
     * @covers \Shinjin\Pdo\Db::connect
     */
    public function testIsConstructedWhenPdoArgumentIsParameterArray()
    {
        $params = array(
            'driver' => 'sqlite',
            'dsn'  => 'sqlite::memory:',
            'user' => null,
            'password' => null
        );

        $this->assertInstanceOf('Shinjin\\Pdo\\Db', new Db($params));
    }

    /**
     * @covers \Shinjin\Pdo\Db::__construct
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenConstructorArgumentIsInvalid()
    {
        new Db(null);
    }

    /**
     * @covers \Shinjin\Pdo\Db::__call
     */
    public function testDelegatesToPdoObject()
    {
        $this->assertSame('00000', $this->db->errorCode());
    }

    /**
     * @covers \Shinjin\Pdo\Db::__call
     * @expectedException \BadMethodCallException
     */
    public function testThrowsExceptionWhenMethodDoesNotExist()
    {
        $this->db->badmethod();
    }

    /**
     * @covers \Shinjin\Pdo\Db::connect
     * @covers \Shinjin\Pdo\Db::buildConnectionString
     */
    public function testConnectsToDbAndReturnsPdoObject()
    {
        $params = array(
            'driver' => 'sqlite',
            'dsn'  => 'sqlite::memory:',
            'user' => null,
            'password' => null
        );

        $this->assertInstanceOf('\\PDO', $this->db->connect($params));
    }

    /**
     * @covers \Shinjin\Pdo\Db::connect
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenDriverParameterIsInvalid()
    {
        $params = array('driver' => 'baddriver');

        $this->db->connect($params);
    }

    /**
     * @covers \Shinjin\Pdo\Db::connect
     * @covers \Shinjin\Pdo\Db::buildConnectionString
     * @expectedException \PDOException
     */
    public function testThrowsExceptionWhenDbParametersAreInvalid()
    {
        $params = array(
            'driver' => 'mysql',
            'host' => 'badhost'
        );

        $this->db->connect($params);
    }

    /**
     * @covers \Shinjin\Pdo\Db::query
     * @dataProvider queryReturnsPdoStatementHandleDataProvider
     */
    public function testQueryReturnsPdoStatementHandle($statement, $params)
    {
        $sth = $this->db->query($statement, $params);

        $this->assertInstanceOf('\\PDOStatement', $sth);
    }

    public function queryReturnsPdoStatementHandleDataProvider()
    {
        return array(
            'query without params' => array(
                'SELECT * FROM guestbook WHERE id = 1',
                array()
            ),
            'query with scalar param' => array(
                'SELECT * FROM guestbook WHERE id = ?',
                1
            ),
            'query with array param 1' => array(
                'SELECT * FROM guestbook WHERE id = ?',
                array(1)
            ),
            'query with array param 2' => array(
                'SELECT * FROM guestbook WHERE id = :id',
                array(':id' => 1)
            ),
        );
    }

    /**
     * @covers \Shinjin\Pdo\Db::query
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenQueryStatementIsInvalid()
    {
        $this->db->query(null);
    }

    /**
     * @covers \Shinjin\Pdo\Db::query
     * @expectedException \PDOException
     */
    public function testThrowsExceptionWhenQueryIsInvalid()
    {
        $this->db->query('SELECT * FROM invalid_table');
    }

    /**
     * @covers \Shinjin\Pdo\Db::insert
     * @covers \Shinjin\Pdo\Db::buildInsertQuery
     * @covers \Shinjin\Pdo\Db::query
     */
    public function testInsertsRow()
    {
        $data = array(
            'id' => 4,
            'content' => 'Hello world!',
            'author' => 'quinn',
            'created' => '2016-04-13'
        );
        $this->db->insert('guestbook', $data);

        $actual = $this->db->query(
            'SELECT * FROM guestbook WHERE id = ' . $data['id']
        )->fetchAll();

        // pdo/sqlite is stupid and casts integers to strings
        // so use assertEquals here
        $this->assertEquals(array($data), $actual);
    }

    /**
     * @covers \Shinjin\Pdo\Db::insert
     * @covers \Shinjin\Pdo\Db::buildInsertQuery
     * @covers \Shinjin\Pdo\Db::query
     */
    public function testInsertsMultipleRows()
    {
        $data = array(
            array(
                'id' => 4,
                'content' => 'Hello world!',
                'author' => 'quinn',
                'created' => '2016-04-13'
            ),
            array(
                'id' => 5,
                'content' => null,
                'author'  => null,
                'created' => null
            )
        );
        $this->db->insert('guestbook', $data);

        $actual = $this->db->query(
            'SELECT * FROM guestbook WHERE id IN (4, 5)'
        )->fetchAll();

        $this->assertEquals($data, $actual);
    }

    /**
     * @covers \Shinjin\Pdo\Db::insert
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenInsertDataIsEmpty()
    {
        $this->db->insert('guestbook', array());
    }

    /**
     * @covers \Shinjin\Pdo\Db::update
     * @covers \Shinjin\Pdo\Db::buildQueryFilter
     * @covers \Shinjin\Pdo\Db::query
     * @dataProvider testUpdatesRowDataProvider
     */
    public function testUpdatesRowAndReturnsAffectedRows($data, $expected)
    {
        $affected_rows = $this->db->update('guestbook', $data, array('id' => 1));

        $this->assertSame(1, $affected_rows);

        $actual = $this->db->query(
            'SELECT author FROM guestbook WHERE id = 1'
        )->fetchAll();

        $this->assertSame(array($expected), $actual);
    }

    public function testUpdatesRowDataProvider()
    {
        return array(
            'update with default data' => array(
                array('author' => 'joey'),
                array('author' => 'joey')
            ),
            'update with verbose data' => array(
                array('author =' => 'joey'),
                array('author'   => 'joey')
            )
        );
    }

    /**
     * @covers \Shinjin\Pdo\Db::update
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenUpdateDataIsEmpty()
    {
        $this->db->update('guestbook', array(), array());
    }

    /**
     * @covers \Shinjin\Pdo\Db::update
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenUpdateFiltersAreEmpty()
    {
        $this->db->update('guestbook', array('author' => 'joey'), array());
    }

    /**
     * @covers \Shinjin\Pdo\Db::delete
     * @covers \Shinjin\Pdo\Db::buildQueryFilter
     * @covers \Shinjin\Pdo\Db::query
     */
    public function testDeletesRowAndReturnsAffectedRows()
    {
        $affected_rows = $this->db->delete('guestbook', array('id' => 1));

        $this->assertSame(1, $affected_rows);

        $actual = $this->db->query('SELECT id FROM guestbook')->fetchAll();

        $this->assertEquals(array(array('id' => 2), array('id' => 3)), $actual);
    }

    /**
     * @covers \Shinjin\Pdo\Db::delete
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenDeleteFiltersAreEmpty()
    {
        $this->db->delete('guestbook', array());
    }

    /**
     * @covers \Shinjin\Pdo\Db::beginTransaction
     */
    public function testStartsTransaction()
    {
        $this->assertTrue($this->db->beginTransaction());
        $this->assertAttributeEquals(1, 'transaction_level', $this->db);
    }

    /**
     * @covers \Shinjin\Pdo\Db::beginTransaction
     */
    public function testStartsNestedTransaction()
    {
        $this->db->beginTransaction();

        $this->assertTrue($this->db->beginTransaction());
        $this->assertAttributeEquals(2, 'transaction_level', $this->db);
    }

    /**
     * @covers \Shinjin\Pdo\Db::commit
     */
    public function testCommitsTransaction()
    {
        $this->db->beginTransaction();

        $this->assertTrue($this->db->commit());
    }

    /**
     * @covers \Shinjin\Pdo\Db::commit
     */
    public function testCommitsNestedTransaction()
    {
        $this->db->beginTransaction();
        $this->db->beginTransaction();

        $this->assertTrue($this->db->commit());
        $this->assertAttributeEquals(1, 'transaction_level', $this->db);
    }

    /**
     * @covers \Shinjin\Pdo\Db::rollBack
     */
    public function testRollsBackTransaction()
    {
        $this->db->beginTransaction();

        $this->assertTrue($this->db->rollBack());
    }

    /**
     * @covers \Shinjin\Pdo\Db::rollBack
     */
    public function testRollsBackNestedTransaction()
    {
        $this->db->beginTransaction();
        $this->db->beginTransaction();

        $this->assertTrue($this->db->rollBack());
        $this->assertAttributeEquals(1, 'transaction_level', $this->db);
    }

    /**
     * @covers \Shinjin\Pdo\Db::buildInsertQuery
     */
    public function testBuildsInsertQuery(){
        $expected = 'INSERT INTO guestbook (id,content,author,created) ' .
                    'VALUES (?,?,?,?)';

        $columns = array('id', 'content', 'author', 'created');
        $actual = $this->db->buildInsertQuery('guestbook', $columns);

        $this->assertSame($expected, $actual);
    }

    /**
     * @covers \Shinjin\Pdo\Db::buildQueryFilter
     * @dataProvider testBuildQueryFilterWorksDataProvider
     */
    public function testBuildQueryFilterWorks(
        $filters,
        $expected_string,
        $expected_params
    ){
        $actual_params = array();
        $actual_string = $this->db->buildQueryFilter($filters, $actual_params);

        $this->assertSame($expected_string, $actual_string);
        $this->assertSame($expected_params, $actual_params);
    }

    public function testBuildQueryFilterWorksDataProvider()
    {
        return array(
            'default filter' => array(
                array('id' => 1),
                '(id = ?)',
                array(1)
            ),
            'verbose filter' => array(
                array('id <>' => 1),
                '(id <> ?)',
                array(1)
            ),
            'filters with implicit AND operator' => array(
                array('id' => 1, 'created >' => '2010-04-31'),
                '(id = ? AND created > ?)',
                array(1, '2010-04-31')
            ),
            'filters with OR operator' => array(
                array('id' => 1, 'or', 'created >' => '2010-04-31'),
                '(id = ? OR created > ?)',
                array(1, '2010-04-31')
            ),
            'nested filters' => array(
                array(
                    'id' => 1, 
                    array(
                        'author' => 'joe',
                        'or',
                        array('author' => 'suzy')
                    )
                ),
                '(id = ? AND (author = ? OR (author = ?)))',
                array(1, 'joe', 'suzy')
            ),
        );
    }

    /**
     * @covers \Shinjin\Pdo\Db::buildQueryFilter
     * @expectedException \Shinjin\Pdo\Exception\InvalidArgumentException
     * @dataProvider testBuildQueryFilterThrowsExceptionDataProvider
     */
    public function testBuildQueryFilterThrowsExceptionWhenFiltersAreInvalid(
        $filters
    ){
        $this->db->buildQueryFilter($filters);
    }

    public function testBuildQueryFilterThrowsExceptionDataProvider()
    {
        return array(
            'filter starts with operator' => array(
                array('or')
            ),
            'filter is invalid string' => array(
                array('invalid')
            )
        );
    }

}
