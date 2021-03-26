<?php

namespace App\Command;

use App\Config\ConfigLoader;
use App\Fogger\Data\ChunkCache;
use App\Fogger\Data\ChunkError;
use App\Fogger\Recipe\RecipeFactory;
use App\Fogger\Refine\Refiner;
use App\Fogger\Schema\SchemaManipulator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class FinishCommand extends Command
{
    protected $schemaManipulator;

    protected $chunkCache;

    protected $chunkError;

    protected $recipeFactory;

    protected $recipe = null;

    private $refiner;

    public function __construct(
        SchemaManipulator $schemaManipulator,
        Refiner $refiner,
        ChunkCache $chunkCache,
        ChunkError $chunkError,
        RecipeFactory $recipeFactory
    ) {
        $this->schemaManipulator = $schemaManipulator;
        $this->refiner = $refiner;
        $this->chunkCache = $chunkCache;
        $this->chunkError = $chunkError;
        $this->recipeFactory = $recipeFactory;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('fogger:finish')
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Where should the command look for a config file. Defaults to fogger.yaml in root folder.',
                ConfigLoader::DEFAULT_FILENAME
            )
            ->setDescription('Recreates all the indexes and foreign keys in the target');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Fogger finish procedure');

        $io = new SymfonyStyle($input, $output);
        if ($this->chunkCache->getProcessedCount() < $this->chunkCache->getPublishedCount()) {
            $this->outputMessage(
                sprintf(
                    "We are still working on it, please try again later (%d/%d)",
                    $this->chunkCache->getProcessedCount(),
                    $this->chunkCache->getPublishedCount()
                ),
                $io,
                'fg=black;bg=yellow'
            );

            return -1;
        }

        if ($this->chunkError->hasError()) {
            $this->outputMessage(sprintf("There has been an error:\n\n%s", $this->chunkError->getError()), $io);

            return -1;
        }

        try {
            $output->writeln(' - refining database...');
            $this->refiner->refine(
                $this->recipe ?? $this->recipeFactory->createRecipe($input->getOption('file'))
            );
            $output->writeln(' - recreating indexes...');
            $this->schemaManipulator->recreateIndexes();
            $output->writeln(' - recreating foreign keys...');
            $this->schemaManipulator->recreateForeignKeys();
        } catch (\Exception $exception) {
            $this->outputMessage(sprintf("There has been an error:\n\n%s", $exception->getMessage()), $io);

            return -1;
        }

        $this->outputMessage('Data moved, constraints and indexes recreated.', $io, 'fg=black;bg=green');

        $output->writeln('Fixing column definitions...');
        $columnTypes = Yaml::parseFile('/fogger/columns.yaml');

        $target = $this->schemaManipulator->getTargetConnection();
        $host = $target->getHost();
        $port = $target->getPort();
        $dbname = $target->getDatabase();
        $user = $target->getUsername();
        $password = $target->getPassword();
        
        $conn = pg_connect("host=$host port=$port dbname=$dbname user=$user password=$password");

        $output->writeln("Booleans");
        foreach ($columnTypes['boolean'] as $table => $columns) {
            $output->writeln("Table: $table");
            foreach ($columns as $column => $attrs) {
                $output->writeln("Column: $column");
                $default = $attrs['default'];
                $defaultStr = ($default == '1') ? "TRUE" : "FALSE";
                $output->writeln("Default: $defaultStr");
                pg_query($conn, "
                    ALTER TABLE $table
                    ALTER COLUMN $column DROP DEFAULT,
                    ALTER COLUMN $column TYPE boolean USING
                        CASE WHEN $column='' THEN FALSE
                        ELSE TRUE
                    END,
                    ALTER COLUMN $column SET DEFAULT $defaultStr;
                ");
            }
        }

        $output->writeln("Strings");
        foreach ($columnTypes['string'] as $table => $columns) {
            $output->writeln("Table: $table");
            foreach ($columns as $column => $attrs) {
                $output->writeln("Column: $column");
                $default = $attrs['default'];
                $output->writeln("Default: $default");
                if ($default == '{}') {
                pg_query($conn, "
                    ALTER TABLE $table
                    ALTER COLUMN $column DROP DEFAULT,
                    ALTER COLUMN $column TYPE character varying[] USING $column::character varying[],
                    ALTER COLUMN $column SET DEFAULT '{}';
                ");
                }
            }
        }

        $output->writeln("Texts");
        foreach ($columnTypes['text'] as $table => $columns) {
            $output->writeln("Table: $table");
            foreach ($columns as $column => $attrs) {
                if (!array_key_exists($column, $columnTypes['string'][$table])) {
                    $output->writeln("Column: $column");
                    $default = $attrs['default'];
                    $output->writeln("Default: $default");
                    if ($default == '{}') {
                        pg_query($conn, "
                            ALTER TABLE $table
                            ALTER COLUMN $column DROP DEFAULT,
                            ALTER COLUMN $column TYPE text[] USING $column::text[],
                            ALTER COLUMN $column SET DEFAULT '{}';
                        ");
                    }
                }
            }
        }

        return 0;
    }

    protected function outputMessage(string $message, SymfonyStyle $io, string $style = 'fg=white;bg=red')
    {
        $io->block($message, null, $style, ' ', true);
    }
}
