<?php
/**
 * 支付工厂 + 订单履约
 * 设置：pay_wechat = 1/0、pay_alipay = 1/0
 * 订单表 orders 由 ensureTable() 幂等创建
 * 套餐定义与 /dashboardupgrade 页一致：v1=10GB v2=30GB v3=100GB
 */
class PayManager
{
    /** 套餐定义：key => [名称, 配额字节, 月价分]（后台可改：plan_v1_price 等，600 = ¥6/月） */
    public static function plans(): array
    {
        $gb = 1024 * 1024 * 1024;
        return [
            'v1' => ['name' => '10GB 套餐',  'quota' => 10 * $gb, 'price' => (int)(getSetting('plan_v1_price') ?: 600)],
            'v2' => ['name' => '30GB 套餐',  'quota' => 30 * $gb, 'price' => (int)(getSetting('plan_v2_price') ?: 1200)],
            'v3' => ['name' => '100GB 套餐', 'quota' => 100 * $gb, 'price' => (int)(getSetting('plan_v3_price') ?: 1800)],
        ];
    }

    /** 购买时长：key => [名称, 月数, 折扣]（与 /dashboardupgrade 页一致） */
    public static function periods(): array
    {
        return [
            '1m' => ['name' => '1个月', 'months' => 1,  'discount' => 1.0],
            '3m' => ['name' => '3个月', 'months' => 3,  'discount' => 1.0],
            '6m' => ['name' => '6个月', 'months' => 6,  'discount' => 0.9],
            '1y' => ['name' => '1年',   'months' => 12, 'discount' => 0.8],
        ];
    }

    /** 套餐 + 时长 → 应付总额（分）。参数不合法返回 null。 */
    public static function priceOf(string $planKey, string $periodKey): ?int
    {
        $plan   = self::plans()[$planKey] ?? null;
        $period = self::periods()[$periodKey] ?? null;
        if (!$plan || !$period) return null;
        return (int) round($plan['price'] * $period['months'] * $period['discount']);
    }

    /** 订单表（幂等） */
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        db()->exec(
            "CREATE TABLE IF NOT EXISTS orders (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                order_no   VARCHAR(32) UNIQUE NOT NULL,
                user_id    INT NOT NULL,
                channel    VARCHAR(20) NOT NULL COMMENT 'wechat/alipay',
                plan       VARCHAR(20) NOT NULL COMMENT 'v1/v2/v3',
                period     VARCHAR(8)  NOT NULL DEFAULT '1m' COMMENT '1m/3m/6m/1y',
                amount_fen INT NOT NULL,
                status     VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/closed',
                trade_no   VARCHAR(64) DEFAULT '',
                paid_at    INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // 兼容旧表（已存在时补列，失败忽略）
        try { db()->exec("ALTER TABLE orders ADD COLUMN period VARCHAR(8) NOT NULL DEFAULT '1m' COMMENT '1m/3m/6m/1y' AFTER plan"); } catch (Throwable $e) {}
        $done = true;
    }

    /** 是否有任何支付渠道启用 */
    public static function enabled(): bool
    {
        return self::channel('wechat') !== null || self::channel('alipay') !== null;
    }

    /** 按渠道名取驱动实例（未启用/未配置返回 null） */
    public static function channel(string $name): ?PaymentInterface
    {
        if ($name === 'wechat' && getSetting('pay_wechat') === '1') {
            $d = new WechatPay();
            return $d->isConfigured() ? $d : null;
        }
        if ($name === 'alipay' && getSetting('pay_alipay') === '1') {
            $d = new Alipay();
            return $d->isConfigured() ? $d : null;
        }
        return null;
    }

    /** 生成商户订单号：日期 + 用户ID + 随机（商户侧唯一、可读） */
    public static function newOrderNo(int $uid): string
    {
        return date('YmdHis') . str_pad((string)$uid, 4, '0', STR_PAD_LEFT) . bin2hex(random_bytes(3));
    }

    /**
     * 标记订单已支付 + 履约（升配额）。
     * 幂等：已 paid 直接返回 true；用条件 UPDATE 抢占防止并发双履约。
     */
    public static function settlePaid(string $orderNo, string $tradeNo): bool
    {
        self::ensureTable();
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_no = ?');
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch();
        if (!$order) return false;

        if ($order['status'] === 'paid') return true;   // 已处理（微信会重发通知）

        // 原子抢占：只有 pending → paid 成功的那一次才履约
        $upd = $pdo->prepare("UPDATE orders SET status='paid', trade_no=?, paid_at=? WHERE order_no=? AND status='pending'");
        $upd->execute([$tradeNo, time(), $orderNo]);
        if ($upd->rowCount() === 0) return true;   // 被并发抢先，对方已履约

        // 履约：套餐配额写到用户（叠加当前限制取 max，保证已付费不降级）
        $plan = self::plans()[$order['plan']] ?? null;
        if ($plan) {
            $pdo->prepare('UPDATE users SET storage_limit = GREATEST(storage_limit, ?) WHERE id = ?')
                ->execute([$plan['quota'], $order['user_id']]);
        }
        return true;
    }
}
