<?php
require_once __DIR__ . '/StorageInterface.php';
require_once __DIR__ . '/LocalDriver.php';
require_once __DIR__ . '/CosDriver.php';
require_once __DIR__ . '/OssDriver.php';
require_once __DIR__ . '/S3Driver.php';

/**
 * 存储工厂：按 config/storage.php 的 driver 选择引擎。
 * 全站通过 Storage::instance() 取驱动，切换引擎（local ↔ cos ↔ 未来 oss/s3）零改业务代码。
 */
class Storage {
    private static ?StorageInterface $inst = null;

    public static function instance(): StorageInterface {
        if (self::$inst === null) {
            $cfg = require __DIR__ . '/../../config/storage.php';
            switch ($cfg['driver'] ?? 'local') {
                case 'cos':
                    self::$inst = new CosDriver($cfg['cos']);
                    break;
                case 'oss':
                    self::$inst = new OssDriver($cfg['oss']);
                    break;
                case 's3':
                    self::$inst = new S3Driver($cfg['s3']);
                    break;
                default:
                    self::$inst = new LocalDriver($cfg['local']);
            }
        }
        return self::$inst;
    }
}
