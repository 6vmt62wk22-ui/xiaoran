<?php
/**
 * 验证码驱动统一接口
 * 实现方负责：表单渲染（renderHtml/renderJs）+ 服务端校验（verify）
 * 校验输入统一从 $_POST 读取（本地图形码：captcha；极验：lot_number/captcha_output/pass_token/gen_time；腾讯云：ticket/randstr）
 */
interface CaptchaInterface
{
    /** 驱动名：local / geetest / tencent */
    public function name(): string;

    /**
     * 服务端校验（一次性：校验通过后应销毁凭据，防重放）
     * @param array $input 一般传 $_POST
     */
    public function verify(array $input): bool;

    /** 表单区域 HTML（登录/注册表单内嵌） */
    public function renderHtml(): string;

    /** 页面底部需要的 JS（script 标签 + 初始化逻辑），无则返回空串 */
    public function renderJs(): string;

    /** 驱动是否已配置可用（密钥齐全） */
    public function isConfigured(): bool;
}
