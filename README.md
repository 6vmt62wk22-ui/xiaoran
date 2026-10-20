# TangWo · 统一网盘/图床底座（纯 PHP 轻量内核）

> **作者 / Author：** TangWo · **开源协议：** MIT · 纯 PHP 网盘项目（仅 PHP 版，不含原生三端）
> 这是把以往散落的网盘项目（wangpanphp、TangWo图床、Cloudreve 规划）收敛后的**统一底座**：
> 存储、上传内核、路由、鉴权、UI 全部共用一套，网盘与图床都长在同一内核上，从此不再"每次新写"。

纯 PHP 8.3 + MySQL + 原生前端，贴合 LNMP / 宝塔环境，零框架依赖。

## 功能
- 文件上传 + **短链下载**（`/s/xxxx`，干净无 `.php` 后缀）
- **瞬时上传（pre_upload 秒传）**：前端 SparkMD5 分片算哈希 → 命中即跳过上传，复用已有物理文件
- **引用计数删除**：秒传共享的文件，单用户删除不会误删他人文件
- **受保护 / 限时分享**：分享密码（bcrypt）+ 过期时间，download 端强制拦截
- **配额进度条**：控制台实时显示已用 / 总量（GB/TB 自动格式化），上传 / 删除自动维护用量
- **文件夹系统**：`files.type` / `parent_id`（迁移 1004），支持目录层级
- **SVG 图形验证码**：登录/注册防刷，无需 GD 扩展；`captcha.php` 点击可刷新
- **统一登录/注册入口**：全部收敛到 `dashboard.php` 访客态（旧 `login.php` / `register.php` 仅做重定向，消除无验证码的旧入口）
- **管理后台**（`dashboardadmin.php`）：概览 / 用户管理 / 文件管理（含回收恢复）/ 系统信息四标签页，搜索 + 过滤 + 配额进度条
- **套餐升级（内嵌）**：`dashboard.php?view=upgrade` 内嵌升级页，未登录可浏览套餐，购买需登录（演示环境无支付网关，直接写配额），与「个人中心」「我的文件」零刷新切换
- **首次部署自动引导**：`index.php` 检测 `settings.schema_version`，未安装自动跳 `/install`
- **营销首页**（`index.php`）：Hero 双光晕 + 浮动特性卡 + CTA，Font Awesome Free 全兼容
- **4 引擎存储抽象**：本机 / 腾讯云 COS / 阿里云 OSS / AWS S3，切换只改一行配置
- **干净短链路由**：`/dashboard` `/dashboardadmin` `/dashboardadminpricing` `/s/xxxx` 等，无 `index.php?page=` 参数；个人中心/套餐升级内嵌在 `/dashboard` 的 `?view=profile` / `?view=upgrade`，不另起路由
- **多子域集群就绪**：auth/app/io/dl 四节点按角色分工，无状态 HMAC 票据跨域认证，节点无需共享 session
- 游客上传开关、自定义下载域名、单文件上限（后台设置）

