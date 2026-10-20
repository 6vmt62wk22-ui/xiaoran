<?php
/**
 * 本机存储驱动：文件落在服务器本地磁盘（uploads/ 目录）
 * 对应你「TangWo图床」的本地盘方案。
 */
class LocalDriver implements StorageInterface {
    private string $root;

    public function __construct(array $cfg) {
        $this->root = rtrim($cfg['root'] ?? __DIR__ . '/../../uploads', '/');
    }

    private function path(string $key): string {
        return $this->root . '/' . ltrim($key, '/');
    }

    public function put(string $localTmp, string $key): bool {
        $dest = $this->path($key);
        $dir  = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // 上传临时文件用 move；非上传临时文件（如内部转存）用 rename
        return move_uploaded_file($localTmp, $dest) || rename($localTmp, $dest);
    }

    public function delete(string $key): bool {
        $p = $this->path($key);
        return file_exists($p) ? @unlink($p) : true;
    }

    public function exists(string $key): bool {
        return file_exists($this->path($key));
    }

    public function isRemote(): bool { return false; }

    public function url(string $key, int $expire = 600): string { return ''; }

    public function stream(string $key, string $filename): void {
        $p = $this->path($key);
        if (!file_exists($p)) { http_response_code(404); exit('物理文件丢失'); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($p) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($p));
        header('Cache-Control: no-store');
        $fp = fopen($p, 'rb');
        if ($fp) {
            while (!feof($fp)) { echo fread($fp, 8192); flush(); }
            fclose($fp);
        }
        exit;
    }

    /**
     * 本机下载加速：仅下发指令头，由 Nginx 通过 internal location 直接发文件（X-Accel-Redirect）
     * 不走 PHP 流式读盘，大文件下载不占 PHP 内存。需 nginx 配置 internal 路径。
     */
    public function internalRedirect(string $key, string $filename, string $location): void {
        $p = $this->path($key);
        if (!file_exists($p)) { http_response_code(404); exit('物理文件丢失'); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($p) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Cache-Control: no-store');
        header('X-Accel-Redirect: ' . rtrim($location, '/') . '/' . ltrim($key, '/'));
        exit;
    }
}
