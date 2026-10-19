<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $ticker = strtoupper(trim((string)($_POST['ticker'] ?? '')));
        $company = trim((string)($_POST['company_name'] ?? ''));
        $strategy = in_array($_POST['strategy'] ?? '', ['short','long'], true) ? $_POST['strategy'] : 'long';
        if (!preg_match('/^[A-Z0-9.\-]{1,15}$/', $ticker)) {
            exit('Invalid ticker symbol. Use letters/numbers, dot, or hyphen.');
        }
        $quantity = max(0, (float)($_POST['quantity'] ?? 0));
        $avgCost = max(0, (float)($_POST['avg_cost'] ?? 0));
        $current = max(0, (float)($_POST['current_price'] ?? 0));
        $ema20 = ($_POST['ema20'] ?? '') !== '' ? (float)$_POST['ema20'] : null;
        $ema50 = ($_POST['ema50'] ?? '') !== '' ? (float)$_POST['ema50'] : null;
        $rsi = ($_POST['rsi14'] ?? '') !== '' ? (float)$_POST['rsi14'] : null;
        $support = ($_POST['support_price'] ?? '') !== '' ? (float)$_POST['support_price'] : null;
        $resistance = ($_POST['resistance_price'] ?? '') !== '' ? (float)$_POST['resistance_price'] : null;
        $notes = trim((string)($_POST['notes'] ?? ''));
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $sql = "UPDATE holdings SET ticker=?, company_name=?, strategy=?, quantity=?, avg_cost=?, current_price=?,
                    ema20=?, ema50=?, rsi14=?, support_price=?, resistance_price=?, notes=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ticker,$company,$strategy,$quantity,$avgCost,$current,$ema20,$ema50,$rsi,$support,$resistance,$notes,$id]);
        } else {
            $sql = "INSERT INTO holdings (ticker,company_name,strategy,quantity,avg_cost,current_price,ema20,ema50,rsi14,support_price,resistance_price,notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE company_name=VALUES(company_name), strategy=VALUES(strategy),
                    quantity=VALUES(quantity), avg_cost=VALUES(avg_cost), current_price=VALUES(current_price),
                    ema20=VALUES(ema20), ema50=VALUES(ema50), rsi14=VALUES(rsi14),
                    support_price=VALUES(support_price), resistance_price=VALUES(resistance_price), notes=VALUES(notes)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ticker,$company,$strategy,$quantity,$avgCost,$current,$ema20,$ema50,$rsi,$support,$resistance,$notes]);
        }
        header('Location: index.php?saved=1'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM holdings WHERE id=?')->execute([$id]);
        header('Location: index.php?deleted=1'); exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM holdings WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$rows = $pdo->query('SELECT * FROM holdings ORDER BY ticker')->fetchAll();

$totalValue = 0.0; $totalCost = 0.0; $shortValue = 0.0; $longValue = 0.0;
foreach ($rows as $r) {
    $totalValue += (float)$r['quantity'] * (float)$r['current_price'];
    $totalCost += (float)$r['quantity'] * (float)$r['avg_cost'];
    if ($r['strategy'] === 'short') $shortValue += (float)$r['quantity'] * (float)$r['current_price'];
    else $longValue += (float)$r['quantity'] * (float)$r['current_price'];
}
$unrealized = $totalValue - $totalCost;
$returnPct = $totalCost > 0 ? ($unrealized / $totalCost) * 100 : 0;

function signalFor(array $r): array {
    $p = (float)$r['current_price'];
    if ($p <= 0) return ['Add price data', 'secondary', 'Enter a valid latest price.'];
    $ema20 = $r['ema20'] !== null ? (float)$r['ema20'] : null;
    $ema50 = $r['ema50'] !== null ? (float)$r['ema50'] : null;
    $rsi = $r['rsi14'] !== null ? (float)$r['rsi14'] : null;
    $support = $r['support_price'] !== null ? (float)$r['support_price'] : null;
    $resistance = $r['resistance_price'] !== null ? (float)$r['resistance_price'] : null;

    if ($r['strategy'] === 'short') {
        if ($rsi !== null && $rsi >= 70) return ['Overbought watch', 'warning', 'Consider risk and nearby resistance; not an automatic sell.'];
        if ($ema20 !== null && $ema50 !== null && $p > $ema20 && $ema20 > $ema50) return ['Uptrend watch', 'success', 'Trend is positive; wait for your planned entry and risk limit.'];
        if ($support !== null && $p <= $support * 1.02) return ['Near support', 'info', 'Check whether support holds; it may break.'];
        return ['Wait / review', 'secondary', 'No complete short-term rule matched.'];
    }
    if ($ema50 !== null && $p < $ema50) return ['Review thesis', 'warning', 'Price is below EMA50; review the business and your long-term thesis.'];
    if ($support !== null && $p <= $support * 1.03) return ['Near support', 'info', 'Compare price with valuation and business fundamentals.'];
    if ($ema20 !== null && $ema50 !== null && $p > $ema20 && $ema20 > $ema50) return ['Positive trend', 'success', 'Trend is positive; do not buy without checking valuation and allocation.'];
    return ['Hold / review', 'secondary', 'Review fundamentals, valuation and portfolio weight.'];
}
function riskPlan(array $r): array {
    $entry = (float)$r['current_price'];
    if ($entry <= 0) return ['—','—','—'];
    $stop = $r['support_price'] !== null && (float)$r['support_price'] < $entry ? (float)$r['support_price'] * 0.98 : $entry * 0.95;
    $risk = $entry - $stop;
    $target1 = $entry + 2 * $risk;
    $target2 = $entry + 3 * $risk;
    return [money($stop), money($target1), money($target2)];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>US Stock Portfolio Analyzer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#0b1220;color:#e8edf7}.navbar,.panel{background:#121c2d;border:1px solid #25334a}
.muted{color:#9caec7}.metric{font-size:1.55rem;font-weight:700}.table{--bs-table-bg:transparent;--bs-table-color:#e8edf7;--bs-table-border-color:#25334a}
.form-control,.form-select{background:#0b1220;color:#e8edf7;border-color:#34445f}.form-control:focus,.form-select:focus{background:#0b1220;color:#fff}
.form-control::placeholder{color:#7e91ad}.btn-primary{background:#526bf5;border-color:#526bf5}.pill{font-size:.75rem}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3 py-3 mb-4"><div class="container-fluid"><span class="navbar-brand fw-bold">US Stock Portfolio Analyzer</span><span class="muted small">Local MVP · Manual market data</span></div></nav>
<main class="container-fluid px-3 px-lg-4 pb-5">
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Holding saved.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-info">Holding deleted.</div><?php endif; ?>
<div class="row g-3 mb-4">
<?php foreach ([['Portfolio value',money($totalValue)],['Cost basis',money($totalCost)],['Unrealized P/L',money($unrealized).' ('.number_format($returnPct,2).'%)'],['Short / Long',money($shortValue).' / '.money($longValue)]] as $m): ?>
<div class="col-12 col-sm-6 col-xl-3"><div class="panel rounded-4 p-3 h-100"><div class="muted small"><?=h($m[0])?></div><div class="metric mt-2"><?=h($m[1])?></div></div></div>
<?php endforeach; ?>
</div>
<div class="row g-4">
<div class="col-12 col-xxl-8">
<div class="panel rounded-4 p-3 p-lg-4">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h4 class="mb-1">My holdings</h4><div class="muted small">Add current holdings and technical values from a trusted chart source.</div></div><span class="badge text-bg-secondary"><?=count($rows)?> symbols</span></div>
<div class="table-responsive"><table class="table align-middle">
<thead><tr><th>Ticker</th><th>Strategy</th><th>Qty</th><th>Avg cost</th><th>Price</th><th>Market value</th><th>P/L</th><th>Signal</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="muted py-4">No holdings yet. Add your first stock in the form.</td></tr><?php endif; ?>
<?php foreach ($rows as $r):
$value=(float)$r['quantity']*(float)$r['current_price']; $cost=(float)$r['quantity']*(float)$r['avg_cost']; $pl=$value-$cost; $sig=signalFor($r); ?>
<tr>
<td><strong><?=h($r['ticker'])?></strong><div class="muted small"><?=h($r['company_name'])?></div></td>
<td><span class="badge <?= $r['strategy']==='short'?'text-bg-info':'text-bg-primary' ?>"><?= $r['strategy']==='short'?'Short-term':'Long-term' ?></span></td>
<td><?=number_format((float)$r['quantity'],4)?></td><td><?=money((float)$r['avg_cost'])?></td><td><?=money((float)$r['current_price'])?></td><td><?=money($value)?></td>
<td class="<?=$pl>=0?'text-success':'text-danger'?>"><?=money($pl)?><div class="small"><?= $cost>0?number_format($pl/$cost*100,2).'%' : '—' ?></div></td>
<td><span class="badge text-bg-<?=$sig[1]?>"><?=h($sig[0])?></span><div class="muted small" style="min-width:170px"><?=h($sig[2])?></div></td>
<td><a class="btn btn-sm btn-outline-light mb-1" href="?edit=<?=(int)$r['id']?>">Edit</a>
<form method="post" onsubmit="return confirm('Delete this holding?')" class="d-inline"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-sm btn-outline-danger">×</button></form></td>
</tr>
<?php [$stop,$t1,$t2]=riskPlan($r); ?>
<tr><td colspan="9" class="pt-0"><div class="muted small">Illustrative plan for <?=h($r['ticker'])?>: stop <?=h($stop)?> · target 1 <?=h($t1)?> · target 2 <?=h($t2)?>. These are mechanical estimates from price/support, not a recommendation.</div></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="muted small mt-2">Signals are screening rules, not predictions. Prices and indicators are manually entered in this MVP.</div>
</div>
</div>
<div class="col-12 col-xxl-4">
<div class="panel rounded-4 p-3 p-lg-4">
<h4 class="mb-1"><?= $edit?'Edit holding':'Add / update holding' ?></h4><div class="muted small mb-3">USD values · ticker is unique</div>
<form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h((string)($edit['id']??''))?>">
<div class="row g-3">
<div class="col-5"><label class="form-label">Ticker *</label><input class="form-control" name="ticker" required maxlength="15" placeholder="AAPL" value="<?=h($edit['ticker']??'')?>"></div>
<div class="col-7"><label class="form-label">Company</label><input class="form-control" name="company_name" placeholder="Apple Inc." value="<?=h($edit['company_name']??'')?>"></div>
<div class="col-12"><label class="form-label">Strategy</label><select class="form-select" name="strategy"><option value="long" <?=($edit['strategy']??'long')==='long'?'selected':''?>>Long-term</option><option value="short" <?=($edit['strategy']??'')==='short'?'selected':''?>>Short-term / swing</option></select></div>
<div class="col-4"><label class="form-label">Quantity</label><input type="number" min="0" step="0.000001" class="form-control" name="quantity" value="<?=h($edit['quantity']??'0')?>"></div>
<div class="col-4"><label class="form-label">Avg cost ($)</label><input type="number" min="0" step="0.000001" class="form-control" name="avg_cost" value="<?=h($edit['avg_cost']??'0')?>"></div>
<div class="col-4"><label class="form-label">Price ($)</label><input type="number" min="0" step="0.000001" class="form-control" name="current_price" value="<?=h($edit['current_price']??'0')?>"></div>
<div class="col-6"><label class="form-label">EMA 20</label><input type="number" step="any" min="0" class="form-control" name="ema20" value="<?=h($edit['ema20']??'')?>"></div>
<div class="col-6"><label class="form-label">EMA 50</label><input type="number" step="any" min="0" class="form-control" name="ema50" value="<?=h($edit['ema50']??'')?>"></div>
<div class="col-4"><label class="form-label">RSI 14</label><input type="number" step="any" min="0" max="100" class="form-control" name="rsi14" value="<?=h($edit['rsi14']??'')?>"></div>
<div class="col-4"><label class="form-label">Support ($)</label><input type="number" step="any" min="0" class="form-control" name="support_price" value="<?=h($edit['support_price']??'')?>"></div>
<div class="col-4"><label class="form-label">Resistance ($)</label><input type="number" step="any" min="0" class="form-control" name="resistance_price" value="<?=h($edit['resistance_price']??'')?>"></div>
<div class="col-12"><label class="form-label">Notes / investment thesis</label><textarea class="form-control" name="notes" rows="3" placeholder="Why do I own this stock? What would change my mind?"><?=h($edit['notes']??'')?></textarea></div>
<div class="col-12 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Save holding</button><?php if($edit): ?><a class="btn btn-outline-light" href="index.php">Cancel</a><?php endif; ?></div>
</div></form>
</div>
<div class="panel rounded-4 p-3 mt-3"><h5>Broker connection</h5><p class="muted small mb-2">Not connected in this version. Tell me your broker (e.g. Interactive Brokers, Schwab, moomoo, Webull, or another provider) to check its official API/import options. Start with read-only access; never share passwords or secret keys here.</p></div>
</div></div>
<div class="muted small mt-4">Local prototype. Keep it on your own computer; do not expose it publicly without authentication, CSRF protection review, HTTPS and security hardening.</div>
</main></body></html>
