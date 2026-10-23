<?php
require_once __DIR__ . '/auth.php';
require_admin_login();

$db = Database::getConnection();
$totalProjects = 0;
$unreadMessages = 0;
$recentMessages = [];

if ($db) {
    try {
        $totalProjects = $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        $unreadMessages = $db->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'unread'")->fetchColumn();
        $recentMessages = $db->query("SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 5")->fetchAll();
    } catch (Exception $e) {
        // Fallback
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | G10 Obra Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            background-color: #09090b;
            color: #fafafa;
        }
        .admin-nav {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: #09090b;
            position: sticky;
            top: 0;
            z-index: 40;
        }
        .admin-nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 3.75rem;
        }
        .admin-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            list-style: none;
        }
        .admin-menu a {
            font-size: 0.85rem;
            color: #a1a1aa;
            font-weight: 500;
        }
        .admin-menu a:hover, .admin-menu a.active {
            color: #ffffff;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.25rem;
            padding: 1.75rem 0 3rem;
        }
        .col-4 { grid-column: span 4; }
        .col-5 { grid-column: span 5; }
        .col-3 { grid-column: span 3; }
        .col-6 { grid-column: span 6; }
        .col-7 { grid-column: span 7; }
        .col-12 { grid-column: span 12; }

        @media (max-width: 1024px) {
            .col-4, .col-5, .col-3, .col-6, .col-7 {
                grid-column: span 12;
            }
        }
    </style>
