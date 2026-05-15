<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeEnviosQueueCommand extends Command
{
    protected $signature = 'envios:purge-queue
                            {--execute : Actually delete (default is dry-run)}
                            {--chunk=5000 : Jobs per chunk}';

    protected $description = 'Purge envios queue: delete jobs whose ProspectoEnFlujo has invalid email (null, empty, marked invalid, or known typo)';

    private const DOMINIOS_TYPOS = [
        'gmial.com', 'gmai.com', 'gimeil.com', 'guimei.con', 'gmaill.com',
        'gmeil.com', 'gmail.con', 'gmail.co', 'gamil.com', 'gnail.com',
        'gmsil.com', 'gmil.com', 'gmail.cl',
        'hotmal.com', 'hotmial.com', 'hotmai.com', 'hotmail.con', 'hotmil.com',
        'hotmaill.com', 'hotamil.com', 'homail.com', 'htmail.com',
        'yaho.com', 'yahooo.com', 'yahoo.con', 'yhaoo.com', 'yaoo.com',
        'outlok.com', 'outllok.com', 'outlook.con', 'outlool.com',
        'live.con', 'liv.com',
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '2G');

        $execute = $this->option('execute');
        $chunkSize = (int) $this->option('chunk');
        $targetClass = 'App\\Jobs\\EnviarEmailEtapaProspectoJob';

        $this->info('MODE: ' . ($execute ? 'REAL DELETE' : 'DRY-RUN'));
        $this->info('Loading PEF IDs without valid email (includes typos and marked invalid)...');

        $pefSinEmail = [];
        $loadStart = microtime(true);
        $typoList = implode("','", self::DOMINIOS_TYPOS);

        DB::table('prospecto_en_flujo as pef')
            ->leftJoin('prospectos as p', 'p.id', '=', 'pef.prospecto_id')
            ->where(function ($q) use ($typoList) {
                $q->whereNull('p.email')
                    ->orWhere('p.email', '')
                    ->orWhereNull('p.id')
                    ->orWhere('p.email_invalido', true)
                    ->orWhereRaw("LOWER(SUBSTRING(p.email FROM POSITION('@' IN p.email) + 1)) IN ('{$typoList}')")
                    ->orWhereRaw("LOWER(p.email) LIKE '%.con'")
                    ->orWhereRaw("LOWER(p.email) LIKE '%.cpm'");
            })
            ->select('pef.id')
            ->orderBy('pef.id')
            ->chunk(50000, function ($chunk) use (&$pefSinEmail) {
                foreach ($chunk as $row) {
                    $pefSinEmail[$row->id] = true;
                }
            });

        $this->info('PEF without valid email: ' . number_format(count($pefSinEmail)) . ' (' . round(microtime(true) - $loadStart, 2) . 's)');
        $this->info('Scanning envios queue...');

        $total = 0;
        $basura = 0;
        $deleted = 0;
        $skipped = 0;
        $start = microtime(true);

        DB::table('jobs')->where('queue', 'envios')->orderBy('id')->chunkById($chunkSize, function ($jobs) use (&$total, &$basura, &$deleted, &$skipped, &$pefSinEmail, $execute, $targetClass) {
            $idsToDelete = [];
            foreach ($jobs as $j) {
                $total++;
                $p = json_decode($j->payload, true);
                if (($p['data']['commandName'] ?? '') !== $targetClass) {
                    $skipped++;
                    continue;
                }
                $obj = @unserialize($p['data']['command']);
                if (! $obj || ! isset($obj->prospectoEnFlujoId)) {
                    $skipped++;
                    continue;
                }
                if (isset($pefSinEmail[$obj->prospectoEnFlujoId])) {
                    $basura++;
                    $idsToDelete[] = $j->id;
                }
            }
            if ($execute && $idsToDelete) {
                $deleted += DB::table('jobs')->whereIn('id', $idsToDelete)->delete();
            }
            if ($total % 100000 === 0) {
                $line = date('H:i:s') . ' | Procesados: ' . number_format($total)
                    . ' | Basura: ' . number_format($basura)
                    . ' | Skipped: ' . number_format($skipped);
                if ($execute) {
                    $line .= ' | Borrados: ' . number_format($deleted);
                }
                $this->line($line);
            }
        });

        $this->newLine();
        $this->info('=== FINAL ===');
        $this->info('Total procesados: ' . number_format($total));
        $this->info('Basura identificada: ' . number_format($basura));
        $this->info('Skipped (otros tipos): ' . number_format($skipped));
        if ($execute) {
            $this->info('REALMENTE BORRADOS: ' . number_format($deleted));
        }
        $this->info('Tiempo total: ' . round(microtime(true) - $start, 2) . 's');

        return Command::SUCCESS;
    }
}
