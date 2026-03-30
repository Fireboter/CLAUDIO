<?php
$pageTitle = 'Dashboard';
$dbError = null;
try {
    $stats = [
        'orders'   => db_query("SELECT COUNT(*) as c FROM `Order`")[0]['c'],
        'pending'  => db_query("SELECT COUNT(*) as c FROM `Order` WHERE status = 'pending'")[0]['c'],
        'products' => db_query('SELECT COUNT(*) as c FROM Product')[0]['c'],
        'revenue'  => db_query("SELECT COALESCE(SUM(totalAmount),0) as c FROM `Order` WHERE status IN ('processing','shipped','delivered')")[0]['c'],
    ];
    $recentOrders = db_query("SELECT * FROM `Order` ORDER BY createdAt DESC LIMIT 10");
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
    $stats = ['orders' => '?', 'pending' => '?', 'products' => '?', 'revenue' => '?'];
    $recentOrders = [];
}
require __DIR__ . '/layout-header.php';
?>
<?php if ($dbError): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:1.5rem;margin-bottom:2rem">
  <h2 style="color:#dc2626;margin-bottom:0.5rem">Error de base de datos</h2>
  <p style="font-family:monospace;font-size:0.85rem;color:#7f1d1d;margin-bottom:1rem"><?= htmlspecialchars($dbError) ?></p>
  <p style="margin-bottom:0.5rem">Si la DB es nueva, ejecute las migraciones en orden:</p>
  <ol style="margin:0.5rem 0 0 1.5rem;line-height:2">
    <li><a href="/setup.php" target="_blank" style="color:#dc2626">/setup.php</a> — Crear tablas</li>
    <li><a href="/migrate-preorder.php" target="_blank" style="color:#dc2626">/migrate-preorder.php</a> — Añadir columnas isPreorder</li>
  </ol>
</div>
<?php endif; ?>

<div class="page-header">
  <h1>Dashboard</h1>
  <a href="/" target="_blank" class="btn btn-secondary">Ver tienda &rarr;</a>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-value"><?= $stats['orders'] ?></div>
    <div class="stat-label">Pedidos totales</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['pending'] ?></div>
    <div class="stat-label">Pendientes de pago</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['products'] ?></div>
    <div class="stat-label">Productos</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= number_format((float)$stats['revenue'], 0) ?> €</div>
    <div class="stat-label">Ingresos</div>
  </div>
</div>

<h2 style="font-size:1.125rem;font-weight:600;margin-bottom:1rem">Pedidos recientes</h2>
<table class="admin-table">
  <thead><tr><th>Número</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Fecha</th></tr></thead>
  <tbody>
    <?php foreach ($recentOrders as $o): ?>
    <tr onclick="location.href='/admin/orders/<?= $o['id'] ?>'" style="cursor:pointer">
      <td><strong>#<?= e($o['orderNumber']) ?></strong></td>
      <td><?= e($o['customerName']) ?></td>
      <td><?= number_format((float)$o['totalAmount'], 2) ?> €</td>
      <td><span class="badge badge-<?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
      <td style="color:var(--color-gray-500);font-size:0.8rem"><?= date('d/m/Y H:i', strtotime($o['createdAt'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php require __DIR__ . '/layout-footer.php'; ?>
