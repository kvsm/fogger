<?php

namespace App\Config;

use App\Config\Model\Config;
use Doctrine\DBAL\Connection;

class ConfigFactory
{
    private $connection;

    private $sourceSchemaManager;

    private $tableConfigFactory;

    public function __construct(Connection $connection, TableConfigFactory $tableConfigFactory)
    {
        $this->connection = $connection;
        
    }

    public function createFromDBAL()
    {
        $this->connection->getDatabasePlatform()->registerDoctrineTypeMapping('xml', 'array');
        $this->connection->getDatabasePlatform()->registerDoctrineTypeMapping('_text', 'array');

        $dbalTables = $this->sourceSchemaManager->listTables();

        $config = new Config();

        foreach ($dbalTables as $dbalTable) {
            $config->addTable($dbalTable->getName(), $this->tableConfigFactory->createFromDBALTable($dbalTable));
        }
        return $config;
    }
}
