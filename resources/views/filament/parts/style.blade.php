<style>
.crm-hero{position:relative;overflow:hidden;border-radius:22px;padding:30px 34px;color:#fff;background:linear-gradient(120deg,#b71c1c 0%,#d32f2f 45%,#ef5350 100%);box-shadow:0 24px 60px -30px rgba(183,28,28,.75);display:flex;justify-content:space-between;align-items:flex-end;gap:20px}
.crm-hero:after{content:"";position:absolute;right:-60px;top:-80px;width:320px;height:320px;border-radius:50%;background:rgba(255,255,255,.08)}
.crm-hero:before{content:"";position:absolute;right:120px;bottom:-120px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.06)}
.crm-eyebrow{font-size:11px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;opacity:.85}
.crm-hero h2{font-size:30px;line-height:1.15;font-weight:700;margin:8px 0 6px;letter-spacing:-.6px}
.crm-hero p{opacity:.9;font-size:14px}
.crm-hero-actions{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.crm-hero .crm-btn{background:#fff;color:#b71c1c!important}.crm-hero .crm-btn:hover{background:#fff5f5}
.crm-hero .crm-btn.ghost{background:rgba(255,255,255,.14);color:#fff!important;border:1px solid rgba(255,255,255,.35)}
.crm-btn{display:inline-flex;align-items:center;gap:8px;background:var(--crm-red,#d32f2f);color:#fff!important;border-radius:11px;padding:10px 16px;font-size:13px;font-weight:600;white-space:nowrap;cursor:pointer;transition:transform .15s,box-shadow .15s}
.crm-btn:hover{transform:translateY(-1px);box-shadow:0 10px 20px -12px rgba(0,0,0,.5)}
.crm-link{color:var(--crm-red,#d32f2f);font-weight:600}
.crm-muted{color:var(--crm-muted,#6f6767);font-size:13px}
.crm-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-top:20px}
.crm-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
.crm-stat,.crm-card{background:#fff;border:1px solid var(--crm-line,#efe7e6);border-radius:18px;padding:20px 22px;box-shadow:0 1px 2px rgba(31,26,26,.03),0 14px 34px -24px rgba(31,26,26,.18)}
.crm-stat{position:relative;display:flex;flex-direction:column;gap:6px;min-height:140px}
.crm-stat .ico{position:absolute;right:18px;top:18px;width:38px;height:38px;border-radius:12px;display:grid;place-items:center;font-size:18px}
.crm-stat small{color:var(--crm-muted,#6f6767);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.8px}
.crm-stat b{font-size:30px;line-height:1.1;font-weight:700;letter-spacing:-.8px}
.crm-stat .cur{display:flex;flex-direction:column;gap:3px;margin-top:2px}
.crm-stat .cur span{font-size:15px;font-weight:600}
.crm-stat .cur em{font-style:normal;font-size:11px;color:var(--crm-muted,#6f6767);font-weight:500;margin-left:6px}
.crm-stat a{font-size:12px;color:var(--crm-red,#d32f2f);font-weight:600;margin-top:auto}
.t-red .ico{background:#fdeeee;color:#d32f2f}.t-green .ico{background:#e9f7ef;color:#2e7d32}.t-amber .ico{background:#fff4e0;color:#b26a00}.t-blue .ico{background:#e8f4fd;color:#0b65a3}
.crm-split{display:grid;grid-template-columns:1.35fr 1fr;gap:16px;margin-top:16px}
.crm-split.even{grid-template-columns:1fr 1fr}
.crm-card h3{font-size:15px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.crm-card h3 a{font-size:12px;font-weight:600;color:var(--crm-red,#d32f2f)}
.crm-card p{font-size:13px;color:var(--crm-muted,#6f6767);margin-bottom:12px}
.crm-row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:12px 4px;border-top:1px solid #f4eeed;font-size:13px;border-radius:10px;transition:background .15s}
.crm-row:hover{background:#fcf7f7}
.crm-row:first-of-type{border-top:0}
.crm-row small{display:block;color:var(--crm-muted,#6f6767);margin-top:3px;font-size:12px}
.crm-row .amt{font-weight:700;white-space:nowrap;text-align:right}
.crm-badge{padding:4px 10px;background:#fff4e0;color:#a35b00;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.crm-badge.green{background:#e9f7ef;color:#2e7d32}.crm-badge.red{background:#fdeeee;color:#c62828}.crm-badge.blue{background:#e8f4fd;color:#0b65a3}.crm-badge.gray{background:#f3f0ef;color:#6f6767}
.crm-empty{padding:28px 12px;text-align:center;color:var(--crm-muted,#6f6767)}
.crm-empty>span{display:inline-grid;place-items:center;width:44px;height:44px;border-radius:50%;background:#e9f7ef;color:#2e7d32;font-size:20px;margin-bottom:10px}
.crm-empty strong{display:block;margin-bottom:4px;color:var(--crm-ink,#1f1a1a)}
.crm-approvals{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.crm-approvals a{border:1px solid var(--crm-line,#efe7e6);border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:4px;transition:all .15s}
.crm-approvals a:hover{border-color:#f3c7c7;background:#fff9f9}
.crm-approvals b{font-size:24px;font-weight:700}.crm-approvals b.hot{color:#d32f2f}
.crm-approvals small{font-size:12px;color:var(--crm-muted,#6f6767)}
.crm-bars{display:flex;align-items:flex-end;gap:14px;height:150px;padding-top:10px}
.crm-bars .col{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%}
.crm-bars .pair{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:4px}
.crm-bars i{display:block;width:40%;max-width:18px;border-radius:6px 6px 2px 2px;background:#f6c9c9;min-height:3px}
.crm-bars i.tax{background:linear-gradient(180deg,#ef5350,#c62828)}
.crm-bars span{font-size:11px;color:var(--crm-muted,#6f6767)}
.crm-legend{display:flex;gap:14px;font-size:12px;color:var(--crm-muted,#6f6767)}.crm-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px;background:#f6c9c9}.crm-legend i.tax{background:#d32f2f}
.crm-quick{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.crm-quick a{display:flex;gap:12px;align-items:center;border:1px solid var(--crm-line,#efe7e6);border-radius:14px;padding:14px;font-size:13px;font-weight:600;transition:all .15s}
.crm-quick a:hover{border-color:#f3c7c7;background:#fff9f9;transform:translateY(-1px)}
.crm-quick a span{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#fdeeee;color:#d32f2f;font-size:16px;flex:none}
.crm-quick a small{display:block;font-weight:400;color:var(--crm-muted,#6f6767);margin-top:2px}
.crm-onboard{display:flex;justify-content:space-between;align-items:center;gap:20px;border:1px dashed #f0b9b9;background:#fffafa;border-radius:16px;padding:18px 20px;margin-top:16px}
.crm-onboard p{font-size:13px;color:var(--crm-muted,#6f6767);margin-top:4px}
.crm-form{display:grid;gap:16px}.crm-form label{font-size:13px;font-weight:600;display:grid;gap:7px}
.crm-form input,.crm-form select{border:1px solid #ddd4d3;border-radius:10px;padding:10px 12px;max-width:100%;background:#fff}
.crm-table{width:100%;font-size:13px;border-collapse:collapse}.crm-table td,.crm-table th{padding:11px 12px;text-align:left;border-bottom:1px solid #f1eaea}.crm-table th{background:#fcfafa;position:sticky;top:0;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#7b7171}
.crm-error{background:#fdecec;color:#991b1b;padding:12px 14px;border-radius:12px;margin:10px 0}.crm-success{background:#ecfdf3;color:#15803d;padding:12px 14px;border-radius:12px}
.crm-scroll{overflow:auto}.crm-steps{display:flex;gap:30px;font-size:12px;color:var(--crm-muted,#6f6767);margin-bottom:24px}.crm-steps b{color:var(--crm-red,#d32f2f)}
@media(max-width:1100px){.crm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.crm-split,.crm-split.even{grid-template-columns:1fr}}
@media(max-width:640px){.crm-grid,.crm-grid.three{grid-template-columns:1fr}.crm-hero{flex-direction:column;align-items:flex-start;padding:22px}.crm-hero h2{font-size:24px}.crm-approvals,.crm-quick{grid-template-columns:1fr}.crm-mini-stats{grid-template-columns:1fr 1fr}}
</style>
@if(session('success'))<div class="crm-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="crm-error">{{ $errors->first() }}</div>@endif
