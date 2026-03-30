<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEO Dashboard - Rechtecheck</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/dashboard.css">
</head>
<body>

    <!-- Toast container for notifications -->
    <div id="toast-container"></div>

    <!-- Dashboard Header -->
    <header class="dashboard-header">
        <div class="header-content">
            <div>
                <h1><i class="fas fa-chart-line"></i> SEO Dashboard - Rechtecheck Project Management</h1>
                <p class="header-subtitle">
                    Gesamt: <span id="total-pages-count">0</span> Seiten verwaltet
                </p>
            </div>
            <div class="model-switcher">
                <div class="dropdown">
                    <button class="btn-model dropdown-toggle" type="button" id="model-dropdown-btn" onclick="toggleModelDropdown()">
                        <i class="fas fa-robot"></i>
                        <span id="model-label">Lade...</span>
                        <i class="fas fa-chevron-down ms-1"></i>
                    </button>
                    <ul class="model-dropdown-menu" id="model-dropdown-menu">
                        <!-- populated by JS -->
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <!-- Tab Navigation -->
    <nav class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('management')" data-tab="management">
            <i class="fas fa-cogs"></i> Management
        </button>
        <button class="tab-btn" onclick="switchTab('analytics')" data-tab="analytics">
            <i class="fas fa-chart-bar"></i> Analytics
        </button>
    </nav>

    <!-- Tab Content: Management -->
    <div class="tab-content active" id="tab-management">

        <!-- Action Buttons Row -->
        <div class="action-bar">
            <button class="btn-action btn-blue" onclick="handleAction('generate_all')" id="btn-generate-all">
                <i class="fas fa-wand-magic-sparkles"></i> Generate All Missing
            </button>
            <button class="btn-action btn-green" onclick="handleAction('publish_all')" id="btn-publish-all">
                <i class="fas fa-upload"></i> Publish All
            </button>
            <button class="btn-action btn-purple" onclick="handleAction('sync_gsc')" id="btn-sync-gsc">
                <i class="fas fa-sync-alt"></i> Sync GSC
            </button>
            <button class="btn-action btn-orange" onclick="handleAction('run_analyzer')" id="btn-run-analyzer">
                <i class="fas fa-microscope"></i> Run Analyzer
            </button>
            <button class="btn-action btn-teal" onclick="handleAction('generate_sitemap')" id="btn-generate-sitemap">
                <i class="fas fa-sitemap"></i> Generate Sitemap
            </button>
        </div>

        <!-- Sort Controls -->
        <div class="sort-bar">
            <label for="sort-select"><i class="fas fa-sort"></i> Sortieren nach:</label>
            <select id="sort-select" onchange="handleSort(this.value)">
                <option value="alphabet">Alphabet</option>
                <option value="score">Score</option>
                <option value="clicks">Clicks</option>
                <option value="status">Status</option>
            </select>
            <div class="sort-bar-divider"></div>
            <form class="add-rg-form" onsubmit="addRechtsgebiet(event)">
                <input type="text" id="add-rg-input" class="add-rg-input" placeholder="Neues Rechtsgebiet..." maxlength="255" autocomplete="off">
                <button type="submit" class="add-rg-btn"><i class="fas fa-plus"></i> Hinzufügen</button>
            </form>
        </div>

        <!-- Loading Spinner -->
        <div class="spinner" id="loading-spinner">
            <div class="spinner-ring"></div>
            <p>Daten werden geladen...</p>
        </div>

        <!-- Empty State -->
        <div class="empty-state" id="empty-state" style="display: none;">
            <i class="fas fa-inbox"></i>
            <p>Keine Rechtsgebiete gefunden.</p>
            <p class="text-muted-custom">Erstellen Sie Rechtsgebiete in der Datenbank, um loszulegen.</p>
        </div>

        <!-- Hierarchy Data Table -->
        <div class="table-wrapper" id="table-wrapper" style="display: none;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="col-expand"></th>
                        <th class="col-name">Name</th>
                        <th class="col-status">Status</th>
                        <th class="col-score">Score</th>
                        <th class="col-clicks">Clicks</th>
                        <th class="col-impressions">Impressions</th>
                        <th class="col-position">Avg Position</th>
                        <th class="col-actions">Aktionen</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <!-- Rows populated by JS -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab Content: Analytics -->
    <div class="tab-content" id="tab-analytics">

        <!-- Summary Cards -->
        <div class="analytics-summary" id="analytics-summary">
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-file-alt"></i></div>
                <div class="summary-value" id="stat-total-pages">-</div>
                <div class="summary-label">Gesamt Seiten</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-check-circle"></i></div>
                <div class="summary-value" id="stat-published">-</div>
                <div class="summary-label">Veröffentlicht</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-mouse-pointer"></i></div>
                <div class="summary-value" id="stat-clicks">-</div>
                <div class="summary-label">Klicks (30T)</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-eye"></i></div>
                <div class="summary-value" id="stat-impressions">-</div>
                <div class="summary-label">Impressionen (30T)</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-percentage"></i></div>
                <div class="summary-value" id="stat-ctr">-</div>
                <div class="summary-label">&Oslash; CTR</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-chart-line"></i></div>
                <div class="summary-value" id="stat-position">-</div>
                <div class="summary-label">&Oslash; Position</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-euro-sign"></i></div>
                <div class="summary-value" id="stat-api-cost">-</div>
                <div class="summary-label">API Kosten (30T)</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-robot"></i></div>
                <div class="summary-value" id="stat-api-calls">-</div>
                <div class="summary-label">API Calls (30T)</div>
            </div>
        </div>

        <!-- Trend Chart -->
        <div class="chart-container">
            <div class="chart-header">
                <h3>Traffic Trend</h3>
                <div class="chart-period-btns">
                    <button class="period-btn active" onclick="loadTrendChart(30)">30 Tage</button>
                    <button class="period-btn" onclick="loadTrendChart(60)">60 Tage</button>
                    <button class="period-btn" onclick="loadTrendChart(90)">90 Tage</button>
                </div>
            </div>
            <canvas id="trendChart" height="300"></canvas>
        </div>

        <!-- Recommendations Table -->
        <div class="analytics-section">
            <h3><i class="fas fa-lightbulb"></i> Empfehlungen</h3>
            <table class="analytics-table" id="recommendations-table">
                <thead>
                    <tr><th>Seite</th><th>Aktion</th><th>Grund</th><th>Priorit&auml;t</th><th>Status</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- Top Performers Table -->
        <div class="analytics-section">
            <h3><i class="fas fa-trophy"></i> Top Performer</h3>
            <table class="analytics-table" id="top-performers-table">
                <thead>
                    <tr><th>Seite</th><th>Klicks</th><th>Impressionen</th><th>CTR</th><th>Position</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- API Cost Table -->
        <div class="analytics-section">
            <h3><i class="fas fa-euro-sign"></i> API Kostenübersicht (30 Tage)</h3>
            <table class="analytics-table" id="api-costs-table">
                <thead>
                    <tr><th>Datum</th><th>API</th><th>Calls</th><th>Tokens</th><th>Kosten</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

    </div>

    <script src="assets/dashboard.js"></script>
</body>
</html>
