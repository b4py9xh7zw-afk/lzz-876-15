<?php

namespace App\Console\Commands;

use App\Models\IdentityVerification;
use App\Models\IdentityVerificationAudit;
use Illuminate\Console\Command;

class PurgeIdentityVerifications extends Command
{
    protected $signature = 'identity:purge';

    protected $description = '清理超过保留期的考前身份核验材料（证件照与人脸照）';

    public function handle(): int
    {
        $count = 0;

        IdentityVerification::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('purged_at')
            ->chunkById(100, function ($verifications) use (&$count) {
                foreach ($verifications as $verification) {
                    $verification->purgeMaterials();

                    IdentityVerificationAudit::create([
                        'verification_id' => $verification->id,
                        'actor_id' => null,
                        'action' => IdentityVerificationAudit::ACTION_PURGE,
                        'detail' => '保留期届满，系统自动清理核验材料',
                        'ip' => null,
                        'created_at' => now(),
                    ]);

                    $count++;
                }
            });

        $this->info("已清理 {$count} 条过期的身份核验材料。");

        return self::SUCCESS;
    }
}
