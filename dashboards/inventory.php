<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$success_message = "";
$error_message = "";

// Handle adding a supplier
if(isset($_POST['add_supplier'])) {
    $supplier_name = trim($_POST['supplier_name']);
    if(!empty($supplier_name)){
        mysqli_query($conn, "INSERT INTO suppliers (name) VALUES ('".mysqli_real_escape_string($conn,$supplier_name)."')");
        $success_message = "Supplier added successfully.";
    }
}

// Handle adding inventory
if(isset($_POST['add_inventory'])){
    $item_name  = trim($_POST['item_name']);
    $item_code  = trim($_POST['item_code']);
    $quantity   = intval($_POST['quantity']);
    $category   = trim($_POST['category']);
    $supplier_id= intval($_POST['supplier_id']);
    $price      = floatval($_POST['price']);
    $added_by   = $_SESSION['user_id'];
    $receipt    = "";

    if(isset($_FILES['receipt']) && $_FILES['receipt']['error'] == 0){
        $ext     = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $receipt = "receipts/".time()."_".rand(1000,9999).".".$ext;
        move_uploaded_file($_FILES['receipt']['tmp_name'], "../".$receipt);
    }

    mysqli_query($conn, "INSERT INTO inventory (item_name,item_code,quantity,category,supplier_id,price,added_by,receipt,created_at)
        VALUES ('".mysqli_real_escape_string($conn,$item_name)."','".mysqli_real_escape_string($conn,$item_code)."',
        '$quantity','".mysqli_real_escape_string($conn,$category)."','$supplier_id','$price','$added_by','$receipt',NOW())");

    $success_message = "Inventory item added successfully.";
}

// Handle fund submission
if(isset($_POST['submit_funds'])){
    $fund_amount = floatval($_POST['fund_amount']);
    $payment_date = date("Y-m-d H:i:s");
    if($fund_amount > 0){
        mysqli_query($conn, "INSERT INTO fees_payments (student_id,class_id,term_id,amount_due,amount_paid,payment_date,status)
        VALUES (0,0,0,0,'$fund_amount','$payment_date','fund_submission')");
        $success_message = "KES ".number_format($fund_amount,2)." submitted successfully.";
    } else {
        $error_message = "Please enter a valid fund amount.";
    }
}

// Fetch suppliers
$suppliers = mysqli_query($conn, "SELECT * FROM suppliers ORDER BY name");

// Fetch financial summary
$fees_result  = mysqli_query($conn, "SELECT SUM(amount_paid) AS total_collected FROM fees_payments");
$fees_row     = mysqli_fetch_assoc($fees_result);
$total_collected = floatval($fees_row['total_collected'] ?? 0);

$spent_result = mysqli_query($conn, "SELECT SUM(quantity*price) AS total_spent FROM inventory");
$spent_row    = mysqli_fetch_assoc($spent_result);
$total_spent  = floatval($spent_row['total_spent'] ?? 0);

$remaining_funds = $total_collected - $total_spent;
$spend_pct = $total_collected > 0 ? min(100, round(($total_spent / $total_collected) * 100)) : 0;

// Fetch inventory count
$inv_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt, SUM(quantity) as total_qty FROM inventory"));
$item_count    = $inv_count_row['cnt'] ?? 0;
$total_qty     = $inv_count_row['total_qty'] ?? 0;

$low_stock_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM inventory WHERE quantity < 5"));
$low_stock_count = $low_stock_row['cnt'] ?? 0;

// Fetch inventory with supplier names
$inventory = mysqli_query($conn, "
    SELECT i.*, s.name AS supplier_name
    FROM inventory i
    LEFT JOIN suppliers s ON i.supplier_id = s.id
    ORDER BY i.created_at DESC
");

// Re-fetch suppliers for form
$suppliers_form = mysqli_query($conn, "SELECT * FROM suppliers ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory — Little Friends School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Mulish:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #07090d;
  --surface:  #0f1218;
  --surface2: #161b24;
  --surface3: #1d2433;
  --border:   rgba(255,255,255,0.07);
  --accent:   #00d4aa;
  --accent2:  #00f0c0;
  --gold:     #f5a623;
  --red:      #ff5c5c;
  --blue:     #4d9fff;
  --text:     #eef0f4;
  --muted:    #6b7585;
  --radius:   14px;
  --font-h:   'Syne', sans-serif;
  --font-b:   'Mulish', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-b);
  font-size: 15px;
  min-height: 100vh;
  line-height: 1.6;
}

/* ── TOPBAR ── */
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 28px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  position: sticky;
  top: 0;
  z-index: 200;
  gap: 12px;
}

.brand { display: flex; align-items: center; gap: 13px; }
.brand-icon {
  width: 40px; height: 40px;
  background: var(--accent);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  color: #07090d;
}
.brand-text h2 {
  font-family: var(--font-h);
  font-size: 1.05rem;
  color: var(--text);
  line-height: 1.15;
}
.brand-text small { color: var(--muted); font-size: 0.75rem; }

.btn-home {
  padding: 8px 18px;
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text);
  border-radius: 8px;
  text-decoration: none;
  font-family: var(--font-b);
  font-size: 0.84rem;
  font-weight: 600;
  transition: all .2s;
  white-space: nowrap;
}
.btn-home:hover { background: var(--surface2); border-color: var(--accent); color: var(--accent); }

