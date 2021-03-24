<?php

namespace App\Fogger\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema as DBAL;
use Doctrine\DBAL\Types\Type;


class SchemaManipulator
{
    private $sourceSchema;

    private $sourceConnection;

    private $targetSchema;

    public function __construct(Connection $source, Connection $target)
    {
        $this->sourceConnection = $source;
        $this->sourceSchema = $source->getSchemaManager();
        $this->targetSchema = $target->getSchemaManager();
    }

    /**
     * @throws DBAL\SchemaException
     */
    public function copySchemaDroppingIndexesAndForeignKeys()
    {
        $sourceTables = $this->sourceSchema->listTables();
        /** @var DBAL\Table $table */
        foreach ($sourceTables as $table) {
            $primary = NULL;
            $auto_increments = NULL;
            foreach ($table->getColumns() as $column) {
                if ($column->getAutoincrement()) {
                    $auto_increments[] = clone $column;
                    $column->setAutoincrement(false);
                }
                if($column->getName() == "id") {
                    if($column->getType() == 'Guid'){
                        $column->setColumnDefinition("uuid DEFAULT uuid_generate_v4() NOT NULL");
                        $column->setDefault(NULL);
                    }
                }
                if( $column->getType() == 'Boolean') {
                    $type = \Doctrine\DBAL\Types\Type::getType('string');
                    $column->setType($type);
                }
                if( $column->getType() == 'String') {
                    $type2 = \Doctrine\DBAL\Types\Type::getType('text');
                    $column->setType($type2);
                }
            }
            foreach ($table->getForeignKeys() as $fk) {
                $table->removeForeignKey($fk->getName());
            }
            foreach ($table->getIndexes() as $index) {
                if ($index->getName() == "PRIMARY") {
                    $primary = $index;
                }
                $table->dropIndex($index->getName());
            }
            if (!$table->hasOption('collate')) {
                $table->addOption(
                    'collate',
                    $this->sourceConnection->getParams()['driverOptions']['collate']
                );
            }
            $this->targetSchema->createTable($table);
            if ($primary !== NULL) {
                $this->targetSchema->createIndex($primary, $table->getName());
            }
            /** @var DBAL\Column $column */
            foreach ($auto_increments as $column) {
                $this->targetSchema->alterTable(
                    new DBAL\TableDiff($table->getName(), [], [new DBAL\ColumnDiff($column->getName(), $column)])
                );
            }
        }
    }

    private function recreateIndexesOnTable(DBAL\Table $table)
    {
        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            if ($index->getName() != "PRIMARY") {
                echo(sprintf(
                    "  - %s's index %s on %s\n",
                    $table->getName(),
                    $index->getName(),
                    implode(', ', $index->getColumns())
                ));
                $indexes[$index->getName()] = $index;
            }

        }
        $this->targetSchema->alterTable(
            new DBAL\TableDiff($table->getName(), [], [], [], $indexes)
        );
    }

    private function recreateForeignKeysOnTable(DBAL\Table $table)
    {
        foreach ($table->getForeignKeys() as $fk) {
            echo(sprintf(
                "  - %s.%s => %s.%s\n",
                $fk->getLocalTableName(),
                implode('_', $fk->getLocalColumns()),
                $fk->getForeignTableName(),
                implode('_', $fk->getForeignColumns())
            ));
            $this->targetSchema->createForeignKey($fk, $table->getName());
        }
    }

    public function recreateIndexes()
    {
        $sourceTables = $this->sourceSchema->listTables();
        foreach ($sourceTables as $table) {
            $this->recreateIndexesOnTable($table);
        }
    }

    public function recreateForeignKeys()
    {
        $sourceTables = $this->sourceSchema->listTables();
        foreach ($sourceTables as $table) {
            $this->recreateForeignKeysOnTable($table);
        }
    }
}
