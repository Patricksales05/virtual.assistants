<?php
require_once 'db_config.php';

$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || ($current_role !== 'staff' && $current_role !== 'staff member')) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];

$date_start = $_GET['start'] ?? '';
$date_end = $_GET['end'] ?? '';

// Fetch filtered history for this user
try {
    $sql = "SELECT * FROM attendance WHERE user_id = :user_id";
    $params = ['user_id' => $user_id];
    
    if (!empty($date_start) && !empty($date_end)) {
        $sql .= " AND attendance_date BETWEEN :start AND :end";
        $params['start'] = $date_start;
        $params['end'] = $date_end;
    }
    
    $sql .= " ORDER BY attendance_date DESC, time_in DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll();
} catch (PDOException $e) {
    $history = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My History - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --secondary-color: #818cf8;
            --bg-dark: #0f172a;
            --card-glass: rgba(15, 23, 42, 0.7);
            --card-border: rgba(255, 255, 255, 0.1);
            --header-glass: rgba(15, 23, 42, 0.9);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-green: #10b981;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { background: #020617; color: var(--text-main); min-height: 100vh; display: flex; font-size: 0.75rem; }

        /* Sidebar Styling (Sync with Dashboard) */
        .sidebar { width: 195px; background: var(--header-glass); border-right: 1px solid var(--card-border); padding: 1.5rem 1rem; display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; }
        .logo-img { width: 140px; filter: brightness(0) invert(1); margin-bottom: 2rem; }
        .nav-menu { list-style: none; flex: 1; }
        .nav-link { text-decoration: none; color: var(--text-muted); padding: 0.6rem 1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.75rem; transition: all 0.3s; font-weight: 600; font-size: 0.75rem; }
        .nav-link:hover { background: rgba(99, 102, 241, 0.08); color: var(--primary-color); }
        .nav-link.active { background: #6366f1; color: white; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4); }

        .main-content { flex: 1; padding: 1.5rem; background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.1) 0%, transparent 50%); }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }

        .history-card { background: var(--card-glass); backdrop-filter: blur(20px); border-radius: 20px; padding: 1.25rem 1.5rem; border: 1px solid var(--card-border); }
        
        /* Calendar Search Filters */
        .filter-form { display: flex; align-items: flex-end; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--card-border); padding-bottom: 1.25rem; }
        .input-group { display: flex; flex-direction: column; gap: 0.35rem; flex: 1; }
        .input-group label { font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted); font-weight: 800; }
        .date-input { background: rgba(255,255,255,0.03); border: 1px solid var(--card-border); border-radius: 8px; padding: 0.5rem 0.75rem; color: white; outline: none; transition: all 0.3s; font-size: 0.75rem; }
        .date-input:focus { border-color: var(--primary-color); background: rgba(99, 102, 241, 0.05); box-shadow: 0 0 15px rgba(99, 102, 241, 0.2); }
        
        .btn-filter { background: var(--primary-color); color: white; border: none; padding: 0.5rem 1.25rem; border-radius: 10px; font-weight: 800; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); display: flex; align-items: center; gap: 0.5rem; }
        .btn-filter:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4); opacity: 0.9; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.75rem; color: var(--text-muted); font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border); }
        td { padding: 0.85rem 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 0.8rem; }
        
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; background: rgba(16, 185, 129, 0.1); color: var(--accent-green); }
    </style>
</head>
<body>
    <aside class="sidebar">
        <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="dashboard.php?view=profile" class="nav-link"><i class="fas fa-user-circle"></i><span>Profile</span></a></li>
            <li class="nav-item"><a href="dashboard.php?view=history" class="nav-link active"><i class="fas fa-history"></i><span>History</span></a></li>
        </ul>
    </aside>

    <main class="main-content">
        <header class="header-top">
            <div>
                <h1 style="font-size: 1.15rem; font-weight: 800;">Attendance History</h1>
                <p style="color: var(--text-muted); font-size: 0.65rem;">Comprehensive log of your operational performance.</p>
            </div>
        </header>

        <section class="history-card">
            <form action="" method="GET" class="filter-form">
                <div class="input-group">
                    <label>Start Date</label>
                    <input type="date" name="start" class="date-input" value="<?php echo htmlspecialchars($date_start); ?>">
                </div>
                <div class="input-group">
                    <label>End Date</label>
                    <input type="date" name="end" class="date-input" value="<?php echo htmlspecialchars($date_end); ?>">
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-calendar-check"></i> Filter
                </button>
                <?php if(!empty($date_start)): ?>
                    <a href="history.php" class="btn-filter" style="background: rgba(255,255,255,0.05); color: var(--text-muted); box-shadow: none;">Reset</a>
                <?php endif; ?>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Deployment (In)</th>
                        <th>Extraction (Out)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($history)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 4rem; color: var(--text-muted);">
                        <i class="fas fa-search" style="font-size: 2rem; display: block; margin-bottom: 1rem; opacity: 0.2;"></i>
                        No records found for this period.
                    </td></tr>
                    <?php else: ?>
                        <?php foreach($history as $row): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: white;"><?php echo date('h:i A', strtotime($row['time_in'])); ?></div>
                                <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($row['time_in'])); ?></div>
                            </td>
                            <td>
                                <?php if ($row['time_out']): ?>
                                    <div style="font-weight: 700; color: white;"><?php echo date('h:i A', strtotime($row['time_out'])); ?></div>
                                    <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($row['time_out'])); ?></div>
                                <?php else: ?>
                                    <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">PENDING</div>
                                    <div style="font-size: 0.65rem; color: var(--text-muted);">In Session</div>
                                <?php endif; ?>
                            </td>
                            <td><span class="status-badge"><?php echo $row['time_out'] ? 'Completed' : 'Active'; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
