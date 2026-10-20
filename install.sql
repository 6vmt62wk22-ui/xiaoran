-- ============================================================
-- 本文件仅作 DDL 参考。实际建表由 migrate.php 的 createSchema() 执行，
-- install.php / update.php 均调用该函数（在 config 指定的 DB_NAME 库内，
-- 不依赖本文件里的库名/USE），请勿直接用它建库以免库名不一致。
-- ============================================================

-- 用户表
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) UNIQUE NOT NULL,
    password      VARCHAR(255) NOT NULL,          -- 存 password_hash()
    storage_used  BIGINT DEFAULT 0,
    storage_limit BIGINT DEFAULT 2147483648,      -- 2GB
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 文件表
CREATE TABLE files (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT DEFAULT NULL,                 -- NULL 表示游客上传
    file_name  VARCHAR(255) NOT NULL,            -- 原始文件名
    file_key   VARCHAR(255) NOT NULL,            -- 存储路径（如 uploads/2026-08/xxx.zip）
    file_size  BIGINT NOT NULL,
    mime_type  VARCHAR(100),
    md5_hash   VARCHAR(32),
    short_code VARCHAR(20) UNIQUE NOT NULL,      -- 短链码（如 aB3kQ）
    downloads  INT DEFAULT 0,
    status     TINYINT DEFAULT 1,                -- 1正常 0禁用/已删
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_md5 (md5_hash),
    INDEX idx_key (file_key(191)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统设置表
CREATE TABLE settings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    key_name    VARCHAR(50) UNIQUE NOT NULL,
    value       TEXT,
    description VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初始系统设置（key_name 唯一，重复执行安全）
INSERT IGNORE INTO settings (key_name, value, description) VALUES
('site_name',     '小染云盘',    '网站名称'),
('max_file_size', '104857600',  '单文件上限(字节) 默认100MB'),
('allow_guest',   '1',          '是否允许游客上传 1/0'),
('custom_domain', '',           '自定义下载域名（留空则使用默认）');
