<style>
@font-face{font-family:'Fira Sans';font-style:normal;font-weight:300;font-display:swap;src:url('/fonts/fira-sans/fira-sans-latin-300-normal.woff2') format('woff2')}
@font-face{font-family:'Fira Sans';font-style:normal;font-weight:400;font-display:swap;src:url('/fonts/fira-sans/fira-sans-latin-400-normal.woff2') format('woff2')}
@font-face{font-family:'Fira Sans';font-style:normal;font-weight:500;font-display:swap;src:url('/fonts/fira-sans/fira-sans-latin-500-normal.woff2') format('woff2')}
@font-face{font-family:'Fira Sans';font-style:normal;font-weight:600;font-display:swap;src:url('/fonts/fira-sans/fira-sans-latin-600-normal.woff2') format('woff2')}
@font-face{font-family:'Fira Sans';font-style:normal;font-weight:700;font-display:swap;src:url('/fonts/fira-sans/fira-sans-latin-700-normal.woff2') format('woff2')}
@font-face{font-family:'Fira Sans';font-style:normal;font-weight:800;font-display:swap;src:url('/fonts/fira-sans/fira-sans-latin-800-normal.woff2') format('woff2')}
:root{
  --font-family:'Fira Sans',Arial,sans-serif;--font-sans:'Fira Sans',Arial,sans-serif;
  --crm-red:#d32f2f;--crm-red-dark:#9f1d1d;--crm-red-soft:#fdeeee;--crm-ink:#1f1a1a;--crm-muted:#6f6767;--crm-line:#efe7e6;--crm-bg:#faf8f7;
}
body,.fi-body{font-family:'Fira Sans',Arial,sans-serif!important;background:var(--crm-bg)!important;color:var(--crm-ink)}
input,button,select,textarea{font-family:inherit}
/* brand */
.crm-brand{display:flex;align-items:center;gap:10px}
.crm-brand-mark{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,#e53935,#9f1d1d);color:#fff;font-weight:800;font-size:13px;letter-spacing:-.5px;flex:none;box-shadow:0 6px 14px -6px rgba(211,47,47,.7)}
.crm-brand-mark b{font-weight:500;opacity:.85}
.crm-brand-text{display:flex;flex-direction:column;line-height:1;font-weight:700;font-size:16px;color:var(--crm-ink)}
.crm-brand-text small{font-weight:500;font-size:11px;color:var(--crm-red);letter-spacing:2px;text-transform:uppercase;margin-top:3px}
/* chrome */
.fi-topbar{background:rgba(255,255,255,.86)!important;backdrop-filter:blur(10px);border-bottom:1px solid var(--crm-line)!important;box-shadow:none!important}
.fi-sidebar{background:#fff!important;border-right:1px solid var(--crm-line)}
.fi-sidebar-item-btn{border-radius:10px!important}
.fi-sidebar-item.fi-active .fi-sidebar-item-btn{background:var(--crm-red-soft)!important}
.fi-sidebar-item.fi-active .fi-sidebar-item-label,.fi-sidebar-item.fi-active .fi-icon{color:var(--crm-red)!important;font-weight:600}
.fi-sidebar-group-label{letter-spacing:1.2px;text-transform:uppercase;font-size:11px!important;color:#a39a99!important}
.fi-page-header-heading,.fi-header-heading{font-weight:700!important;letter-spacing:-.4px}
.fi-section,.fi-ta-ctn{border-radius:16px!important;border:1px solid var(--crm-line)!important;box-shadow:0 1px 2px rgba(31,26,26,.03),0 10px 30px -18px rgba(31,26,26,.12)!important}
.fi-ta-header-cell{background:#fcfafa!important;font-weight:600!important;text-transform:uppercase;font-size:11px!important;letter-spacing:.6px;color:#7b7171!important}
.fi-tabs{border-radius:12px!important;background:#fff;border:1px solid var(--crm-line)}
.fi-tabs-item.fi-active{background:var(--crm-red-soft)!important}
.fi-tabs-item.fi-active .fi-tabs-item-label{color:var(--crm-red)!important}
.fi-btn{border-radius:10px!important;font-weight:600!important}
.fi-badge{border-radius:999px!important;font-weight:600!important}
.fi-modal-window{border-radius:18px!important}
.fi-simple-layout{background:radial-gradient(1200px 500px at 10% -10%,#fde3e3 0,transparent 60%),radial-gradient(900px 500px at 110% 110%,#fbe9e7 0,transparent 55%),#fff!important}
.fi-simple-main{border-radius:20px!important;box-shadow:0 30px 80px -40px rgba(159,29,29,.45)!important;border:1px solid var(--crm-line)}
/* role chip */
.crm-role-chip{font-size:11px;font-weight:600;padding:5px 10px;border-radius:999px;background:#f4f0ef;color:#5b5252;margin-right:6px;white-space:nowrap}
.crm-role-accountant{background:var(--crm-red-soft);color:var(--crm-red)}
.crm-role-director{background:#fff4e0;color:#a35b00}
.crm-role-accountant-assistant{background:#e8f4fd;color:#0b65a3}
.crm-role-paralegal{background:#eef7ee;color:#2e7d32}
.crm-mini-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.crm-mini-stats>div{border:1px solid var(--crm-line);border-radius:12px;padding:10px 12px}.crm-mini-stats small{display:block;color:var(--crm-muted);font-size:11px}.crm-mini-stats b{font-size:14px}.crm-red{color:var(--crm-red)}
</style>
