<?php
/**
 * 升级套餐面板片段（被 dashboard.php include，不独立访问）
 * 所有 class 加 up- 前缀、id 加 up 前缀，避免与 dashboard 现有 .card/.mask/.wrap 冲突。
 * 支付成功后本地 toast + 切回升级视图，不再整页跳转到独立页。
 */
?>
<section class="upgrade-view view-panel" data-view="upgrade">
  <style>
    .upgrade-view { --up-brand:#3b82f6; --up-brand-d:#2563eb; --up-brand-l:#eff6ff;
      --up-ink:#0f172a; --up-mut:#64748b; --up-line:#e2e8f0; --up-bg:#f7f9fc;
      --up-ok:#22c55e; --up-warn:#f59e0b; --up-red:#ef4444; }
    .upgrade-view * { box-sizing:border-box; }
    .up-wrap { max-width:760px; margin:0 auto; padding:28px 20px 48px; }

    .up-head { text-align:center; margin-bottom:24px; }
    .up-head h1 { font-size:23px; font-weight:800; letter-spacing:-.3px; color:var(--up-ink); }
    .up-head p { color:var(--up-mut); font-size:13.5px; margin-top:6px; }

    .up-banner-ok {
      background:#ecfdf5; border:1px solid #a7f3d0; color:#047857;
      padding:11px 16px; border-radius:11px; margin-bottom:18px;
      text-align:center; font-weight:600; font-size:14px;
    }

    .up-free-banner {
      display:flex; align-items:center; justify-content:space-between; gap:12px;
      background:#fff; border:1px solid var(--up-line); border-radius:12px;
      padding:13px 18px; margin-bottom:14px; flex-wrap:wrap;
    }
    .up-free-banner .l { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .up-tag-free { background:#ecfdf5; color:#16a34a; font-size:12px; font-weight:700; padding:3px 10px; border-radius:99px; }
    .up-free-banner .name { font-weight:700; font-size:14.5px; }
    .up-free-banner .desc { color:var(--up-mut); font-size:12.5px; }
    .up-free-banner .cur { color:var(--up-ok); font-size:12.5px; font-weight:600; }

    .up-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    .up-card {
      background:#fff; border:1.5px solid var(--up-line); border-radius:14px;
      padding:20px 16px 18px; text-align:center; cursor:pointer;
      transition:all .15s; position:relative;
    }
    .up-card:hover { border-color:#bfdbfe; transform:translateY(-2px); box-shadow:0 8px 20px rgba(59,130,246,.08); }
    .up-card.selected { border-color:var(--up-brand); background:var(--up-brand-l); box-shadow:0 6px 18px rgba(59,130,246,.15); }
    .up-card.selected::after {
      content:''; position:absolute; top:8px; right:8px; width:22px; height:22px;
      background:var(--up-brand); border-radius:50%;
      background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 448 512' fill='%23fff'%3E%3Cpath d='M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z'/%3E%3C/svg%3E");
      background-size:12px 12px; background-position:center; background-repeat:no-repeat;
    }
    .up-card.current .up-cname::after {
      content:'当前'; background:var(--up-ok); color:#fff; font-size:11px; font-weight:600;
      padding:2px 9px; border-radius:99px; margin-left:6px; vertical-align:2px;
    }
    .up-cname { font-size:15px; font-weight:700; color:#334155; }
    .up-clabel { font-size:12px; color:var(--up-mut); margin-top:2px; }
    .up-cprice { margin:14px 0 4px; }
    .up-cprice b { font-size:28px; font-weight:800; color:var(--up-ink); letter-spacing:-.5px; }
    .up-cprice span { font-size:12px; color:var(--up-mut); }
    .up-csize { font-size:12.5px; color:#475569; background:#f1f5f9; border-radius:8px; padding:7px 8px; margin:10px 0 12px; line-height:1.55; }
    .up-csize b { font-size:14px; }
    .up-cpill { display:inline-block; background:var(--up-brand); color:#fff; font-size:11.5px; padding:3px 13px; border-radius:99px; }

    .up-custom-banner {
      display:flex; align-items:center; justify-content:center; gap:8px;
      border:1.5px dashed #cbd5e1; border-radius:12px; padding:13px; margin-top:14px;
      color:#94a3b8; font-size:13.5px; font-weight:600; background:transparent; cursor:default;
    }

    .up-sec-title { font-size:14.5px; font-weight:700; margin:26px 0 12px; color:var(--up-ink); }
    .up-seg { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; background:#fff; border:1px solid var(--up-line); border-radius:12px; padding:5px; }
    .up-seg-item { text-align:center; padding:10px 4px; border-radius:9px; cursor:pointer; transition:all .15s; color:#475569; font-size:14px; font-weight:600; }
    .up-seg-item:hover { background:#f1f5f9; }
    .up-seg-item.selected { background:var(--up-brand); color:#fff; }
    .up-seg-item .off { display:inline-block; font-size:10.5px; font-weight:700; color:var(--up-warn); margin-left:4px; }
    .up-seg-item.selected .off { color:#fde047; }

    .up-chan-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; max-width:340px; }
    .up-chan { display:flex; align-items:center; gap:10px; background:#fff; border:1.5px solid var(--up-line); border-radius:12px; padding:12px 16px; cursor:pointer; transition:all .15s; font-size:14px; font-weight:600; color:#475569; }
    .up-chan:hover { border-color:#bfdbfe; }
    .up-chan.selected { border-color:var(--up-brand); background:var(--up-brand-l); color:var(--up-ink); box-shadow:0 4px 12px rgba(59,130,246,.12); }
    .up-chan i { font-size:19px; width:24px; text-align:center; }
    .up-chan .ic-wx { color:#09bb07; }
    .up-chan .ic-ali { color:#1677ff; }

    .up-mask { position:fixed; inset:0; background:rgba(15,23,42,.55); backdrop-filter:blur(3px); display:none; align-items:center; justify-content:center; z-index:99; padding:16px; }
    .up-mask.show { display:flex; }
    .up-pay-modal { background:#fff; border-radius:16px; padding:26px 28px 22px; width:340px; max-width:100%; text-align:center; box-shadow:0 24px 60px rgba(15,23,42,.25); animation:upPop .18s ease; }
    @keyframes upPop { from { transform:scale(.94); opacity:0; } to { transform:scale(1); opacity:1; } }
    .up-pay-modal h3 { font-size:16.5px; font-weight:800; color:var(--up-ink); }
    .up-pm-sub { color:var(--up-mut); font-size:12.5px; margin-top:4px; }
    .up-pm-amount { color:var(--up-red); font-size:30px; font-weight:800; margin:10px 0 4px; }
    .up-qr-box { width:210px; height:210px; margin:12px auto; padding:10px; border:1px solid var(--up-line); border-radius:12px; background:#fff; }
    .up-qr-box img, .up-qr-box canvas { display:block; width:100%; height:100%; }
    .up-pm-status { font-size:13px; color:var(--up-mut); display:flex; align-items:center; justify-content:center; gap:7px; }
    .up-pm-status .spin { width:14px; height:14px; border:2px solid #cbd5e1; border-top-color:var(--up-brand); border-radius:50%; animation:upRot .8s linear infinite; }
    @keyframes upRot { to { transform:rotate(360deg); } }
    .up-pm-status.ok { color:var(--up-ok); font-weight:700; }
    .up-pm-status.err { color:var(--up-red); font-weight:600; }
    .up-pm-close { margin-top:16px; background:#f1f5f9; border:none; color:#475569; border-radius:9px; padding:9px 22px; font-size:13.5px; font-weight:600; cursor:pointer; }
    .up-pm-close:hover { background:#e2e8f0; }

    .up-action-bar { background:#fff; border:1px solid var(--up-line); border-radius:14px; padding:16px 20px; margin-top:14px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .up-ab-info { display:flex; flex-direction:column; gap:2px; min-width:0; }
    .up-ab-summary { font-size:13px; color:var(--up-mut); }
    .up-ab-summary b { color:var(--up-ink); }
    .up-ab-price { display:flex; align-items:baseline; gap:8px; margin-top:2px; }
    .up-ab-price .now { color:var(--up-red); font-size:26px; font-weight:800; }
    .up-ab-price .orig { color:#94a3b8; font-size:12.5px; text-decoration:line-through; }
    .up-btn-buy { background:var(--up-brand); color:#fff; border:none; border-radius:11px; padding:13px 34px; font-size:15px; font-weight:700; cursor:pointer; transition:.15s; white-space:nowrap; }
    .up-btn-buy:hover:not(:disabled) { background:var(--up-brand-d); transform:translateY(-1px); box-shadow:0 6px 16px rgba(59,130,246,.3); }
    .up-btn-buy:disabled { background:#cbd5e1; cursor:not-allowed; }
    .up-btn-login { background:var(--up-brand-l); color:var(--up-brand); border:1.5px solid var(--up-brand); border-radius:11px; padding:12px 30px; font-size:15px; font-weight:700; text-decoration:none; white-space:nowrap; }
    .up-btn-login:hover { background:var(--up-brand); color:#fff; }

    .up-note { text-align:center; color:#94a3b8; font-size:12px; margin-top:22px; line-height:1.7; }

    @media (max-width:620px) {
      .up-cards { grid-template-columns:1fr; }
      .up-seg { grid-template-columns:repeat(2,1fr); }
      .up-action-bar { flex-direction:column; align-items:stretch; text-align:center; }
      .up-btn-buy, .up-btn-login { width:100%; }
    }
  </style>

  <div class="up-wrap">

    <div class="up-head">
      <h1>选择版本</h1>
      <p>升级后配额立即生效，可随时更换</p>
    </div>

    <?php if ($upAppliedName): ?>
      <div class="up-banner-ok"><i class="fas fa-circle-check"></i> 已成功切换到「<?= h($upAppliedName) ?>」✅</div>
    <?php endif; ?>

    <!-- 免费版横幅 -->
    <div class="up-free-banner">
      <div class="l">
        <span class="up-tag-free">免费</span>
        <span class="name">V0</span>
        <span class="desc">单文件 100M 以内 · 注册即用</span>
      </div>
      <?php if ($upCurrentKey === 'v0'): ?><span class="cur">✓ 当前版本</span><?php endif; ?>
    </div>

    <!-- 三张付费卡 -->
    <div class="up-cards" id="upPlanGrid">
      <?php foreach ($UP_PLANS as $k => $p): if (!empty($p['free'])) continue;
        $maxMb = round($p['max'] / $MB);
        $maxStr = $maxMb >= 1024 ? round($maxMb / 1024) . 'G' : $maxMb . 'M';
      ?>
        <div class="up-card <?= $k === $upCurrentKey ? 'current' : '' ?>"
             data-key="<?= h($k) ?>" data-name="<?= h($p['name']) ?>" data-price="<?= (int) $p['price'] ?>">
          <div class="up-cname"><?= h($p['name']) ?></div>
          <div class="up-clabel"><?= h($p['label']) ?></div>
          <div class="up-cprice"><b><?= (int) $p['price'] ?></b><span> 元/月</span></div>
          <div class="up-csize">单文件最大<br><b><?= $maxStr ?></b></div>
          <span class="up-cpill">免广告</span>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- 定制空间横幅 -->
    <div class="up-custom-banner"><i class="fas fa-pen-ruler"></i> 定制空间</div>

    <!-- 购买时长 -->
    <div class="up-sec-title">购买时间</div>
    <div class="up-seg" id="upPeriodGrid">
      <?php foreach ($UP_PERIODS as $i => $pd): ?>
        <div class="up-seg-item <?= $i === 0 ? 'selected' : '' ?>"
             data-key="<?= h($pd['key']) ?>"
             data-name="<?= h($pd['name']) ?>"
             data-months="<?= (int) $pd['months'] ?>"
             data-discount="<?= (float) $pd['discount'] ?>">
          <?= h($pd['name']) ?><?php if ($pd['badge']): ?><span class="off"><?= h($pd['badge']) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($UP_PAY_ENABLED && $isLogin): ?>
    <!-- 支付渠道 -->
    <div class="up-sec-title">支付方式</div>
    <div class="up-chan-grid" id="upChanGrid">
      <?php if ($UP_CHANNELS['wechat']): ?>
        <div class="up-chan selected" data-key="wechat"><i class="fab fa-weixin ic-wx"></i>微信支付</div>
      <?php endif; ?>
      <?php if ($UP_CHANNELS['alipay']): ?>
        <div class="up-chan <?= $UP_CHANNELS['wechat'] ? '' : 'selected' ?>" data-key="alipay"><i class="fab fa-alipay ic-ali"></i>支付宝</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 底部操作条 -->
    <div class="up-action-bar">
      <div class="up-ab-info">
        <div class="up-ab-summary" id="upAbSummary">请选择版本和时长</div>
        <div class="up-ab-price">
          <span class="now" id="upAbNow">¥ --</span>
          <span class="orig" id="upAbOrig"></span>
        </div>
      </div>
      <?php if ($isLogin): ?>
        <form method="POST" id="upApplyForm" style="margin:0;display:flex;">
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="plan" id="upApplyPlan" value="">
          <input type="hidden" name="period" id="upApplyPeriod" value="">
          <button type="submit" class="up-btn-buy" id="upBuyBtn" disabled>确认购买</button>
        </form>
      <?php else: ?>
        <a class="up-btn-login" href="<?= appUrl('dashboard') ?>?tab=login"><i class="fas fa-sign-in-alt"></i> 登录后购买</a>
      <?php endif; ?>
    </div>

    <p class="up-note">
      <?php if ($UP_PAY_ENABLED): ?>
        支付成功后配额自动到账（支持扫码后 3 秒轮询确认，异步通知未达时自动向渠道查单兜底）<br>如遇支付问题请联系管理员核实订单
      <?php else: ?>
        演示环境未接入支付网关，点击「确认购买」直接写入对应配额<br>管理员可在后台「接口配置 - 支付」开启微信 / 支付宝后启用真实支付
      <?php endif; ?>
    </p>

  </div>

  <?php if ($UP_PAY_ENABLED && $isLogin): ?>
  <!-- 支付二维码弹层 -->
  <div class="up-mask" id="upPayMask">
    <div class="up-pay-modal">
      <h3 id="upPmTitle">扫码支付</h3>
      <div class="up-pm-sub" id="upPmSub"></div>
      <div class="up-pm-amount" id="upPmAmount">¥ --</div>
      <div class="up-qr-box" id="upQrBox"></div>
      <div class="up-pm-status" id="upPmStatus"><span class="spin"></span>等待扫码支付…</div>
      <button type="button" class="up-pm-close" id="upPmClose">取消支付</button>
    </div>
  </div>
  <script src="assets/vendor/qrcodejs/qrcode.min.js"></script>
  <?php endif; ?>

  <script>
  (function () {
    const PAY_ON     = <?= ($UP_PAY_ENABLED && $isLogin) ? 'true' : 'false' ?>;
    const planGrid   = document.getElementById('upPlanGrid');
    const periodGrid = document.getElementById('upPeriodGrid');
    const chanGrid   = document.getElementById('upChanGrid');
    const abSummary  = document.getElementById('upAbSummary');
    const abNow      = document.getElementById('upAbNow');
    const abOrig     = document.getElementById('upAbOrig');
    const buyBtn     = document.getElementById('upBuyBtn');
    const applyForm  = document.getElementById('upApplyForm');
    const applyPlan  = document.getElementById('upApplyPlan');
    const applyPeriod= document.getElementById('upApplyPeriod');

    let plan = null, period = null, channel = null;
    let pollTimer = null, curOrderNo = null;

    if (chanGrid) {
      const sel = chanGrid.querySelector('.up-chan.selected');
      if (sel) channel = sel.dataset.key;
      chanGrid.addEventListener('click', e => {
        const c = e.target.closest('.up-chan');
        if (!c) return;
        channel = c.dataset.key;
        chanGrid.querySelectorAll('.up-chan').forEach(x => x.classList.toggle('selected', x === c));
      });
    }

    function recalc() {
      if (plan) {
        planGrid.querySelectorAll('.up-card').forEach(c => c.classList.toggle('selected', c === plan.el));
      }
      if (period) {
        periodGrid.querySelectorAll('.up-seg-item').forEach(s => s.classList.toggle('selected', s === period.el));
      }
      if (!plan || !period) {
        abSummary.textContent = plan ? '已选 ' + plan.name + '，请选择购买时长' : '请选择版本和时长';
        abNow.textContent = '¥ --';
        abOrig.textContent = '';
        if (buyBtn) buyBtn.disabled = true;
        return;
      }
      const orig  = plan.price * period.months;
      const total = Math.round(orig * period.discount);
      abSummary.innerHTML = '已选 <b>' + plan.name + '</b> · ' + period.name + (period.discount < 1 ? '（' + Math.round(period.discount * 10) + ' 折）' : '');
      abNow.textContent  = '¥ ' + total;
      abOrig.textContent = period.discount < 1 ? '原价 ¥ ' + orig : '';
      if (buyBtn) {
        buyBtn.disabled = false;
        buyBtn.textContent = '确认购买 ¥ ' + total;
      }
      applyPlan.value   = plan.key;
      applyPeriod.value = period.key;
    }

    planGrid.addEventListener('click', e => {
      const card = e.target.closest('.up-card');
      if (!card) return;
      plan = { el: card, key: card.dataset.key, name: card.dataset.name, price: parseInt(card.dataset.price, 10) };
      recalc();
    });

    periodGrid.addEventListener('click', e => {
      const seg = e.target.closest('.up-seg-item');
      if (!seg) return;
      period = {
        el: seg, key: seg.dataset.key, name: seg.dataset.name,
        months: parseInt(seg.dataset.months, 10), discount: parseFloat(seg.dataset.discount)
      };
      recalc();
    });

    /* ===== 真实支付流：pay_create 拿二维码 → 轮询 pay_query → 到账刷新（不整页跳转） ===== */
    const payMask   = document.getElementById('upPayMask');
    const pmTitle   = document.getElementById('upPmTitle');
    const pmSub     = document.getElementById('upPmSub');
    const pmAmount  = document.getElementById('upPmAmount');
    const qrBox     = document.getElementById('upQrBox');
    const pmStatus  = document.getElementById('upPmStatus');
    const pmClose   = document.getElementById('upPmClose');

    function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    function setStatus(html, cls) {
      pmStatus.className = 'up-pm-status' + (cls ? ' ' + cls : '');
      pmStatus.innerHTML = html;
    }

    function pollPaid(orderNo) {
      stopPoll();
      pollTimer = setInterval(async () => {
        try {
          const r = await fetch('pay_query', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'order_no=' + encodeURIComponent(orderNo)
          }).then(x => x.json());
          if (r.success && r.paid) {
            stopPoll();
            setStatus('<i class="fas fa-circle-check"></i> 支付成功，配额已到账！', 'ok');
            setTimeout(() => {
              payMask.classList.remove('show');
              qrBox.innerHTML = '';
              // 本地把所购套餐标记为「当前」，无需整页刷新
              if (applyPlan && applyPlan.value) {
                planGrid.querySelectorAll('.up-card').forEach(c => c.classList.toggle('current', c.dataset.key === applyPlan.value));
              }
              try { if (window.switchView) window.switchView('upgrade'); } catch (_) {}
              if (window.toast) window.toast('支付成功，配额已到账！', 'success');
            }, 1400);
          }
        } catch (e) { /* 网络抖动，下一轮再试 */ }
      }, 3000);
    }

    if (pmClose) {
      pmClose.addEventListener('click', () => {
        stopPoll();
        payMask.classList.remove('show');
        qrBox.innerHTML = '';
      });
    }

    if (applyForm) {
      applyForm.addEventListener('submit', async e => {
        if (!PAY_ON) return;                       // 演示模式：走原 POST 直写（PRG 重定向）
        e.preventDefault();
        if (!channel) { alert('请选择支付方式'); return; }
        buyBtn.disabled = true;
        buyBtn.textContent = '正在创建订单…';
        try {
          const body = 'action=create&plan=' + encodeURIComponent(applyPlan.value) +
                       '&period=' + encodeURIComponent(applyPeriod.value) +
                       '&channel=' + encodeURIComponent(channel);
          const r = await fetch('pay_create', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body
          }).then(x => x.json());
          if (!r.success) { alert(r.message || '下单失败，请稍后重试'); return; }

          curOrderNo = r.order_no;
          pmTitle.textContent = (channel === 'wechat' ? '微信扫码支付' : '支付宝扫码支付');
          pmSub.textContent   = r.plan + ' · ' + r.period;
          pmAmount.textContent= '¥ ' + r.amount;
          qrBox.innerHTML     = '';
          new QRCode(qrBox, { text: r.qr, width: 190, height: 190, correctLevel: QRCode.CorrectLevel.M });
          setStatus('<span class="spin"></span>等待扫码支付…');
          payMask.classList.add('show');
          pollPaid(curOrderNo);
        } catch (err) {
          alert('网络异常，请稍后重试');
        } finally {
          buyBtn.disabled = false;
          buyBtn.textContent = '确认购买';
        }
      });
    }
  })();
  </script>
</section>
