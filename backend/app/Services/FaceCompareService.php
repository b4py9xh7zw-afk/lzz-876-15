<?php

namespace App\Services;

/**
 * 人脸比对服务。
 *
 * 当前实现为内置的轻量级图像相似度算法（16x16 灰度均值哈希 + 汉明距离），
 * 用于本地/演示环境给出确定性的比对分值。
 *
 * 生产环境应将 compare() 替换为合规的人脸比对服务（如阿里云/腾讯云人脸核身），
 * 本类是唯一集成点，阈值与状态判定逻辑保持不变。
 */
class FaceCompareService
{
    private const HASH_SIZE = 16;

    /**
     * 比对两张人脸照片，返回 0-100 的相似度；无法解析时返回 null。
     */
    public function compare(string $pathA, string $pathB): ?float
    {
        $hashA = $this->averageHash($pathA);
        $hashB = $this->averageHash($pathB);

        if ($hashA === null || $hashB === null) {
            return null;
        }

        $distance = 0;
        $length = strlen($hashA);
        for ($i = 0; $i < $length; $i++) {
            if ($hashA[$i] !== $hashB[$i]) {
                $distance++;
            }
        }

        return round((1 - $distance / $length) * 100, 2);
    }

    /**
     * 根据相似度与配置阈值判定核验状态。
     */
    public function decideStatus(float $similarity): string
    {
        $pass = (float) config('identity.pass_threshold', 85);
        $suspect = (float) config('identity.suspect_threshold', 45);

        if ($similarity >= $pass) {
            return \App\Models\IdentityVerification::STATUS_PASSED;
        }
        if ($similarity >= $suspect) {
            return \App\Models\IdentityVerification::STATUS_SUSPECTED;
        }
        return \App\Models\IdentityVerification::STATUS_FAILED;
    }

    /**
     * 计算图片的灰度均值哈希（aHash），返回 0/1 字符串。
     */
    private function averageHash(string $path): ?string
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (!$src) {
            return null;
        }

        $size = self::HASH_SIZE;
        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));

        $grays = [];
        $sum = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($dst, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int) round($r * 0.299 + $g * 0.587 + $b * 0.114);
                $grays[] = $gray;
                $sum += $gray;
            }
        }

        imagedestroy($src);
        imagedestroy($dst);

        $avg = $sum / count($grays);
        $bits = '';
        foreach ($grays as $gray) {
            $bits .= $gray >= $avg ? '1' : '0';
        }

        return $bits;
    }
}
