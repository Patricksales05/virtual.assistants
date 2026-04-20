<?php
require_once 'db_config.php';
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
$user_name = $_SESSION['username'];
$role = $_SESSION['role'];

// Fetch attendance logs for operations review with date filtering
$date_start = $_GET['start'] ?? '';
$date_end = $_GET['end'] ?? '';

try {
    $sql = "
        SELECT a.*, u.full_name, u.role, u.username as acc_name 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER', 'Team Lead', 'Operations Manager')
    ";
    $params = [];
    
    if (!empty($date_start) && !empty($date_end)) {
        $sql .= " AND a.attendance_date BETWEEN :start AND :end";
        $params['start'] = $date_start;
        $params['end'] = $date_end;
    }
    
    $sql .= " ORDER BY a.attendance_date DESC, a.time_in DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attendance_logs = $stmt->fetchAll();
} catch (PDOException $e) { $attendance_logs = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Audit - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #6366f1; --bg-dark: #0f172a; --card-glass: rgba(15, 23, 42, 0.7); --text-muted: #94a3b8; --card-border: rgba(255, 255, 255, 0.1); --accent-green: #10b981; --accent-red: #ef4444; }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Outfit',sans-serif; }
        body { 
            background: linear-gradient(-45deg, #0f172a, #111827, #1e293b, #1e1b4b);
            background-size: 300% 300%;
            animation: gradientBG 15s ease infinite;
            color: #f8fafc; min-height: 100vh; display: flex; 
        }
        @keyframes gradientBG { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        
        .sidebar { width: 230px; background: rgba(15, 23, 42, 0.9); border-right: 1px solid var(--card-border); padding: 1.5rem 1rem; display: flex; flex-direction: column; height: 100vh; position: sticky; top: 0; }
        .logo-img { max-width: 100%; height: auto; margin-bottom: 2rem; filter: brightness(0) invert(1); }
        .nav-link { text-decoration: none; color: var(--text-muted); padding: 0.75rem 1.15rem; border-radius: 12px; display: flex; align-items: center; gap: 1rem; transition: all 0.3s; margin-bottom: 0.35rem; white-space: nowrap; }
        .nav-link:hover, .nav-link.active { background: rgba(99, 102, 241, 0.1); color: var(--primary-color); }
        .nav-link.active { background: var(--primary-color); color: white; }
        
        .main { flex: 1; padding: 1.5rem; }
        .card { background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem; animation: fadeInUp 0.6s ease; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        table { width: 100%; border-collapse: collapse; margin-top: 2rem; }
        th { text-align: left; color: var(--text-muted); font-size: 0.7rem; font-weight: 800; text-transform: uppercase; padding: 0.85rem 1rem; border-bottom: 1px solid var(--card-border); }
        td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--card-border); font-size: 0.85rem; }
        .avatar { width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        <a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> <span class="nav-text">Home</span></a>
        <a href="users.php" class="nav-link"><i class="fas fa-users"></i> <span class="nav-text">Dashboard</span></a>
        <a href="reports.php" class="nav-link active"><i class="fas fa-chart-line"></i> <span class="nav-text">Staff Audit</span></a>
        <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> <span class="nav-text">Settings</span></a>
        <div style="margin-top:auto; padding-top:2rem; border-top:1px solid var(--card-border);">
            <a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> <span class="nav-text">Logout</span></a>
        </div>
    </aside>

    <main class="main">
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem;">
            <div>
                <h1 style="font-size: 1.85rem; font-weight: 800; letter-spacing: -0.04em;">Staff Audit</h1>
                <p style="color: var(--text-muted); font-size: 0.8rem;">Global ledger of staff deployment and professional performance.</p>
            </div>
            
            <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end; background: rgba(30, 41, 59, 0.4); padding: 1rem 1.5rem; border-radius: 20px; border: 1px solid var(--card-border); backdrop-filter: blur(10px);">
                <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                    <label style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">Start Period</label>
                    <input type="date" name="start" value="<?php echo htmlspecialchars($date_start); ?>" style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--card-border); border-radius: 10px; padding: 0.6rem 0.75rem; color: white; outline: none; font-size: 0.85rem; font-weight: 600;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                    <label style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">End Period</label>
                    <input type="date" name="end" value="<?php echo htmlspecialchars($date_end); ?>" style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--card-border); border-radius: 10px; padding: 0.6rem 0.75rem; color: white; outline: none; font-size: 0.85rem; font-weight: 600;">
                </div>
                <button type="submit" style="background: var(--primary-color); color: white; border: none; padding: 0.7rem 1.75rem; border-radius: 10px; font-weight: 800; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.75rem;">
                    <i class="fas fa-filter"></i> Apply
                </button>
                <?php if (!empty($date_start)): ?>
                    <a href="reports.php" style="background: rgba(255,255,255,0.05); color: var(--text-muted); text-decoration: none; padding: 0.7rem 1.25rem; border-radius: 10px; font-weight: 800; font-size: 0.75rem; border: 1px solid var(--card-border);">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <section class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--card-border);">
                <h3 style="font-weight: 800; color: white;"><i class="fas fa-list-ul" style="color: var(--primary-color); margin-right: 0.75rem;"></i> Deployment Ledger</h3>
                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;"><?php echo count($attendance_logs); ?> total entries found</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendance_logs)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted);">No operational logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($attendance_logs as $log): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <div class="avatar"><?php echo strtoupper(substr($log['acc_name'], 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($log['full_name']); ?></div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">@<?php echo htmlspecialchars($log['acc_name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color:var(--accent-green); font-weight:600;"><?php echo date('h:i A', strtotime($log['time_in'])); ?></td>
                                <td style="color:var(--accent-red); font-weight:600;"><?php echo $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : 'PENDING'; ?></td>
                                <td><?php echo date('M d, Y', strtotime($log['attendance_date'])); ?></td>
                                <td><span style="background:rgba(99, 102, 241, 0.1); color:var(--primary-color); padding:0.2rem 0.6rem; border-radius:100px; font-size:0.75rem; font-weight:700;">STAFF</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