## 目录结构
```
tangwo-disk/
├── index.php            营销首页 + 未安装检测（自动跳 /install）
├── dashboard.php        控制台 + 访客态登录/注册（含验证码）+ 文件夹 + 个人中心内嵌（?view=profile）
├── login.php / register.php   兼容重定向（收敛到 dashboard，防绕过验证码）
├── logout.php
├── settings.php         设置（跳转到 dashboard?view=profile）
├── install.php          安装器（建库建表 + 跑迁移）
├── migrate.php          迁移内核（幂等，dashboard/install 共用）
├── config.php           站点 / 数据库 / 票据密钥配置（需改）
├── functions.php        公共函数（DB / 短码 / 引用计数 / url / shareUrl / 验证码 / 格式化）
├── install.sql          初始建表 SQL
│
├── admin/               管理后台（通用布局 + 各标签页入口）
│   ├── admin_layout_header.php / admin_layout_footer.php   后台通用头 / 脚
│   ├── dashboardadmin.php                                  后台入口（概览）
│   ├── dashboardadmin*.php                                 各标签页入口（干净短链）
│   └── admin_overview.php / admin_users.php / admin_files.php /
│       admin_pricing.php / admin_quotas.php / admin_settings.php / admin_system.php
│
├── api/                 接口端点
│   ├── upload.php       上传 + 秒传（接受前端 md5 免服务端重算）
│   ├── pre_upload.php   秒传预检（命中则瞬时出链）
│   ├── download.php     短链下载（流式 / 预签名直链 + 密码门 + 过期拦截）
│   ├── delete.php       删除（引用计数保护）
│   ├── captcha.php      图形验证码端点（SVG，点击刷新）
│   ├── send_code.php    邮箱 / 短信验证码发送
│   └── pay_create.php / pay_query.php / pay_notify.php   支付下单 / 查询 / 回调
│
├── pages/               法律 / 静态页
│   └── contact.php / help.php / privacy.php / terms.php
│
├── partials/            页面片段
│   └── dashboard_upgrade_panel.php   套餐升级面板（被 dashboard.php include）
│
├── core/                内核类库
│   ├── Storage/         存储内核（Local / COS / OSS / S3 + Manager）
│   ├── Auth/            Ticket（无状态 HMAC 票据）
│   ├── Captcha/ Mail/ Sms/ Payment/ Verify/
│   └── Http.php / Tc3Client.php
│
├── config/storage.php   存储引擎配置（driver + 各引擎参数）
├── deploy/
│   ├── nginx-rewrite.conf    Nginx 重写片段（单实例）
│   └── nginx-cluster.conf    Nginx 多子域集群示例
├── docs/                部署说明 / 优化审查报告
├── assets/              样式 + 脚本 + Font Awesome Free（本地化）
├── uploads/             文件存储（运行时生成，已加禁止 PHP 执行）
├── .htaccess            Apache 重写（短链 + 干净路由 + 禁止 uploads 执行 PHP）
└── 404.html
```

## 运行步骤
1. 改配置：`config.php` 填 DB 账号密码、`SITE_URL`、`TICKET_SECRET`；`config/storage.php` 选 `driver`
2. 访问首页 → 未安装会**自动跳转 `/install`**（建库建表 + 迁移到最新），部署稳定后**删掉 `install.php`**
3. 放代码到 web 根目录，确保 `uploads/` 可写（755/777 视环境）
4. 配置重写（宝塔看 `docs/部署说明-宝塔.txt`，含 dashboardadmin / dashboardadminpricing 等新路由）
5. 访问首页 → 注册第一个账号（自动成为 id=1 管理员）→ 上传

> 旧库升级：运行 `php migrate.php`，迁移内核幂等（列/索引已存在自动跳过）。

## 干净短链（无 .php 后缀）
所有页面路由与下载短链均不带 `.php`：`/dashboard` `/dashboardadmin` `/dashboardadminpricing` `/settings` `/s/xxxx` `/pre_upload` `/upload` `/delete`。个人中心（`?view=profile`）与套餐升级（`?view=upgrade`）已内嵌在 dashboard.php，无需单独路由。
- **Apache**：已自带 `.htaccess`（`mod_rewrite` 开启即可）
- **Nginx/宝塔**：伪静态规则见 `deploy/nginx-rewrite.conf`；子目录部署请把 `^/` 改成 `^/你的子目录/`

## 存储内核（4 引擎 · 统一抽象）
业务代码只依赖 `StorageInterface`，切换引擎零改动：
```php
Storage::instance()->put($tmp, $key);   // 上传
Storage::instance()->url($key);         // 远程引擎返回预签名直链
Storage::instance()->delete($key);      // 删除
```
`upload.php` / `download.php` / `delete.php` / `pre_upload.php` 全部走此内核。

### 切换引擎（只改 `config/storage.php` 的 `driver`）
| driver | 引擎 | 说明 |
|--------|------|------|
| `local` | 本机盘 | 默认，文件落 `uploads/` |
| `cos`   | 腾讯云 COS | 填 secret_id/key/bucket/region，下载走预签名直链（对应 wangpanphp） |
| `oss`   | 阿里云 OSS | 填 access_key/secret/bucket/endpoint，V1 签名 |
| `s3`    | AWS S3 / 兼容 | 填 access_key/secret/bucket/region，SigV4（支持 R2/MinIO 填 endpoint） |

