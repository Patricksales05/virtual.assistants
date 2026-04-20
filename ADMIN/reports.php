<?php
require_once 'db_config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    header("Location: index.php");
    exit();
}
$user_name = $_SESSION['username'];

// Get filter values
$filter_date = $_GET['filter_date'] ?? '';
$where_clause = "1";
$params = [];

if (!empty($filter_date)) {
    $where_clause = "a.attendance_date = ?";
    $params[] = $filter_date;
}

// Fetch combined attendance data
try {
    $sql = "
        SELECT a.*, u.full_name, u.role, u.username as acc_name 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE $where_clause
        ORDER BY a.attendance_date DESC, a.time_in DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll();
} catch (PDOException $e) {
    $report_data = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #6366f1; --bg-dark: #0f172a; --card-glass: rgba(15, 23, 42, 0.7); --text-muted: #94a3b8; --card-border: rgba(255, 255, 255, 0.1); }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { 
            background: linear-gradient(-45deg, #0f172a, #1e293b, #111827, #1e1b4b);
            background-size: 300% 300%;
            animation: gradientBG 15s ease infinite;
            color: #f8fafc; 
            min-height: 100vh; 
            display: flex; 
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background: #0f172a;
            border-right: 1px solid var(--card-border);
            padding: 2rem 1.25rem;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
        }
        .logo-container { margin-bottom: 2.5rem; display: flex; align-items: center; justify-content: center; }
        .logo-img { width: 180px; filter: brightness(0) invert(1); image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; }
        .nav-menu { list-style: none; flex: 1; margin-top: 1rem; }
        .nav-item { margin-bottom: 0.5rem; }
        .nav-link { 
            text-decoration: none; 
            color: #94a3b8; 
            padding: 0.85rem 1.25rem; 
            border-radius: 12px; 
            display: flex; 
            align-items: center; 
            gap: 1rem; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            font-weight: 600; 
            font-size: 0.85rem;
        }
        .nav-link i { width: 1.2rem; text-align: center; font-size: 1rem; }
        .nav-link:hover { color: #6366f1; background: rgba(99, 102, 241, 0.05); }
        .nav-link.active { 
            background: #6366f1; 
            color: white !important; 
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3); 
            font-weight: 700;
        }
        .nav-link.active i { color: white; }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 1.5rem;
            border-top: 1px solid var(--card-border);
        }
        .logout-link {
            color: #ff4d4d !important;
            font-weight: 800;
        }
        .logout-link i { color: #ff4d4d !important; }
        .logout-link:hover { background: rgba(239, 68, 68, 0.1); }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 1.5rem 2.5rem;
            width: calc(100% - 250px);
            background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.08) 0%, transparent 50%);
        }
        
        .report-card { background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 24px; padding: 2rem; }
        .card-header { margin-bottom: 2rem; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; color: var(--text-muted); font-size: 0.8rem; font-weight: 700; text-transform: uppercase; padding: 1rem; border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; border-bottom: 1px solid var(--card-border); font-size: 0.95rem; }
        .avatar { width: 32px; height: 32px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; }
        .badge { padding: 0.25rem 0.75rem; border-radius: 100px; font-size: 0.75rem; font-weight: 700; }
        .status-completed { background: rgba(99, 102, 241, 0.15); color: #6366f1; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .sidebar { animation: fadeInUp 0.6s ease; }
        .card-header { animation: fadeInUp 0.6s ease 0.1s both; }
        .report-card { animation: fadeInUp 0.6s ease 0.2s both; }
        
        .filter-bar { display: flex; gap: 1rem; align-items: center; margin-bottom: 2rem; background: rgba(30, 41, 59, 0.4); border: 1px solid var(--card-border); padding: 1rem 1.5rem; border-radius: 16px; }
        .filter-input { background: rgba(30, 41, 59, 0.6); border: 1px solid var(--card-border); border-radius: 8px; color: white; padding: 0.5rem 1rem; font-size: 0.9rem; }
        .btn-filter { background: var(--primary-color); color: white; border: none; border-radius: 8px; padding: 0.5rem 1.5rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-filter:hover { opacity: 0.9; transform: translateY(-1px); }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Logo" class="logo-img">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Users</span></a></li>
            <li class="nav-item"><a href="reports.php" class="nav-link active"><i class="fas fa-chart-line"></i><span>Reports</span></a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i><span>Settings</span></a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="nav-link logout-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>
    <main class="main-content">
        <div class="report-card" style="padding: 1.25rem; border-radius: 16px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <div>
                    <h2 style="font-size: 1.25rem; font-weight: 800; letter-spacing: -0.02em;">Attendance Report</h2>
                    <p style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;">Real-time monitoring of all staff time logs.</p>
                </div>
                
                <div style="display: flex; gap: 0.75rem; align-items: center;">
                    <form method="GET" class="filter-bar" style="margin-bottom: 0; padding: 0.4rem 0.75rem; border-radius: 10px; gap: 0.6rem;">
                        <span style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Filter by Date</span>
                        <input type="date" name="filter_date" class="filter-input" style="padding: 0.35rem 0.6rem; font-size: 0.65rem; border-radius: 6px;" value="<?php echo htmlspecialchars($filter_date); ?>">
                        <button type="submit" class="btn-filter" style="padding: 0.35rem 1rem; font-size: 0.65rem; border-radius: 6px;">Apply Filter</button>
                        <?php if (!empty($filter_date)): ?>
                            <a href="reports.php" style="color: var(--text-muted); font-size: 0.6rem; text-decoration: none; font-weight: 700;">Clear</a>
                        <?php endif; ?>
                    </form>
                    
                    <a href="export_report.php?filter_date=<?php echo $filter_date; ?>" class="btn-filter" style="background: #10b981; text-decoration: none; padding: 0.45rem 1rem; font-size: 0.65rem; display: flex; align-items: center; gap: 0.5rem; border-radius: 10px;">
                        <i class="fas fa-file-excel"></i> EXCEL
                    </a>
                </div>
            </div>
            
            <div class="table-container">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.7rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--card-border); text-transform: uppercase;">
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Account Name</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Role</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Time In</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Time Out</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Date</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Total Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">No attendance records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): 
                                // Calculate hours
                                $total_hrs = '--:--';
                                if ($row['time_in'] && $row['time_out']) {
                                    $start = new DateTime($row['time_in']);
                                    $end = new DateTime($row['time_out']);
                                    $diff = $start->diff($end);
                                    $total_hrs = $diff->format('%H:%I:%S');
                                }
                            ?>
                                <tr style="border-bottom: 1px solid var(--card-border); transition: background 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                    <td style="padding: 0.65rem 0.85rem;">
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div class="avatar" style="width: 24px; height: 24px; font-size: 0.65rem; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                                <?php echo strtoupper(substr($row['acc_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 800; color: white; line-height: 1.1; font-size: 0.75rem;"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                <div style="font-size: 0.6rem; color: var(--text-muted);">@<?php echo htmlspecialchars($row['acc_name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem;">
                                        <span class="badge" style="background: rgba(99, 102, 241, 0.1); color: #6366f1; font-size: 0.55rem; padding: 0.2rem 0.5rem; border-radius: 4px; font-weight: 900; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($row['role']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem; color: #10b981; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_in'])); ?></td>
                                    <td style="padding: 0.65rem 0.85rem; color: #ef4444; font-weight: 800; font-size: 0.75rem;">
                                        <?php echo $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : 'PENDING'; ?>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem; color: var(--text-muted); font-size: 0.7rem;"><?php echo date('M d, Y', strtotime($row['attendance_date'])); ?></td>
                                    <td style="padding: 0.65rem 0.85rem; font-family: monospace; font-weight: 900; color: white; font-size: 0.75rem;"><?php echo $total_hrs; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