</head>
<body class="dark-theme">

    <!-- Barra de Navegación del Panel -->
    <header class="admin-nav">
        <div class="container admin-nav-inner">
            <div style="display: flex; align-items: center; gap: 2rem;">
                <a href="dashboard.php" class="brand">
                    <span class="brand-badge">G10</span>
                    <span>OBRA <small style="font-size: 0.65rem; color: #a1a1aa; border: 1px solid rgba(255,255,255,0.12); padding: 2px 6px; border-radius: 4px; font-weight: 500; text-transform: uppercase;">Admin</small></span>
                </a>
                <ul class="admin-menu">
                    <li><a href="dashboard.php" class="active">Panel Principal</a></li>
                    <li><a href="projects.php">Obras (<?= $totalProjects ?>)</a></li>
                    <li><a href="messages.php">Mensajes <?= $unreadMessages > 0 ? "<span class='badge' style='background:#ef4444; color:#fff; border:none; padding:1px 6px;'>$unreadMessages</span>" : "" ?></a></li>
                </ul>
            </div>

            <div style="display: flex; align-items: center; gap: 1rem;">
                <a href="../index.php" target="_blank" class="btn btn-outline btn-sm">
                    Ver Sitio Web &nearr;
                </a>
                <a href="logout.php" class="btn btn-secondary btn-sm" style="color: #ef4444;">
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="dashboard-grid">

            <!-- 1. Tarjeta: Historial de Actividad (Contribution History) -->
            <div class="card col-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">Historial de Obras</div>
                        <div class="card-subtitle">Últimos 6 meses de actividad</div>
                    </div>
                </div>

                <div class="chart-bars">
                    <div class="chart-bar-col">
                        <div class="chart-bar" style="height: 65%;"></div>
                        <span class="chart-label">Dic</span>
                    </div>
                    <div class="chart-bar-col">
                        <div class="chart-bar" style="height: 40%;"></div>
                        <span class="chart-label">Ene</span>
                    </div>
                    <div class="chart-bar-col">
                        <div class="chart-bar" style="height: 85%;"></div>
                        <span class="chart-label">Feb</span>
                    </div>
                    <div class="chart-bar-col">
                        <div class="chart-bar" style="height: 55%;"></div>
                        <span class="chart-label">Mar</span>
                    </div>
                    <div class="chart-bar-col">
                        <div class="chart-bar" style="height: 95%;"></div>
                        <span class="chart-label">Abr</span>
                    </div>
                    <div class="chart-bar-col">
                        <div class="chart-bar active" style="height: 100%;"></div>
                        <span class="chart-label">May</span>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; background: #18181b; padding: 0.85rem; border-radius: 6px; margin-bottom: 1rem;">
                    <div>
                        <div class="stat-label">Próxima Entrega</div>
                        <div style="font-weight: 700; font-size: 0.95rem;">Mayo 25, 2026</div>
                        <div style="font-size: 0.75rem; color: #a1a1aa;">Residencia V&amp;M</div>
                    </div>
                    <div>
                        <div class="stat-label">Cronograma</div>
                        <div style="font-weight: 700; font-size: 0.95rem;">En Plazo</div>
                        <div style="font-size: 0.75rem; color: #a1a1aa;">Avance 92%</div>
                    </div>
                </div>

                <a href="projects.php" class="btn btn-primary btn-block">
                    Ver Obras Registradas
                </a>
            </div>

            <!-- 2. Tarjeta: Umbral de Presupuesto / Payout Threshold -->
            <div class="card col-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">Parámetros de Cotización</div>
                        <div class="card-subtitle">Configura el rango mínimo de proyectos para filtrar leads.</div>
                    </div>
                    <button class="btn-icon" style="width: 1.75rem; height: 1.75rem; font-size: 0.8rem;">&times;</button>
                </div>

                <div class="form-group">
                    <label class="form-label">Moneda Predeterminada</label>
                    <select class="form-select">
                        <option>USD — Dólar Estadounidense</option>
                        <option>ARS — Peso Argentino</option>
                        <option>EUR — Euro</option>
                    </select>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.35rem;">
                        <label class="form-label" style="margin-bottom: 0;">Presupuesto Mínimo</label>
                        <span style="font-size: 1.25rem; font-weight: 700;">$25,000.00</span>
                    </div>
                    <input type="range" min="5000" max="100000" step="5000" value="25000" style="width: 100%; accent-color: #fff; cursor: pointer;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem; color: #71717a; margin-top: 0.25rem;">
                        <span>$5,000 (MIN)</span>
                        <span>$100,000 (MAX)</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notas de Criterio Constructivo</label>
                    <textarea class="form-textarea" rows="2" placeholder="Obras llave en mano, remodelaciones integrales...">Proyectos unifamiliares y comerciales medianos a grandes.</textarea>
                </div>

                <button class="btn btn-primary btn-block" onclick="alert('Configuración guardada exitosamente.');">
                    Guardar Parámetros
                </button>
            </div>

            <!-- 3. Tarjeta: Metas de Obras 2026 (Savings Targets) -->
            <div class="card col-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">Metas de Construcción</div>
                        <div class="card-subtitle">Hitos operativos para el ejercicio 2026</div>
                    </div>
                    <a href="projects.php" class="btn btn-secondary btn-sm">Nueva Obra</a>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <div class="stat-label">OBRAS RESIDENCIALES</div>
                    <div class="stat-value">$420,000</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 65%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.775rem; color: #a1a1aa;">
                        <span>65% ejecutado</span>
                        <span style="font-weight: 600; color: #fff;">$273,000</span>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <div class="stat-label">DESARROLLOS COMERCIALES</div>
                    <div class="stat-value" style="font-size: 1.75rem;">$85,000</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 32%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.775rem; color: #a1a1aa;">
                        <span>32% ejecutado</span>
                        <span style="font-weight: 600; color: #fff;">$27,200</span>
                    </div>
                </div>

                <div style="font-size: 0.775rem; color: #71717a; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 0.75rem;">
                    Obras en ejecución supervisadas por la dirección técnica.
                </div>
            </div>

            <!-- 4. Tarjeta Rápida: Publicar Obra (Distribute Track style) -->
            <div class="card-dashed col-4">
                <div class="icon-box-centered">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                </div>
                <div style="font-weight: 700; font-size: 1rem; margin-bottom: 0.35rem;">Publicar Nueva Obra</div>
                <p style="font-size: 0.8rem; color: #a1a1aa; max-width: 260px; margin-bottom: 1.25rem;">
                    Sube fotografías de alta resolución, metros cuadrados y ficha técnica al portafolio.
                </p>
                <a href="projects.php?action=new" class="btn btn-primary btn-sm">
                    Crear Proyecto
                </a>
            </div>

            <!-- 5. Tarjeta: Balance de Cotizaciones (Claimable Balance style) -->
            <div class="card col-4">
                <div class="card-header">
                    <div>
                        <div class="card-subtitle" style="margin-top:0;">Presupuestos Activos</div>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <div class="stat-value">$184,500</div>
                    <div style="margin-top: 0.4rem;">
                        <span class="badge">
                            <span class="badge-dot success"></span>
                            Cotizaciones Aprobadas
                        </span>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.825rem; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 1rem; margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #a1a1aa;">Materiales de Obra</span>
                        <span style="font-weight: 600;">$112,000</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #a1a1aa;">Mano de Obra Especializada</span>
                        <span style="font-weight: 600;">$72,500</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px dashed rgba(255,255,255,0.08); padding-top: 0.5rem;">
                        <span style="font-weight: 600;">Total Proyectado</span>
                        <span style="font-weight: 700; color: #fff;">$184,500 USD</span>
                    </div>
                </div>
            </div>

            <!-- 6. Tarjeta: Últimas Consultas Recibidas (Recent Transactions style) -->
            <div class="card col-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">Consultas Recientes</div>
                        <div class="card-subtitle">Últimos contactos recibidos desde la web</div>
                    </div>
                    <a href="messages.php" class="btn btn-outline btn-sm">Ver Todas</a>
                </div>

                <div class="activity-list">
                    <?php if (!empty($recentMessages)): ?>
                        <?php foreach ($recentMessages as $msg): ?>
                            <div class="activity-item">
                                <div class="activity-left">
                                    <div class="activity-icon">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                            <polyline points="22,6 12,13 2,6"></polyline>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="activity-title"><?= htmlspecialchars($msg['name']) ?></div>
                                        <div class="activity-desc"><?= htmlspecialchars(substr($msg['message'], 0, 30)) ?>...</div>
                                    </div>
                                </div>
                                <div class="activity-right">
                                    <div class="activity-amount"><?= htmlspecialchars($msg['phone'] ?: 'Web') ?></div>
                                    <div class="activity-date"><?= date('d M', strtotime($msg['created_at'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Mensajes de demostración idénticos a la estética visual de la imagen -->
                        <div class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon">☕</div>
                                <div>
                                    <div class="activity-title">Arq. Martín Rossi</div>
                                    <div class="activity-desc">Consulta por render y obra</div>
                                </div>
                            </div>
                            <div class="activity-right">
                                <div class="activity-amount">Residencial</div>
                                <div class="activity-date">Hoy, 10:24 AM</div>
                            </div>
                        </div>

                        <div class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon">🛒</div>
                                <div>
                                    <div class="activity-title">Estudio Horizon S.A.</div>
                                    <div class="activity-desc">Pliego de licitación oficinas</div>
                                </div>
                            </div>
                            <div class="activity-right">
                                <div class="activity-amount">Comercial</div>
                                <div class="activity-date">Ayer</div>
                            </div>
                        </div>

                        <div class="activity-item">
                            <div class="activity-left">
                                <div class="activity-icon">💼</div>
                                <div>
                                    <div class="activity-title">Mariana Valenzuela</div>
                                    <div class="activity-desc">Remodelación Casa Country</div>
                                </div>
                            </div>
                            <div class="activity-right">
                                <div class="activity-amount positive">+340 m²</div>
                                <div class="activity-date">12 Oct</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

</body>
</html>
