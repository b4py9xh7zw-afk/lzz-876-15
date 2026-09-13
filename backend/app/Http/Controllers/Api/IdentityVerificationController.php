<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\IdentityVerification;
use App\Models\IdentityVerificationAudit;
use App\Services\FaceCompareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class IdentityVerificationController extends Controller
{
    public function __construct(protected FaceCompareService $faceCompare)
    {
    }

    /**
     * 考生查询自己在某试卷下的最新核验状态。
     */
    public function status(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();

        $latest = IdentityVerification::where('user_id', $user->id)
            ->where('exam_paper_id', $examPaper->id)
            ->orderBy('id', 'desc')
            ->first();

        $attempts = IdentityVerification::where('user_id', $user->id)
            ->where('exam_paper_id', $examPaper->id)
            ->count();

        $maxAttempts = (int) config('identity.max_attempts', 3);

        return response()->json([
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
            ],
            'verification' => $latest,
            'can_start' => $latest !== null && $latest->status === IdentityVerification::STATUS_PASSED,
            'attempts_used' => $attempts,
            'attempts_left' => max(0, $maxAttempts - $attempts),
            'retention_days' => (int) config('identity.retention_days', 7),
        ]);
    }

    /**
     * 考生提交证件照 + 人脸照，系统自动比对并分为 通过/疑似/失败。
     */
    public function submit(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'id_name' => 'required|string|max:50',
            'id_number' => 'required|string|min:6|max:30',
            'id_photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
            'face_photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ], [
            'id_name.required' => '请填写证件姓名',
            'id_number.required' => '请填写证件号码',
            'id_photo.required' => '请上传证件照片',
            'id_photo.image' => '证件照片必须是图片文件',
            'face_photo.required' => '请采集人脸照片',
            'face_photo.image' => '人脸照片必须是图片文件',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $latest = IdentityVerification::where('user_id', $user->id)
            ->where('exam_paper_id', $examPaper->id)
            ->orderBy('id', 'desc')
            ->first();

        if ($latest && $latest->status === IdentityVerification::STATUS_PASSED) {
            return response()->json(['message' => '您已通过本场考试的身份核验，无需重复提交', 'code' => 'ALREADY_PASSED'], 409);
        }

        if ($latest && $latest->status === IdentityVerification::STATUS_SUSPECTED) {
            return response()->json(['message' => '您的核验材料正在等待监考老师人工确认，请稍候', 'code' => 'UNDER_REVIEW'], 409);
        }

        $maxAttempts = (int) config('identity.max_attempts', 3);
        $attempts = IdentityVerification::where('user_id', $user->id)
            ->where('exam_paper_id', $examPaper->id)
            ->count();

        if ($attempts >= $maxAttempts) {
            return response()->json([
                'message' => "本场考试核验次数已用完（{$maxAttempts} 次），请联系监考老师",
                'code' => 'NO_ATTEMPTS_LEFT',
            ], 429);
        }

        // 私有存储（非公开目录），随机文件名，仅能通过授权接口访问
        $dir = "identity/{$examPaper->id}/{$user->id}";
        $idPhotoPath = $request->file('id_photo')->store($dir, 'local');
        $facePhotoPath = $request->file('face_photo')->store($dir, 'local');

        $similarity = $this->faceCompare->compare(
            Storage::disk('local')->path($idPhotoPath),
            Storage::disk('local')->path($facePhotoPath)
        );

        if ($similarity === null) {
            Storage::disk('local')->delete([$idPhotoPath, $facePhotoPath]);
            return response()->json(['message' => '照片无法解析，请重新上传清晰的证件照与人脸照'], 422);
        }

        $status = $this->faceCompare->decideStatus($similarity);

        $verification = IdentityVerification::create([
            'user_id' => $user->id,
            'exam_paper_id' => $examPaper->id,
            'id_name' => $request->input('id_name'),
            'id_number_masked' => $this->maskIdNumber($request->input('id_number')),
            'id_number_hash' => hash_hmac('sha256', trim($request->input('id_number')), (string) config('app.key')),
            'id_photo_path' => $idPhotoPath,
            'face_photo_path' => $facePhotoPath,
            'similarity' => $similarity,
            'status' => $status,
            'fail_reason' => $status === IdentityVerification::STATUS_FAILED ? '人脸与证件照比对相似度过低' : null,
            'attempt' => $attempts + 1,
            'expires_at' => now()->addDays((int) config('identity.retention_days', 7)),
        ]);

        $this->logAudit($verification, $user->id, IdentityVerificationAudit::ACTION_SUBMIT, $request, "自动比对相似度 {$similarity}，结果 {$status}");

        return response()->json([
            'message' => match ($status) {
                IdentityVerification::STATUS_PASSED => '身份核验通过',
                IdentityVerification::STATUS_SUSPECTED => '核验结果为疑似，已提交监考老师人工确认',
                default => '身份核验未通过',
            },
            'verification' => $verification,
            'attempts_left' => max(0, $maxAttempts - $attempts - 1),
        ]);
    }

    /**
     * 监考端：核验记录列表（管理员全部，教师仅自己创建的试卷）。
     * 列表只返回脱敏元数据，不含照片内容。
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$this->isProctor($user)) {
            return response()->json(['message' => '无权限访问监考核验'], 403);
        }

        $query = IdentityVerification::with([
            'user:id,username,real_name,email',
            'examPaper:id,title,created_by',
        ])->orderBy('id', 'desc');

        if (!$user->isAdmin()) {
            $query->whereHas('examPaper', function ($q) use ($user) {
                $q->where('created_by', $user->id);
            });
        }

        if ($request->filled('status') && in_array($request->status, array_keys(IdentityVerification::STATUSES), true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('exam_paper_id')) {
            $query->where('exam_paper_id', $request->exam_paper_id);
        }

        $verifications = $query->paginate((int) $request->input('per_page', 15));

        return response()->json(['verifications' => $verifications]);
    }

    /**
     * 监考端：单条核验详情（脱敏元数据 + 审核信息）。
     */
    public function show(Request $request, IdentityVerification $verification)
    {
        if (!$this->canProctor($request->user(), $verification)) {
            return response()->json(['message' => '无权限查看该核验记录'], 403);
        }

        $this->purgeIfExpired($verification);

        $verification->load([
            'user:id,username,real_name,email',
            'examPaper:id,title,created_by',
            'reviewer:id,username',
        ]);

        return response()->json(['verification' => $verification]);
    }

    /**
     * 监考端：授权查看核验照片。每次查看均写入审计日志。
     */
    public function photo(Request $request, IdentityVerification $verification, string $type)
    {
        if (!in_array($type, ['id', 'face'], true)) {
            return response()->json(['message' => '无效的照片类型'], 422);
        }

        if (!$this->canProctor($request->user(), $verification)) {
            return response()->json(['message' => '无权限查看该核验材料'], 403);
        }

        $this->purgeIfExpired($verification);
        $verification->refresh();

        $path = $type === 'id' ? $verification->id_photo_path : $verification->face_photo_path;

        if ($verification->purged_at !== null || !$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['message' => '核验材料已过保留期并清理，无法查看'], 410);
        }

        $this->logAudit(
            $verification,
            $request->user()->id,
            $type === 'id' ? IdentityVerificationAudit::ACTION_VIEW_ID_PHOTO : IdentityVerificationAudit::ACTION_VIEW_FACE_PHOTO,
            $request
        );

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => Storage::disk('local')->mimeType($path) ?: 'image/jpeg',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * 监考端：人工确认疑似记录（通过 / 驳回）。
     */
    public function review(Request $request, IdentityVerification $verification)
    {
        if (!$this->canProctor($request->user(), $verification)) {
            return response()->json(['message' => '无权限审核该核验记录'], 403);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($verification->status !== IdentityVerification::STATUS_SUSPECTED) {
            return response()->json(['message' => '仅疑似状态的记录需要人工确认'], 409);
        }

        $approve = $request->input('action') === 'approve';
        $note = $request->input('note');

        $verification->update([
            'status' => $approve ? IdentityVerification::STATUS_PASSED : IdentityVerification::STATUS_FAILED,
            'fail_reason' => $approve ? null : ($note ?: '人工审核未通过'),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->logAudit(
            $verification,
            $request->user()->id,
            $approve ? IdentityVerificationAudit::ACTION_REVIEW_APPROVE : IdentityVerificationAudit::ACTION_REVIEW_REJECT,
            $request,
            $note
        );

        return response()->json([
            'message' => $approve ? '已人工确认通过' : '已驳回该核验',
            'verification' => $verification,
        ]);
    }

    /**
     * 监考端：查看某条核验记录的审计日志（谁、何时、做了什么）。
     */
    public function audits(Request $request, IdentityVerification $verification)
    {
        if (!$this->canProctor($request->user(), $verification)) {
            return response()->json(['message' => '无权限查看该核验记录'], 403);
        }

        $audits = $verification->audits()
            ->with('actor:id,username')
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get();

        return response()->json(['audits' => $audits]);
    }

    protected function isProctor($user): bool
    {
        return in_array($user->role, ['admin', 'teacher'], true);
    }

    /**
     * 管理员可审核全部；教师仅可审核自己创建的试卷下的记录。
     */
    protected function canProctor($user, IdentityVerification $verification): bool
    {
        if (!$this->isProctor($user)) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        return (int) $verification->examPaper()->value('created_by') === (int) $user->id;
    }

    /**
     * 到期即时清理（兜底，定时任务 identity:purge 为主）。
     */
    protected function purgeIfExpired(IdentityVerification $verification): void
    {
        if ($verification->purged_at === null && $verification->isExpired()) {
            $verification->purgeMaterials();
            IdentityVerificationAudit::create([
                'verification_id' => $verification->id,
                'actor_id' => null,
                'action' => IdentityVerificationAudit::ACTION_PURGE,
                'detail' => '保留期届满，系统自动清理核验材料',
                'ip' => null,
                'created_at' => now(),
            ]);
        }
    }

    protected function logAudit(IdentityVerification $verification, ?int $actorId, string $action, Request $request, ?string $detail = null): void
    {
        IdentityVerificationAudit::create([
            'verification_id' => $verification->id,
            'actor_id' => $actorId,
            'action' => $action,
            'detail' => $detail,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * 证件号脱敏：仅保留前 3 位与后 2 位，其余以 * 代替。
     */
    protected function maskIdNumber(string $number): string
    {
        $number = trim($number);
        $len = mb_strlen($number);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        if ($len <= 6) {
            return mb_substr($number, 0, 1) . str_repeat('*', $len - 2) . mb_substr($number, -1);
        }
        return mb_substr($number, 0, 3) . str_repeat('*', $len - 5) . mb_substr($number, -2);
    }
}
