<?php

namespace App\Fogger\Schema;

use App\Fogger\Schema\RelationGroups\RelationsGroups;
use Doctrine\DBAL\Connection;

class RelationGroupsFactory
{
    private $sourceSchema;
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->sourceSchema = $connection->getSchemaManager();
    }

    public function createFromDBAL()
    {
        $this->connection->getDatabasePlatform()->registerDoctrineTypeMapping('xml', 'string');
        $this->connection->getDatabasePlatform()->registerDoctrineTypeMapping('_text', 'string');
        
        $groups = new RelationsGroups();
        $this->connection->getDatabasePlatform()->registerDoctrineTypeMapping('xml', 'text');
        $this->connection->getDatabasePlatform()->registerDoctrineTypeMapping('_text', 'text');


        foreach ($this->sourceSchema->listTables() as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                $groups->addForeignKey($foreignKey);
            }
        }

        return $groups;
    }
}
