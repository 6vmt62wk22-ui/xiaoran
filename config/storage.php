<?php
/**
 * 存储引擎配置
 * driver: 'local'（本机盘） | 'cos'（腾讯云 COS） | 'oss'（阿里云 OSS） | 's3'（AWS S3 / 兼容）
 * 切换引擎只需改 'driver' 一行，业务代码（upload/download/delete）零改动。
 */
return [
    'driver' => 'local',   // ← 改为 cos / oss / s3 即切换对应引擎

    'local' => [
        'root' => __DIR__ . '/../uploads',   // 文件落盘根目录
    ],

    'cos' => [
        'secret_id'  => '你的SecretId',
        'secret_key' => '你的SecretKey',
        'bucket'     => 'your-bucket',
        'region'     => 'ap-guangzhou',       // 如 ap-shanghai / ap-beijing
        // 可选：在 COS 控制台绑定自定义域名后填这里，下载直链走自定义域名
        'domain'     => '',
        'expire'     => 600,                  // 预签名下载链接有效期（秒）
    ],

    'oss' => [
        'access_key_id'     => '你的AccessKeyId',
        'access_key_secret' => '你的AccessKeySecret',
        'bucket'            => 'your-bucket',
        'endpoint'          => 'oss-cn-hangzhou.aliyuncs.com',  // 如 oss-cn-shanghai.aliyuncs.com
        'expire'            => 600,
    ],

    's3' => [
        'access_key' => '你的AccessKey',
        'secret_key' => '你的SecretKey',
        'bucket'     => 'your-bucket',
        'region'     => 'ap-southeast-1',     // 如 us-east-1 / ap-northeast-1
        'endpoint'   => '',                    // 可选：S3 兼容服务（如 R2/MinIO）自定义域名，留空用 AWS 官方
        'expire'     => 600,
    ],
];
