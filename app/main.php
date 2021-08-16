<?php

// wait for database to finish spinning up.
sleep(2);

require_once(__DIR__ . '/vendor/autoload.php');

$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->overload(__DIR__ . '/../.env');


/**
 * Get the database connection. If one already exists, this will return it. If not it will create a new one.
 * @return mysqli - the MySQL database connection.
 */
function getDb() : Programster\PgsqlObjects\PgSqlConnection
{
    static $db = null;

    if ($db === null)
    {
        $db = Programster\PgsqlObjects\PgSqlConnection::create(
            $_ENV['DB_HOST'],
            $_ENV['DB_DATABASE'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASSWORD']
        );
    }

    return $db;
}


/**
 * Create the base/primary tables. These tables are largely redundant and are here for
 * symbolic purposes. They will get replaced by the buffer tables later.
 */
function createBaseTables()
{
    $db = getDb();

    $queries[] =
        'CREATE TABLE "products" (
            "uuid" uuid NOT NULL,
            "name" varchar(255) NOT NULL,
            PRIMARY KEY (uuid)
        )';

    $queries[] =
        'CREATE TABLE substitutions (
            "uuid" uuid NOT NULL,
            product_uuid uuid NOT NULL,
            swapped_product_uuid uuid NOT NULL,
            rank INT NOT NULL,
            PRIMARY KEY (uuid),
            FOREIGN KEY (product_uuid) REFERENCES products(uuid) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (swapped_product_uuid) REFERENCES products(uuid) ON DELETE CASCADE ON UPDATE CASCADE
        )';

    foreach ($queries as $query)
    {
        $result = $db->query($query);

        if ($result === false)
        {
            print "Failed to create base table." . PHP_EOL;
            print $db->error . PHP_EOL;
            die();
        }
    }

    print "Created base tables." . PHP_EOL;
}


/**
 * Creates the "buffer" tables. These have the same structure as the primary ones.
 */
function createBufferTables()
{
    $db = getDb();

    $queries[] =
        'CREATE TABLE products_buffer (
            "uuid" uuid NOT NULL,
            "name" varchar(255) NOT NULL,
            PRIMARY KEY (uuid)
        )';

    $queries[] =
        'CREATE TABLE "substitutions_buffer" (
            uuid uuid NOT NULL,
            product_uuid uuid NOT NULL,
            swapped_product_uuid uuid NOT NULL,
            rank INT NOT NULL,
            PRIMARY KEY ("uuid"),
            FOREIGN KEY (product_uuid) REFERENCES products_buffer(uuid) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (swapped_product_uuid) REFERENCES products_buffer(uuid) ON DELETE CASCADE ON UPDATE CASCADE
        )';

    foreach ($queries as $query)
    {
        $result = $db->query($query);

        if ($result === false)
        {
            print "Failed to create the buffer tables." . PHP_EOL;
            print $db->error . PHP_EOL;
            die();
        }
    }
}


/**
 * Generate a sequential UUIDv4
 * @return string - the generated UUID.
 */
function generateUuid() : string
{
    static $factory = null;

    if ($factory === null)
    {
        $factory = new \Ramsey\Uuid\UuidFactory();

        $generator = new \Ramsey\Uuid\Generator\CombGenerator(
            $factory->getRandomGenerator(),
            $factory->getNumberConverter()
        );

        $codec = new Ramsey\Uuid\Codec\TimestampFirstCombCodec($factory->getUuidBuilder());

        $factory->setRandomGenerator($generator);
        $factory->setCodec($codec);
        Ramsey\Uuid\Uuid::setFactory($factory);
    }

    $uuidString = Ramsey\Uuid\Uuid::uuid4()->toString();
    return $uuidString;
}


/**
 * Create an array to represent a random product.
 * @return array
 */
function createRandomProductRow() : array
{
    return array(
        'uuid' => generateUuid(),
        'name' => Programster\CoreLibs\StringLib::generateRandomString(100, useSpecialChars: false, useNumbers: false),
    );
}


/**
 * Swap the tables over so that the buffer tables become the primary ones that are being used.
 */
function renameTables()
{
    print "Renaming tables..." . PHP_EOL;
    $timeStart = microtime(true);
    $tableRenames = array(
        "products_buffer" => "products",
        "substitutions_buffer" => "substitutions",
    );

    $renameQueries = "";

    foreach ($tableRenames as $from => $to)
    {
        $renameQueries .= "ALTER TABLE {$from} RENAME TO {$to};";
    }

    $db = getDb();

    $transactionQuery = "BEGIN TRANSACTION; DROP TABLE substitutions; DROP TABLE products; {$renameQueries} END TRANSACTION;";
    $result = $db->query($transactionQuery);

    if ($result === false)
    {
        print "Failed to rename the buffer tables" . PHP_EOL;
        print print_r($transaction->getMultiQueryObject()->getErrors(), true) . PHP_EOL;
        //print $renameQuery . PHP_EOL;
        die();
    }

    $timeEnd = microtime(true);
    $timeTaken = $timeEnd - $timeStart;
    print "Renaming tables took {$timeTaken} seconds." . PHP_EOL;
}


