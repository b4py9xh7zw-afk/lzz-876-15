<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityVerificationAudit extends Model
{
    public $timestamps = false;

    public const ACTION_SUBMIT = 'submit';
    public const ACTION_VIEW_ID_PHOTO = 'view_id_photo';
    public const ACTION_VIEW_FACE_PHOTO = 'view_face_photo';
    public const ACTION_REVIEW_APPROVE = 'review_approve';
    public const ACTION_REVIEW_REJECT = 'review_reject';
    public const ACTION_PURGE = 'purge';

    protected $fillable = [
        'verification_id',
        'actor_id',
        'action',
        'detail',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function verification()
    {
        return $this->belongsTo(IdentityVerification::class, 'verification_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
