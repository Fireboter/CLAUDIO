<?php
$pageTitle = 'Pedidos';
$statusFilter = $_GET['status'] ?? '';
$params = [];
$where = '1=1';
if ($statusFilter) { $where .= ' AND status = ?'; $params[] = $statusFilter; }
$orders = db_query("SELECT * FROM `Order` WHERE $where ORDER BY createdAt DESC", $params);

$statuses = ['pending','processing','shipped','delivered','cancelled','payment_failed'];
$tab = $_GET['tab'] ?? 'orders';
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Pedidos</h1>
</div>

<div style="display:flex;gap:0;margin-bottom:0;border-bottom:2px solid var(--color-gray-200)">
  <a href="/admin/orders" style="padding:0.5rem 1.25rem;font-weight:600;font-size:0.875rem;border-bottom:2px solid <?= $tab==='orders' ? '#000' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='orders' ? '#000' : 'var(--color-gray-500)' ?>;text-decoration:none">Pedidos</a>
  <a href="/admin/orders?tab=preventa" style="padding:0.5rem 1.25rem;font-weight:600;font-size:0.875rem;border-bottom:2px solid <?= $tab==='preventa' ? '#000' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='preventa' ? '#000' : 'var(--color-gray-500)' ?>;text-decoration:none">Preventa</a>
</div>
<div style="margin-top:1.5rem"></div>

<?php if ($tab === 'orders'): ?>
<div style="display:flex;gap:0.5rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <a href="/admin/orders" class="btn <?= !$statusFilter ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Todos</a>
  <?php foreach ($statuses as $s): ?>
    <a href="/admin/orders?status=<?= $s ?>" class="btn <?= $statusFilter === $s ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= e($s) ?></a>
  <?php endforeach; ?>
</div>

<table class="admin-table">
  <thead><tr><th>Número</th><th>Cliente</th><th>Email</th><th>Total</th><th>Envío</th><th>Estado</th><th>Fecha</th></tr></thead>
  <tbody>
    <?php foreach ($orders as $o): ?>
    <tr onclick="location.href='/admin/orders/<?= $o['id'] ?>'" style="cursor:pointer">
      <td><strong>#<?= e($o['orderNumber']) ?></strong></td>
      <td><?= e($o['customerName']) ?></td>
      <td style="color:var(--color-gray-500);font-size:0.8rem"><?= e($o['customerEmail']) ?></td>
      <td><?= number_format((float)$o['totalAmount'], 2) ?> €</td>
      <td style="font-size:0.8rem"><?= e($o['shippingMethod']) ?></td>
      <td><span class="badge badge-<?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
      <td style="color:var(--color-gray-500);font-size:0.8rem"><?= date('d/m/Y H:i', strtotime($o['createdAt'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?>
    <tr><td colspan="7" style="text-align:center;color:var(--color-gray-500);padding:2rem">No hay pedidos.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($tab === 'preventa'): ?>
<?php
$preorderItems = db_query("
    SELECT
        p.id AS productId,
        p.name AS productName,
        oi.variantId,
        pv.size, pv.color, pv.material, pv.customValues,
        SUM(oi.quantity) AS totalQty,
        COUNT(DISTINCT oi.orderId) AS orderCount
    FROM OrderItem oi
    JOIN Product p ON oi.productId = p.id
    LEFT JOIN ProductVariant pv ON oi.variantId = pv.id
    WHERE oi.isPreorder = 1
    GROUP BY p.id, p.name, oi.variantId, pv.size, pv.color, pv.material, pv.customValues
    ORDER BY p.name, oi.variantId
");
?>
<table class="admin-table">
  <thead>
    <tr>
      <th>Producto</th>
      <th>Variante</th>
      <th>Pedidos</th>
      <th>Cantidad total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($preorderItems as $row):
        $cv = !empty($row['customValues']) ? (json_decode($row['customValues'], true) ?? []) : [];
        $variantParts = [];
        if ($cv) {
            foreach ($cv as $k => $v) $variantParts[] = "$k: $v";
        } else {
            if ($row['size'])     $variantParts[] = 'Talla: ' . $row['size'];
            if ($row['color'])    $variantParts[] = 'Color: ' . $row['color'];
            if ($row['material']) $variantParts[] = 'Material: ' . $row['material'];
        }
        $variantLabel = $variantParts ? implode(', ', $variantParts) : '—';
    ?>
    <tr>
      <td><strong><?= e($row['productName']) ?></strong></td>
      <td style="color:var(--color-gray-500);font-size:0.875rem"><?= e($variantLabel) ?></td>
      <td style="font-size:0.875rem"><?= (int)$row['orderCount'] ?></td>
      <td><strong><?= (int)$row['totalQty'] ?></strong></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($preorderItems)): ?>
    <tr><td colspan="4" style="text-align:center;color:var(--color-gray-500);padding:2rem">No hay preventa activa.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/layout-footer.php'; ?>
