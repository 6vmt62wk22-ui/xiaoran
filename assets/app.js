// 全局 Toast（替代原生 alert）
function toast(msg, type = 'info') {
  const wrap = document.getElementById('toast-wrap');
  if (!wrap) return;
  const colors = { success: '#34d399', error: '#fb7185', info: '#a78bfa' };
  const icons  = { success: '✓', error: '✕', info: 'i' };
  const el = document.createElement('div');
  el.className = 'toast';
  el.innerHTML = `<span style="color:${colors[type]};font-weight:700">${icons[type]}</span><span>${msg}</span>`;
  wrap.appendChild(el);
  setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 300); }, 2600);
}

// 通用 Modal 确认（替代原生 confirm），返回 Promise
function confirmDialog(title, msg) {
  return new Promise(resolve => {
    let box = document.getElementById('xc-modal');
    if (!box) {
      box = document.createElement('div');
      box.id = 'xc-modal';
      box.className = 'modal-mask';
      box.innerHTML = `<div class="modal"><h3 id="xc-modal-title"></h3><p id="xc-modal-msg"></p>
        <div class="modal-actions"><button class="btn btn-ghost" id="xc-modal-cancel">取消</button>
        <button class="btn btn-danger" id="xc-modal-ok">确定</button></div></div>`;
      document.body.appendChild(box);
    }
    document.getElementById('xc-modal-title').textContent = title;
    document.getElementById('xc-modal-msg').textContent = msg;
    box.style.display = 'flex';
    const ok = document.getElementById('xc-modal-ok');
    const cancel = document.getElementById('xc-modal-cancel');
    const close = (v) => { box.style.display = 'none'; ok.onclick = cancel.onclick = null; resolve(v); };
    ok.onclick = () => close(true);
    cancel.onclick = () => close(false);
  });
}

// 通用 Modal 输入（替代原生 prompt），返回 Promise<string|null>
function promptDialog(title, placeholder, defaultValue = '') {
  return new Promise(resolve => {
    let box = document.getElementById('xc-prompt-modal');
    if (!box) {
      box = document.createElement('div');
      box.id = 'xc-prompt-modal';
      box.className = 'modal-mask';
      box.innerHTML = `<div class="modal"><h3 id="xc-prompt-title"></h3>
        <input type="text" class="modal-input" id="xc-prompt-input" autocomplete="off">
        <div class="modal-actions"><button class="btn btn-ghost" id="xc-prompt-cancel">取消</button>
        <button class="btn btn-primary" id="xc-prompt-ok">确定</button></div></div>`;
      document.body.appendChild(box);
    }
    document.getElementById('xc-prompt-title').textContent = title;
    const input = document.getElementById('xc-prompt-input');
    input.placeholder = placeholder || '';
    input.value = defaultValue;
    box.style.display = 'flex';
    setTimeout(() => input.focus(), 10);
    input.select();
    const ok = document.getElementById('xc-prompt-ok');
    const cancel = document.getElementById('xc-prompt-cancel');
    const close = (v) => {
      box.style.display = 'none';
      ok.onclick = cancel.onclick = null;
      input.onkeydown = null;
      resolve(v);
    };
    ok.onclick = () => close(input.value.trim());
    cancel.onclick = () => close(null);
    input.onkeydown = (e) => {
      if (e.key === 'Enter') { e.preventDefault(); ok.onclick(); }
      if (e.key === 'Escape') { e.preventDefault(); cancel.onclick(); }
    };
  });
}

// 分片计算文件 MD5（SparkMD5，免服务端整盘读取）
function computeMd5(file) {
  return new Promise((resolve) => {
    if (!window.SparkMD5) return resolve(null);
    const chunk = 2 * 1024 * 1024;
    const spark = new SparkMD5.ArrayBuffer();
    const fr = new FileReader();
    let current = 0;
    fr.onload = e => {
      spark.append(e.target.result);
      current += e.target.result.byteLength;
      if (current < file.size) readNext();
      else resolve(spark.end());
    };
    fr.onerror = () => resolve(null);
    function readNext() {
      fr.readAsArrayBuffer(file.slice(current, current + chunk));
    }
    readNext();
  });
}

// 首页 / 控制台 上传逻辑（含瞬时上传）
function bindUpload(inputId, btnId, resultId) {
  const input = document.getElementById(inputId);
  const btn   = document.getElementById(btnId);
  const result = document.getElementById(resultId);
  const drop  = btn ? btn.closest('.card')?.querySelector('.drop') : null;
  const BASE  = window.BASE || '';
  const IO_BASE = window.IO_URL ? window.IO_URL.replace(/\/$/, '') : BASE;   // 集群下上传走 io 节点

  function pick() { input.click(); }
  if (btn) btn.addEventListener('click', pick);
  if (drop) drop.addEventListener('click', pick);

  if (drop) {
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) doUpload(e.dataTransfer.files[0]); });
  }
  if (input) input.addEventListener('change', () => { if (input.files[0]) doUpload(input.files[0]); });

  async function doUpload(file) {
    if (!file) return;
    const md5 = await computeMd5(file);

    // 1) 先问后端是否秒传命中
    if (md5) {
      try {
        const pre = await fetch(IO_BASE + '/pre_upload', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `md5=${encodeURIComponent(md5)}&size=${file.size}&name=${encodeURIComponent(file.name)}`
        }).then(r => r.json());
        if (pre.success && pre.hit) {
          showResult(pre, true);
          return;
        }
      } catch (_) { /* 预检失败则继续走普通上传 */ }
    }

    // 2) 未命中：上传真实文件（带上 md5 让服务端免重算）
    const fd = new FormData();
    fd.append('file', file);
    if (md5) fd.append('md5', md5);
    // 分享选项：提取密码 + 有效期（秒传命中时不覆盖原文件属性）
    const pw  = document.getElementById('sharePw')?.value?.trim() || '';
    const exp = document.getElementById('shareExp')?.value || '0';
    if (pw)  fd.append('password', pw);
    if (exp && exp !== '0') fd.append('expire_hours', exp);
    fetch(IO_BASE + '/upload', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.success) showResult(d, false, !!pw);
        else { if (result) result.textContent = '❌ ' + d.message; toast(d.message, 'error'); }
      })
      .catch(() => toast('网络错误', 'error'));
  }

  function showResult(d, instant, protectedFile = false) {
    const url = (window.SITE_URL || '') + '/s/' + d.short_code;
    const tag = protectedFile ? '🔒 已加密 · ' : (instant ? '⚡ 秒传 · ' : '');
    if (result) result.innerHTML = tag + '分享链接：<a href="' + url + '" target="_blank">' + url + '</a>';
    toast(protectedFile ? '已加密分享' : (instant ? '秒传成功' : '上传成功'), 'success');
    if (window.__refresh) window.__refresh();
  }
}

// 删除确认（Modal 替代原生 confirm）
document.addEventListener('click', e => {
  const del = e.target.closest('[data-del]');
  if (!del) return;
  e.preventDefault();
  confirmDialog('删除文件', '确定删除该文件？复用同一物理文件的其他分享不受影响。').then(ok => {
    if (ok) location.href = del.getAttribute('href');
  });
});
