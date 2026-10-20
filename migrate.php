<?php
/**
 * 增量迁移内核 + 初始建表（createSchema）
 * 读取当前 schema_version，依次执行尚未应用的迁移到最新版本。
 * 幂等：已被 install.php（首次安装）与 update.php（老库升级）共用。
 *
 * 版本约定：
 *   1001  初始建表（createSchema 负责，不依赖 install.sql）
 *   1002  为 files 表增加 share_password（分享密码）与 expires_at（过期时间戳）
 *
 * 后续新增表/字段，只需在此数组追加一项并以更高版本号命名函数即可。
 */

/**
 * 初始建表：在 db() 已连接的 DB_NAME 库内创建 users / files / settings（幂等，可重复执行）。
 * 关键：不依赖 install.sql 里的库名/USE，避免 config 里 DB_NAME 与实际库名不一致时建错库。
 */
function createSchema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(50) UNIQUE NOT NULL,
            password      VARCHAR(255) NOT NULL,
            contact       VARCHAR(191) NULL COMMENT '手机号或邮箱（注册验证）',
            storage_used  BIGINT DEFAULT 0,
            storage_limit BIGINT DEFAULT 2147483648,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS files (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT DEFAULT NULL,
            file_name  VARCHAR(255) NOT NULL,
            file_key   VARCHAR(255) NOT NULL,
            file_size  BIGINT NOT NULL,
            mime_type  VARCHAR(100),
            md5_hash   VARCHAR(32),
            short_code VARCHAR(20) UNIQUE,
            type       ENUM('file','folder') DEFAULT 'file',
            parent_id  INT DEFAULT NULL,
            downloads  INT DEFAULT 0,
            status     TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_md5 (md5_hash),
            INDEX idx_key (file_key(191)),
            INDEX idx_parent (parent_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS settings (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            key_name    VARCHAR(50) UNIQUE NOT NULL,
            value       TEXT,
            description VARCHAR(255)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // 初始化系统设置（key_name 唯一，重复执行安全）
    $pdo->exec(
        "INSERT IGNORE INTO settings (key_name, value, description) VALUES
        ('site_name', 'TangWo 网盘', '网站名称'),
        ('max_file_size', '314572800', '单文件上限(字节) 默认300MB'),
        ('allow_guest', '1', '是否允许游客上传 1/0'),
        ('custom_domain', '', '自定义下载域名（留空则使用默认）')"
    );
}

$MIGRATIONS = [
    1001 => null,   // 建表由 createSchema() 完成
    1002 => "ALTER TABLE files ADD COLUMN share_password VARCHAR(64) NULL COMMENT '分享密码(hash)，空表示无';
            ALTER TABLE files ADD COLUMN expires_at INT UNSIGNED NULL COMMENT '过期时间戳，空表示不过期';",
    1003 => "ALTER TABLE users ADD COLUMN contact VARCHAR(191) NULL COMMENT '手机号或邮箱（注册验证）';",
    1004 => "ALTER TABLE files ADD COLUMN type ENUM('file','folder') DEFAULT 'file' AFTER short_code;
            ALTER TABLE files ADD COLUMN parent_id INT DEFAULT NULL AFTER type;
            ALTER TABLE files ADD INDEX idx_parent (parent_id);
            ALTER TABLE files MODIFY short_code VARCHAR(20) NULL;",
    1005 => null,   // 验证码表 verify_codes + 支付订单表 orders：由 VerifyCode::ensureTable() / PayManager::ensureTable() 幂等自建（含外键，走代码不走 DDL 拆分）
    1006 => "ALTER TABLE users ADD COLUMN avatar MEDIUMTEXT NULL COMMENT '用户头像 base64 (dataURL)'",
];

function runMigrations(PDO $pdo): int {
    global $MIGRATIONS;
    $ver = 1000;
    try {
        $r = $pdo->query("SELECT value FROM settings WHERE key_name='schema_version'")->fetch();
        if ($r) $ver = (int) $r['value'];
    } catch (Exception $e) { /* settings 尚未建立，保持 1000 */ }

    $final = $ver;
    foreach ($MIGRATIONS as $v => $sql) {
        if ($v <= $ver) continue;
        if ($v === 1001) {
            // 建表由 createSchema() 负责
        } elseif ($sql) {
            foreach (explode(';', $sql) as $stmt) {
                $stmt = trim($stmt);
                if (!$stmt) continue;
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // 幂等：忽略"列已存在"(1060)、"索引已存在"(1061)、"重复键"(1062)
                    $code = $e->errorInfo[1] ?? 0;
                    if ($code == 1060 || $code == 1061 || $code == 1062
                        || stripos($e->getMessage(), 'Duplicate') !== false) {
                        continue;  // 列/索引已存在，跳过
                    }
                    throw $e;  // 其他错误继续抛出
                }
            }
        }
        $pdo->prepare("INSERT INTO settings (key_name, value, description) VALUES ('schema_version', ?, 'schema version')
                       ON DUPLICATE KEY UPDATE value = ?")
            ->execute([$v, $v]);
        $final = $v;
    }
    return $final;
}
