<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class IdentityVerification extends Model
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_SUSPECTED = 'suspected';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PASSED => '通过',
        self::STATUS_SUSPECTED => '疑似',
        self::STATUS_FAILED => '失败',
    ];

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'id_name',
        'id_number_masked',
        'id_number_hash',
        'id_photo_path',
        'face_photo_path',
        'similarity',
        'status',
        'fail_reason',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'attempt',
        'expires_at',
        'purged_at',
    ];

    /**
     * 敏感字段不下发：照片路径与证件号哈希仅服务端可见。
     */
    protected $hidden = [
        'id_photo_path',
        'face_photo_path',
        'id_number_hash',
    ];

    protected $appends = [
        'materials_available',
    ];

    protected $casts = [
        'similarity' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'purged_at' => 'datetime',
        'attempt' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function audits()
    {
        return $this->hasMany(IdentityVerificationAudit::class, 'verification_id');
    }

    /**
     * 核验材料（照片）是否仍在保留期内可授权查看。
     */
    public function getMaterialsAvailableAttribute(): bool
    {
        return $this->purged_at === null && $this->id_photo_path !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * 清理核验材料：删除私有存储中的照片文件并将路径置空。
     * 仅保留脱敏后的元数据用于审计追溯。
     */
    public function purgeMaterials(): void
    {
        foreach (['id_photo_path', 'face_photo_path'] as $key) {
            $path = $this->{$key};
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
            $this->{$key} = null;
        }
        $this->purged_at = now();
        $this->save();
    }
}
