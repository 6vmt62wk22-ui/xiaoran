<?php
/**
 * 存储驱动统一接口
 * 所有存储引擎（本机 / 腾讯云 COS / OSS / S3）都实现它，
 * 上传/下载/删除代码只依赖此接口，不关心底层是本地盘还是对象存储。
 */
interface StorageInterface {
    /** 把本地临时文件存入存储，key 为逻辑路径（如 uploads/2026-08/xxx.zip） */
    public function put(string $localTmp, string $key): bool;

    /** 删除存储中的文件 */
    public function delete(string $key): bool;

    /** 文件是否存在 */
    public function exists(string $key): bool;

    /** 是否为远程对象存储（影响下载策略：远程走签名直链，本地走 PHP 流式） */
    public function isRemote(): bool;

    /** 生成可下载 URL（远程返回预签名直链；本地返回空串） */
    public function url(string $key, int $expire = 600): string;

    /** 输出文件给浏览器（本地流式；远程则 302 到签名 URL） */
    public function stream(string $key, string $filename): void;
}
