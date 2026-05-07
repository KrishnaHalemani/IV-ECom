<?php
declare(strict_types=1);

function br_email_layout(string $title, string $preheader, string $contentHtml): string
{
    $logoUrl = 'https://via.placeholder.com/180x48?text=BattleRock';
    return '<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
<style>
body{margin:0;padding:0;background:#f1f5ef;font-family:Segoe UI,Arial,sans-serif;color:#1f2a22}
.wrap{max-width:680px;margin:0 auto;padding:18px}
.card{background:#fff;border:1px solid #d7e3d2;border-radius:12px;overflow:hidden}
.head{background:linear-gradient(135deg,#556b2f,#3f5123);padding:20px;color:#fff}
.head img{max-width:180px;height:auto;display:block}
.body{padding:20px}
.muted{color:#60706a}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border:1px solid #e2eadf;padding:8px;font-size:13px;text-align:left}
.pill{display:inline-block;padding:6px 10px;border-radius:999px;background:#e8f6ea;color:#2e6f32;font-weight:700;font-size:12px}
.btn{display:inline-block;background:#556b2f;color:#fff !important;text-decoration:none;padding:10px 14px;border-radius:8px;font-weight:700}
.foot{padding:14px 20px;border-top:1px solid #e7efe4;font-size:12px;color:#68756f}
@media (max-width:620px){.wrap{padding:10px}.body{padding:14px}.head{padding:14px}}
</style></head>
<body><div style="display:none!important;opacity:0;color:transparent;height:0;width:0;overflow:hidden;">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</div>
<div class="wrap"><div class="card"><div class="head"><img src="' . $logoUrl . '" alt="BattleRock"></div><div class="body">' . $contentHtml . '</div><div class="foot">BattleRock • Automated order notification</div></div></div></body></html>';
}