> COS 驱动必须走 V5 两步 HMAC（先 SignKey 再 Signature），缺一步会 403。远程引擎签名因沙箱无凭证未实跑，上线前用小文件做一次 `put + url` 验证。

## 瞬时上传（pre_upload）
纯后端 `md5_file` 全量读盘是蓝奏类网盘的卡顿来源。本底座改为：
1. 前端用 **SparkMD5** 分片（2MB）算文件 MD5，**不占服务端 IO**
2. 先 `POST /pre_upload {md5,size,name}` 问后端是否已有相同哈希
3. **命中** → 后端复用物理文件、新增一条记录、瞬时返回分享链接（全程不传文件）
4. **未命中** → 真实上传，并带上前端算好的 md5，服务端免重算

## 受保护 / 限时分享（前端入口）
上传卡片已内置「提取密码（可选）+ 有效期」两个字段：
- **提取密码**：留空即公开；填写后 `upload.php` 以 bcrypt 存储，`download.php` 强制先过密码门（会话内记住，刷新不再重复输）
- **有效期**：永久 / 1 小时 / 24 小时 / 7 天，对应 `expire_hours`；过期后 `download.php` 返回 410
- 秒传命中时**不覆盖**原文件属性（不会因秒传把别人的公开文件改成加密）

## 本机下载加速（X-Accel-Redirect）
本机存储默认由 PHP 流式读盘下发；开启后改为下发 `X-Accel-Redirect` 指令头，**由 Nginx 直接发文件**，大文件下载不占 PHP 内存：
1. `config.php` 把 `X_ACCEL_REDIRECT` 改为 `true`
2. `nginx-rewrite.conf` 的 `/_protected_files/` 段已配 `internal;`，把 `alias` 指向你的 `uploads/` 绝对路径
3. 远程引擎（COS/OSS/S3）不受影响，仍走预签名直链

## 图形验证码（SVG）
- `functions.php` 的 `issueCaptcha()` 生成并存 session，`renderCaptchaSvg()` 渲染
- `dashboard.php` 在**GET 请求**时同步初始化验证码（避免异步晚到）；**POST 校验时不覆盖**（否则用户输入永远对不上）
- `captcha.php` 是点击刷新端点
- 无需 GD 扩展，纯 SVG 输出

## 管理后台（dashboardadmin.php）
侧栏布局：
- **概览**：用户/文件/存储总量统计
- **用户管理**：搜索 + 配额进度条 + 联系方式 + 调整配额
- **文件管理**：搜索 + 类型过滤 + 软删除恢复（restoreFile）
- **系统信息**：PHP/MySQL 版本、schema 版本、磁盘空间
- **接口配置**（`dashboardadminsettings`）：验证码 / 邮箱 / 短信 / 支付网关 4 个 tab，含 SMTP 连通性测试
- **套餐定价**（`dashboardadminpricing`）：三档套餐月价（v1/v2/v3，单位分），含「生效预览」表

## 套餐定价（dashboardadminpricing.php）
- 与 `PayManager::plans()` 同源，修改后 `/dashboard?view=upgrade` 立即生效
- 存储键：`plan_v1_price` / `plan_v2_price` / `plan_v3_price`（单位 = 分）
- 内置预览表显示 1m / 3m / 6m / 1y 四档支付总额（6m 9 折、1y 8 折）

## 套餐升级（内嵌在 dashboard.php `?view=upgrade`）
- 原独立 `dashboardupgrade.php` 已平移为 `dashboard_upgrade_panel.php` 片段，前台零刷新切换，不再整页跳转
- 未登录可浏览全部套餐（浏览模式），点击购买跳登录
- 已登录点击「选择套餐」直接写入 `users.storage_limit`（演示模式；生产应改为下单/支付成功后按订单写入）

## 配额进度条（实时用量）
控制台顶部实时显示当前用户的存储配额进度条（`getQuota()` 计算占比，GB/TB 自动格式化）：
- 上传真实落盘后 `UPDATE users SET storage_used = storage_used + size`；秒传复用物理文件**不计入**
- 物理文件真正删除（引用计数归零）后回收配额；游客上传（`user_id` 为 NULL）不计入
- 数据来自 `users.storage_used` / `users.storage_limit`，前端用渐变进度条渲染