/**
 * Outputs the mysql tables to CSV files that can be easily analysed.
 */
function outputData()
{
    $db = getDb();

    $tables = array(
        'products',
        'substitutions',
    );

    foreach ($tables as $table)
    {
        $query = "SELECT * FROM {$table}";
        $result = $db->query($query);
        $rows = pg_fetch_all($result);
        Programster\CoreLibs\CsvLib::convertArrayToCsv(__DIR__ . "/{$table}.csv", $rows, true);
        print "{$table} outputted to {$table}.csv" . PHP_EOL;
    }
}


/**
 * Reset the database to make sure that it is ready.
 */
function resetDatabase()
{
    $tables = array(
        'substitutions',
        'products',
        'substitutions_buffer',
        'products_buffer',
    );

    $db = getDb();

    foreach ($tables as $table)
    {
        $query = "DROP TABLE IF EXISTS \"{$table}\"";
        $result = $db->query($query);

        if ($result === false)
        {
            print "Failed to drop table {$table}" . PHP_EOL;
            print $db->error . PHP_EOL;
            die();
        }
    }

    print "reset database" . PHP_EOL;
}


/**
 * Import a large amount of random data into the buffer tables.
 * @return void.
 */
function importData() : void
{
    print "importing data..." . PHP_EOL;
    $products = [];
    $db = getDb();

    for ($s=0; $s<100; $s++)
    {
        $newProducts = [];
        for ($i=0; $i<1000; $i++)
        {
            $newProduct = createRandomProductRow();
            $newProducts[] = $newProduct;
            $products[] = $newProduct;
        }

        $batchInsertProductsQuery = \Programster\PgsqlObjects\Utils::generateBatchInsertQuery($db, "products_buffer", $newProducts);
        $insertProductsResult = $db->query($batchInsertProductsQuery);

        if ($insertProductsResult === false)
        {
            print "Failed to batch insert products to products buffer" . PHP_EOL;
            print $db->error . PHP_EOL;
            die();
        }
    }

    // create subs
    print "Importing subs..." . PHP_EOL;
    $substitutions = [];

    foreach ($products as $productRow)
    {
        $productSubs = createSubstitutions($productRow, $products);
        $substitutions = [...$substitutions, ...$productSubs];

        if (count($substitutions) > 1000)
        {
            // batch insert subs and empty the buffer
            $batchInsertSubsQuery = \Programster\PgsqlObjects\Utils::generateBatchInsertQuery($db, "substitutions_buffer", $substitutions);
            $subsInsertResult = $db->query($batchInsertSubsQuery);
            if ($subsInsertResult === false)
            {
                print "Failed to batch insert substitutions into the substitutions buffer" . PHP_EOL;
                print $db->error . PHP_EOL;
                die();
            }

            $substitutions = [];
        }
    }

    if (count($substitutions) > 0)
    {
        // now batch insert the subs
        $batchInsertSubsQuery = \Programster\PgsqlObjects\Utils::generateBatchInsertQuery(
            $db,
            "substitutions_buffer",
            $substitutions
        );

        $subsInsertResult = $db->query($batchInsertSubsQuery);

        if ($subsInsertResult === false)
        {
            print "Failed to batch insert substitutions into the substitutions buffer" . PHP_EOL;
            print $db->error . PHP_EOL;
            die();
        }
    }
}


function createSubstitutions(array $productRow, array $allProducts) : array
{
    $substitutionRows = [];
    $start = random_int(0, count($allProducts) - 3);
    $subs = array_slice($allProducts, $start, 3);
    $rank = 0;

    foreach ($subs as $substitutionProduct)
    {
        if ($substitutionProduct['uuid'] !== $productRow['uuid'])
        {
            $rank++;

            $substitutionRows[] = [
                'uuid' => generateUuid(),
                'product_uuid' => $productRow['uuid'],
                'swapped_product_uuid' => $substitutionProduct['uuid'],
                'rank' => $rank,
            ];
        }
    }

    return $substitutionRows;
}


function main()
{
    resetDatabase();
    createBaseTables();
    createBufferTables();
    importData();
    renameTables();
    outputData();
}

main();