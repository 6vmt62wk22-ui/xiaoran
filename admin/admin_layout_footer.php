  </div><!-- /.content -->
</div><!-- /.main -->

<!-- ============ 通用确认 Modal（替代原生 confirm） ============ -->
<!-- 必须在脚本前面注册，让下方 <script> 能拿到 cf-ok 等节点 -->
<div class="modal-mask" id="modal-confirm">
  <div class="modal" style="max-width:420px">
    <div class="modal-head">
      <h2 id="cf-title"><i class="fas fa-question-circle" style="color:#f59e0b;margin-right:6px"></i> 确认操作</h2>
      <button class="modal-close" onclick="closeConfirm(false)">✕</button>
    </div>
    <div class="modal-body">
      <p id="cf-msg" style="margin:0;font-size:14.5px;line-height:1.65;color:#1e293b"></p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" onclick="closeConfirm(false)">取消</button>
      <button type="button" class="btn btn-warn" id="cf-ok"><i class="fas fa-check"></i> 确定</button>
    </div>
  </div>
</div>

<script>
// Modal 工具
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open');
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
}
// 点击遮罩关闭
document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('modal-mask')) {
    e.target.classList.remove('open');
  }
});
// ESC 关闭
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-mask.open').forEach(m => m.classList.remove('open'));
  }
});

// 通用确认弹层（替代浏览器原生 confirm()）
let _cfCb = null;
function askConfirm(msg, cb, opts) {
  opts = opts || {};
  document.getElementById('cf-msg').textContent = msg;
  const titleEl = document.getElementById('cf-title');
  const icon = opts.icon || 'question-circle';
  const color = opts.color || (opts.danger ? '#ef4444' : '#f59e0b');
  titleEl.innerHTML = '<i class="fas fa-' + icon + '" style="color:' + color + ';margin-right:6px"></i> ' + (opts.title || '确认操作');
  const ok = document.getElementById('cf-ok');
  ok.className = 'btn ' + (opts.danger ? 'btn-warn' : 'btn-primary');
  ok.innerHTML = '<i class="fas fa-' + (opts.okIcon || 'check') + '"></i> ' + (opts.okText || '确定');
  _cfCb = cb;
  openModal('modal-confirm');
  setTimeout(() => ok.focus(), 50);
}
function closeConfirm(fire) {
  closeModal('modal-confirm');
  if (fire && _cfCb) { try { _cfCb(); } catch (e) {} }
  _cfCb = null;
}
// 关键：modal HTML 已经在上面渲染完毕，这里再绑定才能拿到 cf-ok
document.getElementById('cf-ok').addEventListener('click', () => closeConfirm(true));

// 通用表格搜索（按 data-search 字段匹配）
function bindTableSearch(inputId, tableId) {
  const input = document.getElementById(inputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    table.querySelectorAll('tbody tr').forEach(tr => {
      if (tr.classList.contains('empty-row')) { tr.style.display = q ? 'none' : ''; return; }
      const text = (tr.dataset.search || tr.textContent).toLowerCase();
      tr.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
  });
}
</script>

<footer style="text-align:center;padding:16px;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0;margin-top:24px">
    © <?= date('Y') ?> TangWo · TangWo 网盘（tangwo-disk 开源版）
</footer>

</body>
</html>
