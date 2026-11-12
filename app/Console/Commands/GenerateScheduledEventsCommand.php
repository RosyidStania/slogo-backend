<?php

namespace App\Console\Commands;

use App\Services\ScheduledEventGenerator;
use Illuminate\Console\Command;

class GenerateScheduledEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:generate-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Otomatis membuat acara terjadwal, menutup acara 2 jam setelah selesai, dan menandai sisa target belum absen sebagai alpa satu kali';

    /**
     * Execute the console command.
     */
    public function handle(ScheduledEventGenerator $generator): int
    {
        $result = $generator->run();

        $this->info("Scheduled event check selesai: {$result['created']} acara dibuat, {$result['closed']} acara ditutup, {$result['alpa_marked']} peserta dialpakan.");

        return self::SUCCESS;
    }
}
