<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 考前身份核验配置
    |--------------------------------------------------------------------------
    | 核验材料（证件照、人脸照）仅用于当次考试身份核验，
    | 自提交之日起保留 retention_days 天，到期由 identity:purge 自动清理。
    */

    // 核验材料保留天数（自提交之日起）
    'retention_days' => (int) env('IDENTITY_RETENTION_DAYS', 7),

    // 每位考生每场考试最大核验尝试次数
    'max_attempts' => (int) env('IDENTITY_MAX_ATTEMPTS', 3),

    // 人脸比对相似度阈值（百分制）：>= pass 直接通过；>= suspect 进入人工审核；否则失败
    'pass_threshold' => (float) env('FACE_COMPARE_PASS_THRESHOLD', 85),
    'suspect_threshold' => (float) env('FACE_COMPARE_SUSPECT_THRESHOLD', 45),
];
