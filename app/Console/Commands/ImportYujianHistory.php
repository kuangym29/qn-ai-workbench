<?php

namespace App\Console\Commands;

use App\Services\HistoryImport\YujianHistoryImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportYujianHistory extends Command
{
    protected $signature = 'qn:import-yujian-history {--source-root= : Brand source root directory} {--apply : Write the validated baseline}';

    protected $description = 'Preflight or import the confirmed Qingning Yujian copy baseline';

    public function handle(YujianHistoryImporter $importer): int
    {
        $root = $this->option('source-root');
        if (! is_string($root) || $root === '') {
            $this->error('IMPORT_ABORT: --source-root is required.');

            return self::FAILURE;
        }

        try {
            $plan = $importer->prepare($root);
            $importer->preflight($plan);
            $this->line('Project=1 Columns=6 Topics=4 ContentItems=4 Pages=37 Revisions=4 FormalPageVersions=37 SourceReferences=11');
            foreach ($plan['types'] as $type => $count) {
                $this->line("PageType {$type}={$count}");
            }
            $this->line('D06_COMPARE=PASS');
            $this->line('SOURCE_FILES='.$plan['source_files'].'/11 FOUND');
            $this->line('ProductionTasks planned=0 ChannelTasks planned=0');

            if (! $this->option('apply')) {
                $this->line('DB_WRITE=NO');
                $this->line('IMPORT_STATUS=DRY_RUN');

                return self::SUCCESS;
            }

            $this->line('IMPORT_STATUS='.$importer->apply($plan));
            foreach ($importer->counts() as $name => $count) {
                $this->line("{$name}={$count}");
            }
            $this->line('ProductionTasks created by importer=0');
            $this->line('ChannelTasks created by importer=0');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
