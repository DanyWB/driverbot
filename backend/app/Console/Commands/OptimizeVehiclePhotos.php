<?php

namespace App\Console\Commands;

use App\Domain\Vehicles\Services\TelegramPhotoOptimizer;
use App\Models\VehiclePhoto;
use Illuminate\Console\Command;
use Throwable;

class OptimizeVehiclePhotos extends Command
{
    protected $signature = 'vehicles:optimize-telegram-photos';

    protected $description = 'Prepare full-resolution Telegram photo copies without changing originals';

    public function handle(TelegramPhotoOptimizer $optimizer): int
    {
        $failed = 0;
        foreach (VehiclePhoto::query()->orderBy('id')->lazyById(50) as $photo) {
            try {
                $optimizer->prepare((string) $photo->disk, (string) $photo->file_path);
                $this->info('Photo '.$photo->id.': ready');
            } catch (Throwable $error) {
                $failed++;
                $this->error('Photo '.$photo->id.': '.$error->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