/* ── PAGE ── */
.page {
  max-width: 1280px;
  margin: 0 auto;
  padding: 30px 22px;
  display: flex;
  flex-direction: column;
  gap: 26px;
}

/* ── ALERTS ── */
.alert {
  padding: 12px 18px;
  border-radius: 10px;
  font-size: 0.88rem;
  font-weight: 600;
  border-left: 4px solid;
}
.alert-success { background: rgba(0,212,170,0.1); border-color: var(--accent); color: var(--accent); }
.alert-danger   { background: rgba(255,92,92,0.1);  border-color: var(--red);   color: var(--red); }

/* ── STAT CARDS ROW ── */
.stats-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}

.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px 22px;
  position: relative;
  overflow: hidden;
  transition: border-color .2s;
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  border-radius: var(--radius) var(--radius) 0 0;
}
.stat-card.teal::before   { background: var(--accent); }
.stat-card.gold::before   { background: var(--gold); }
.stat-card.red::before    { background: var(--red); }
.stat-card.blue::before   { background: var(--blue); }
.stat-card:hover { border-color: rgba(255,255,255,0.14); }

.stat-label {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 8px;
}
.stat-value {
  font-family: var(--font-h);
  font-size: 1.6rem;
  font-weight: 700;
  line-height: 1;
  margin-bottom: 6px;
}
.stat-value.teal { color: var(--accent); }
.stat-value.gold { color: var(--gold); }
.stat-value.red  { color: var(--red); }
.stat-value.blue { color: var(--blue); }
.stat-sub { font-size: 0.78rem; color: var(--muted); }

/* ── BUDGET PROGRESS ── */
.budget-bar-wrap {
  margin-top: 10px;
}
.budget-bar-outer {
  height: 6px;
  background: rgba(255,255,255,0.08);
  border-radius: 99px;
  overflow: hidden;
  margin-top: 5px;
}
.budget-bar-inner {
  height: 100%;
  border-radius: 99px;
  transition: width .6s ease;
}

/* ── SECTION CARD ── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}

.card-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 17px 22px;
  border-bottom: 1px solid var(--border);
}
.card-header .hicon {
  width: 32px; height: 32px;
  border-radius: 8px;
  background: var(--surface2);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
}
.card-header h5 {
  font-family: var(--font-h);
  font-size: 0.97rem;
  font-weight: 700;
  color: var(--text);
}

.card-body { padding: 22px; }

/* ── FORMS GRID ── */
.form-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: flex-end;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
  min-width: 130px;
}

.form-group label {
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--muted);
}

.form-control {
  padding: 10px 13px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 9px;
  color: var(--text);
  font-family: var(--font-b);
  font-size: 0.88rem;
  transition: border-color .2s, background .2s;
  width: 100%;
}
.form-control:focus { outline: none; border-color: var(--accent); background: var(--surface3); }
.form-control::placeholder { color: var(--muted); }
.form-control option { background: var(--surface2); }

input[type="file"].form-control {
  padding: 8px 12px;
  font-size: 0.82rem;
  color: var(--muted);
  cursor: pointer;
}

/* ── BUTTONS ── */
.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 9px;
  font-family: var(--font-b);
  font-size: 0.86rem;
  font-weight: 700;
  cursor: pointer;
  transition: all .2s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
  flex-shrink: 0;
}
.btn:hover { transform: translateY(-1px); }
.btn:active { transform: translateY(0); }