## 集群票据（HMAC 无状态）
`core/Auth/Ticket.php` 签发/校验类 JWT 结构的无状态票据，跨节点不依赖中心化会话：
```php
$t = Ticket::issue($userId, ['role' => 'io'], 900);  // 签发 15 分钟
$claims = Ticket::verify($t);                          // 校验，失败返回 null
```
这是 app/auth/io/dl 多子域架构的鉴权地基——服务端只校验签名与过期时间，天然适配横向扩展。

## 多子域集群部署（app / auth / io / dl）
同一份代码，靠节点角色分工，无状态票据打通认证，节点可独立横向扩展。

### 节点角色
| 角色 | 职责 | 暴露路由 |
|------|------|----------|
| auth | 登录 / 注册 / 登出 | /dashboard?tab=login |
| app  | 前端 / 控制台 | / /dashboard /settings /s/ |
| io   | 上传 / 预检 / 删除 | /upload /pre_upload /delete |
| dl   | 下载 | /s/ |

### 配置（config.php）
```php
define('NODE_ROLE', 'single');                // 单实例；集群用环境变量 XC_NODE_ROLE 覆盖
define('TICKET_COOKIE_DOMAIN', '.xcsc1.cn');  // 带前缀点，Cookie 跨子域共享
define('AUTH_URL', 'https://auth.xcsc1.cn');  // 登录 / 注册 / 登出入口
define('APP_URL',  'https://app.xcsc1.cn');   // 控制台 / 前端入口
define('IO_URL',   'https://io.xcsc1.cn');    // 上传 / 预检入口
```

### 认证模型
登录在 dashboard 访客态完成 → `startSessionTicket()` 用共享密钥签发 HMAC 无状态票据（`xc_ticket` Cookie，域 `.xcsc1.cn`）
→ app / io / dl 节点凭 Cookie 调 `Ticket::verify()` 取 uid，无需共享 session。
`currentUid()` 优先 session、回退 ticket，单实例与集群共用同一套代码。

### 部署步骤
1. 四个子域 DNS 解析到同一台机
2. 四份部署（或软链）指向同一代码根，各自 config.php 设 AUTH/APP/IO_URL 与 TICKET_COOKIE_DOMAIN
3. php-fpm 池设 `clear_env = no`，nginx 用 `nginx-cluster.conf` 给每节点传 `XC_NODE_ROLE`（fastcgi_param）
4. 访问 `https://auth.xcsc1.cn/install` 装库，装完删 `install.php` / `update.php`

> 单实例部署：NODE_ROLE 留 `single`、三个 URL 留空，行为与旧版完全一致，无需任何额外配置。

## 迁移机制（install → 1004）
`install.php` / `update.php` 共用 `migrate.php`，按 `settings.schema_version` 增量迁移，**幂等**（列/索引已存在自动跳过 1060/1061/1062）：
- **1001** 初始建表（`createSchema()`）
- **1002** `files` 表增加 `share_password`（分享密码）、`expires_at`（过期时间戳）
- **1003** `users` 表增加 `contact`（手机号/邮箱验证）
- **1004** `files` 表增加 `type`（file/folder）、`parent_id`（文件夹层级）+ `idx_parent` 索引

此外 `dashboard.php` / `dashboardadmin.php` 页面加载时会**运行时自动补列**（`ensureFolderColumns()` / `ensureUserContactColumn()`），老库无需手动跑迁移即可用。

后续加字段/表，只需在 `migrate.php` 的 `$MIGRATIONS` 数组追加一项、用更高版本号命名即可，老库 `php update.php` 即可平滑升级。

## 安全提示
- 正式部署务必上 HTTPS
- `config.php` 的 DB 密码、`TICKET_SECRET`（**必须固定字符串，禁止运行时 random_bytes**），`config/storage.php` 的云密钥，**勿提交公网**
- `TICKET_SECRET` 若每次请求随机生成，会导致跨请求/跨节点票据校验失败
- `uploads/` 已禁止 PHP 执行（Apache `.htaccess` + Nginx 双保险），防 webshell
- 安装后删除 `install.php` / `update.php` / `gen_test_user.php`（如用到）