.btn-teal   { background: var(--accent);  color: #07090d; }
.btn-teal:hover { background: var(--accent2); }
.btn-gold   { background: var(--gold);    color: #1a0e00; }
.btn-gold:hover { filter: brightness(1.1); }
.btn-blue   { background: var(--blue);    color: #020c1e; }
.btn-blue:hover { filter: brightness(1.1); }

/* ── TABLE ── */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

table.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.86rem;
  min-width: 700px;
}
table.data-table thead th {
  padding: 11px 14px;
  text-align: left;
  color: var(--muted);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
table.data-table tbody tr {
  border-bottom: 1px solid rgba(255,255,255,0.04);
  transition: background .15s;
}
table.data-table tbody tr:hover { background: rgba(255,255,255,0.025); }
table.data-table tbody tr:last-child { border-bottom: none; }
table.data-table tbody td {
  padding: 12px 14px;
  vertical-align: middle;
}
table.data-table tbody tr.low-stock { background: rgba(255,92,92,0.06); }
table.data-table tbody tr.low-stock:hover { background: rgba(255,92,92,0.1); }

/* ── BADGES ── */
.badge {
  display: inline-block;
  padding: 3px 9px;
  border-radius: 20px;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.03em;
}
.badge-teal   { background: rgba(0,212,170,0.12); color: var(--accent); }
.badge-red    { background: rgba(255,92,92,0.12);  color: var(--red); }
.badge-gold   { background: rgba(245,166,35,0.12); color: var(--gold); }

/* ── ITEM NAME CELL ── */
.item-name    { font-weight: 600; color: var(--text); }
.item-code    { font-size: 0.75rem; color: var(--muted); font-family: monospace; margin-top: 2px; }

/* ── RECEIPT LINK ── */
.receipt-link {
  color: var(--blue);
  text-decoration: none;
  font-size: 0.82rem;
  font-weight: 600;
}
.receipt-link:hover { text-decoration: underline; }

/* ── FUNDS ALERT BANNER ── */
.funds-banner {
  background: rgba(255,92,92,0.1);
  border: 1px solid rgba(255,92,92,0.25);
  border-radius: var(--radius);
  padding: 14px 20px;
  display: flex;
  align-items: center;
  gap: 12px;
  font-weight: 600;
  color: var(--red);
  font-size: 0.9rem;
}

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
  .stats-row { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .topbar { padding: 13px 16px; }
  .page { padding: 18px 14px; gap: 18px; }
  .stats-row { grid-template-columns: repeat(2, 1fr); gap: 12px; }
  .stat-value { font-size: 1.3rem; }
  .card-body { padding: 16px; }
  .card-header { padding: 14px 16px; }
  .form-group { min-width: 100%; }
  .btn { width: 100%; justify-content: center; }
}
</style>
</head>
<body>

<!-- TOP BAR -->
<header class="topbar">
  <div class="brand">
    <div class="brand-icon">📦</div>
    <div class="brand-text">
      <h2>Little Friends School</h2>
      <small>Inventory Management</small>
    </div>
  </div>
  <a href="admin.php" class="btn-home">← Dashboard</a>
</header>

<div class="page">

  <?php if($success_message): ?>
    <div class="alert alert-success">✓ <?= htmlspecialchars($success_message) ?></div>
  <?php endif; ?>
  <?php if($error_message): ?>
    <div class="alert alert-danger">✕ <?= htmlspecialchars($error_message) ?></div>
  <?php endif; ?>

  <?php if($remaining_funds <= 0): ?>
    <div class="funds-banner">
      ⚠️ &nbsp;Funds depleted! Please submit new funds to continue purchasing inventory.
    </div>
  <?php endif; ?>

  <!-- STAT CARDS -->
  <div class="stats-row">

    <div class="stat-card teal">
      <div class="stat-label">Remaining Funds</div>
      <div class="stat-value teal">KES <?= number_format($remaining_funds, 2) ?></div>
      <div class="stat-sub">of KES <?= number_format($total_collected, 2) ?> collected</div>
      <div class="budget-bar-wrap">
        <div class="budget-bar-outer">
          <div class="budget-bar-inner" style="width:<?= 100 - $spend_pct ?>%;background:var(--accent);"></div>
        </div>
      </div>
    </div>

    <div class="stat-card gold">
      <div class="stat-label">Total Spent</div>
      <div class="stat-value gold">KES <?= number_format($total_spent, 2) ?></div>
      <div class="stat-sub"><?= $spend_pct ?>% of collected funds</div>
      <div class="budget-bar-wrap">
        <div class="budget-bar-outer">
          <div class="budget-bar-inner" style="width:<?= $spend_pct ?>%;background:var(--gold);"></div>
        </div>
      </div>
    </div>

    <div class="stat-card blue">
      <div class="stat-label">Inventory Items</div>
      <div class="stat-value blue"><?= number_format($item_count) ?></div>
      <div class="stat-sub"><?= number_format($total_qty) ?> total units in stock</div>
    </div>

    <div class="stat-card red">
      <div class="stat-label">Low Stock Alerts</div>
      <div class="stat-value red"><?= $low_stock_count ?></div>
      <div class="stat-sub">Items with fewer than 5 units</div>
    </div>

  </div>

  <!-- SUBMIT FUNDS -->
  <div class="card">
    <div class="card-header">
      <div class="hicon">💰</div>
      <h5>Submit Funds</h5>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="form-row">
          <div class="form-group" style="max-width:320px;">
            <label>Amount (KES)</label>
            <input type="number" step="0.01" name="fund_amount" class="form-control" placeholder="e.g. 50000" min="1" required>
          </div>
          <button type="submit" name="submit_funds" class="btn btn-teal">➕ Submit Funds</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ADD SUPPLIER -->
  <div class="card">
    <div class="card-header">
      <div class="hicon">🏭</div>
      <h5>Add Supplier</h5>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="form-row">
          <div class="form-group" style="max-width:340px;">
            <label>Supplier Name</label>
            <input type="text" name="supplier_name" class="form-control" placeholder="e.g. Nairobi Stationery Ltd" required>
          </div>
          <button type="submit" name="add_supplier" class="btn btn-blue">➕ Add Supplier</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ADD INVENTORY ITEM -->
  <div class="card">
    <div class="card-header">
      <div class="hicon">📋</div>
      <h5>Add Inventory Item</h5>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <div class="form-row" style="margin-bottom:14px;">
          <div class="form-group">
            <label>Item Name</label>
            <input type="text" name="item_name" class="form-control" placeholder="e.g. Exercise Books" required>
          </div>
          <div class="form-group" style="max-width:160px;">
            <label>Item Code</label>
            <input type="text" name="item_code" class="form-control" placeholder="e.g. ST-001" required>
          </div>
          <div class="form-group" style="max-width:130px;">
            <label>Quantity</label>
            <input type="number" name="quantity" class="form-control" placeholder="0" min="1" required>
          </div>
          <div class="form-group" style="max-width:160px;">
            <label>Price per Unit</label>
            <input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" min="0" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="max-width:200px;">
            <label>Category</label>
            <input type="text" name="category" class="form-control" placeholder="e.g. Stationery">
          </div>
          <div class="form-group" style="max-width:240px;">
            <label>Supplier</label>
            <select name="supplier_id" class="form-control" required>
              <option value="">Select Supplier</option>
              <?php while($sup = mysqli_fetch_assoc($suppliers_form)): ?>
                <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group" style="max-width:220px;">
            <label>Receipt / Invoice</label>
            <input type="file" name="receipt" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
          </div>
          <button type="submit" name="add_inventory" class="btn btn-gold">➕ Add Item</button>
        </div>
      </form>
    </div>
  </div>

  <!-- INVENTORY TABLE -->
  <div class="card">
    <div class="card-header">
      <div class="hicon">📦</div>
      <h5>Current Inventory</h5>
    </div>
    <div class="card-body" style="padding:0 0 4px;">
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Qty</th>
              <th>Unit Price</th>
              <th>Total Cost</th>
              <th>Supplier</th>
              <th>Category</th>
              <th>Receipt</th>
              <th>Added</th>
            </tr>
          </thead>
          <tbody>
            <?php while($row = mysqli_fetch_assoc($inventory)): 
              $is_low   = $row['quantity'] < 5;
              $total    = $row['quantity'] * $row['price'];
            ?>
            <tr <?= $is_low ? 'class="low-stock"' : '' ?>>
              <td>
                <div class="item-name"><?= htmlspecialchars($row['item_name']) ?></div>
                <div class="item-code"><?= htmlspecialchars($row['item_code']) ?></div>
              </td>
              <td>
                <?php if($is_low): ?>
                  <span class="badge badge-red">⚠ <?= $row['quantity'] ?></span>
                <?php elseif($row['quantity'] < 20): ?>
                  <span class="badge badge-gold"><?= $row['quantity'] ?></span>
                <?php else: ?>
                  <span class="badge badge-teal"><?= $row['quantity'] ?></span>
                <?php endif; ?>
              </td>
              <td>KES <?= number_format($row['price'], 2) ?></td>
              <td style="font-weight:600;">KES <?= number_format($total, 2) ?></td>
              <td style="color:var(--muted);"><?= htmlspecialchars($row['supplier_name'] ?? '—') ?></td>
              <td>
                <?php if(!empty($row['category'])): ?>
                  <span class="badge badge-teal"><?= htmlspecialchars($row['category']) ?></span>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:0.8rem;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if(!empty($row['receipt'])): ?>
                  <a href="../<?= htmlspecialchars($row['receipt']) ?>" target="_blank" class="receipt-link">📄 View</a>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:0.8rem;">None</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted);font-size:0.82rem;white-space:nowrap;">
                <?= date('d M Y', strtotime($row['created_at'])) ?><br>
                <span style="font-size:0.75rem;"><?= date('g:i A', strtotime($row['created_at'])) ?></span>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /page -->

</body>
</html>