<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || ($current_role !== 'staff' && $current_role !== 'staff member')) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];
$role = $_SESSION['role'];
$attendance = null;
$total_today = "00:00:00";

// Get filter date
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');

// Fetch current attendance and filtered list
try {
    $today = date('Y-m-d');
    
    // Current ongoing session
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id, $today]);
    $attendance = $stmt->fetch();
    
    if ($attendance) {
        $start = new DateTime($attendance['time_in']);
        if ($attendance['time_out']) {
            $end = new DateTime($attendance['time_out']);
            $interval = $start->diff($end);
            $total_today = $interval->format('%H:%I:%S');
        } else {
            $now = new DateTime();
            $interval = $start->diff($now);
            $total_today = $interval->format('%H:%I:%S');
        }
    }

    // Filtered Deployment Logs
    $query = "SELECT * FROM attendance WHERE user_id = ?";
    $params = [$user_id];
    
    if (isset($_GET['filter_date']) && !empty($_GET['filter_date'])) {
        $query .= " AND attendance_date = ?";
        $params[] = $_GET['filter_date'];
    }
    
    $query .= " ORDER BY id DESC LIMIT 15";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $attendance_history = $stmt->fetchAll();

} catch (PDOException $e) { $attendance_history = []; }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Control - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Flatpickr for Modern Calendar -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            --accent-red: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { 
            background: #020617;
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
            font-size: 0.75rem;
        }

        /* Sidebar Styling */
        .sidebar { width: 195px; background: var(--header-glass); border-right: 1px solid var(--card-border); padding: 1rem 1rem; display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; z-index: 100; }
        .logo-container { margin-bottom: 2rem; text-align: center; }
        .logo-img { width: 140px; filter: brightness(0) invert(1); transition: all 0.5s ease; cursor: pointer; }
        
        .nav-menu { list-style: none; flex: 1; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { text-decoration: none; color: var(--text-muted); padding: 0.6rem 1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.75rem; transition: all 0.3s; font-weight: 600; font-size: 0.75rem; }
        .nav-link:hover, .nav-link.active { background: rgba(99, 102, 241, 0.08); color: var(--primary-color); }
        .nav-link.active { background: var(--primary-color); color: white; box-shadow: 0 10px 20px rgba(99, 102, 241, 0.2); }

        /* Main Content */
        .main-content { flex: 1; padding: 1.5rem; width: calc(100% - 195px); display: flex; flex-direction: column; gap: 1.5rem; align-items: center; }

        .attendance-card { background: var(--card-glass); backdrop-filter: blur(20px); border-radius: 20px; border: 1px solid var(--card-border); padding: 1.5rem; width: 100%; max-width: 650px; box-shadow: 0 40px 80px rgba(0,0,0,0.5); text-align: center; }
        .digital-clock { font-size: 2.2rem; font-weight: 800; color: white; line-height: 1; margin-bottom: 0.25rem; letter-spacing: -0.04em; }
        .date-display { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.15em; margin-bottom: 1rem; }

        .btn-attendance { padding: 0.6rem 2.5rem; border: none; border-radius: 100px; font-size: 0.85rem; font-weight: 800; cursor: pointer; transition: 0.4s; display: inline-flex; align-items: center; gap: 0.6rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .btn-time-in { background: var(--accent-green); color: white; box-shadow: 0 10px 20px rgba(16, 185, 129, 0.2); }
        .btn-time-out { background: var(--accent-red); color: white; box-shadow: 0 10px 20px rgba(239, 68, 68, 0.2); }
        .btn-attendance:hover { transform: translateY(-3px); }

        /* History Table Header with Calendar */
        .history-card { background: var(--card-glass); border-radius: 20px; border: 1px solid var(--card-border); padding: 1.25rem 1.5rem; width: 100%; max-width: 650px; backdrop-filter: blur(20px); }
        .history-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.1rem; gap: 1rem; }
        .filter-group { display: flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); padding: 0.35rem 0.85rem; border-radius: 100px; }
        .filter-input { background: transparent; border: none; color: white; outline: none; font-weight: 700; width: 90px; font-size: 0.75rem; }
        .filter-btn { background: var(--primary-color); color: white; border: none; padding: 0.35rem 1rem; border-radius: 100px; font-weight: 800; font-size: 0.65rem; text-transform: uppercase; cursor: pointer; transition: 0.3s; }
        .filter-btn:hover { background: var(--primary-hover); transform: translateY(-2px); }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.75rem; font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--card-border); }
        td { padding: 0.85rem 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 0.8rem; }
        .status-pill { padding: 0.25rem 0.75rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></li>
            <li class="nav-item"><a href="dashboard.php?view=attendance" class="nav-link active"><i class="fas fa-clock"></i> Attendance</a></li>
            <li class="nav-item"><a href="dashboard.php?view=pto" class="nav-link"><i class="fas fa-calendar-alt"></i> PTO Request</a></li>
            <li class="nav-item"><a href="dashboard.php?view=profile" class="nav-link"><i class="fas fa-user-circle"></i> Profile</a></li>
        </ul>
        <div style="margin-top:auto; padding-top:1.5rem; border-top:1px solid var(--card-border);">
            <a href="logout.php" class="nav-link" style="color:var(--accent-red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="attendance-card">
            <span style="font-size: 0.7rem; font-weight: 800; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.4em; display: block; margin-bottom: 1rem;">Session Control</span>
            <div class="digital-clock" id="clock">00:00:00 PM</div>
            <div class="date-display" id="date"><?php echo date('l, F d, Y'); ?></div>

            <div style="margin: 2rem 0;">
                <?php if (!$attendance): ?>
                    <button class="btn-attendance btn-time-in" onclick="processAttendance('time_in')"><i class="fas fa-sign-in-alt"></i> Time In</button>
                <?php elseif (!$attendance['time_out']): ?>
                    <button class="btn-attendance btn-time-out" onclick="processAttendance('time_out')"><i class="fas fa-sign-out-alt"></i> Time Out</button>
                    <p style="margin-top: 1rem; color: var(--accent-green); font-weight: 600; font-size: 0.75rem;">Deployment Start: <?php echo date('h:i A', strtotime($attendance['time_in'])); ?></p>
                <?php else: ?>
                    <div style="background: rgba(16, 185, 129, 0.1); padding: 0.75rem 2rem; border-radius: 100px; color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 0.6rem; font-size: 0.8rem;">
                        <i class="fas fa-check-circle"></i> <span style="font-weight: 800; text-transform: uppercase;">Mission Completed</span>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                <div style="border-right: 1px solid rgba(255,255,255,0.05);">
                    <span style="font-size: 0.55rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800;">Entry</span>
                    <h3 style="margin-top: 0.15rem; color: white; font-size: 0.85rem;"><?php echo $attendance ? date('h:i A', strtotime($attendance['time_in'])) : '--:-- --'; ?></h3>
                </div>
                <div style="border-right: 1px solid rgba(255,255,255,0.05);">
                    <span style="font-size: 0.55rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800;">Exit</span>
                    <h3 style="margin-top: 0.15rem; color: white; font-size: 0.85rem;"><?php echo ($attendance && $attendance['time_out']) ? date('h:i A', strtotime($attendance['time_out'])) : '--:-- --'; ?></h3>
                </div>
                <div>
                    <span style="font-size: 0.55rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800;">Duration</span>
                    <h3 style="margin-top: 0.15rem; color: var(--primary-color); font-weight: 800; font-size: 0.85rem;"><?php echo $total_today; ?></h3>
                </div>
            </div>
        </div>

        <section class="history-card">
            <div class="history-controls">
                <div>
                    <h2 style="font-weight: 800; font-size: 1.15rem; letter-spacing: -0.02em;">Deployment Logs</h2>
                    <p style="font-size: 0.6rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-top: 0.15rem;">Intelligence Discovery</p>
                </div>
                <form action="" method="GET" class="filter-group">
                    <i class="fas fa-calendar-alt" style="color: var(--primary-color); font-size: 0.9rem;"></i>
                    <input type="text" name="filter_date" id="calendarFilter" class="filter-input" placeholder="Select Date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    <button type="submit" class="filter-btn">Search</button>
                    <a href="attendance.php" style="color:var(--text-muted); font-size: 0.7rem; margin-left: 0.5rem;"><i class="fas fa-undo"></i></a>
                </form>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Operator Cycle</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Deployment Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendance_history)): ?>
                        <tr><td colspan="4" style="text-align: center; padding: 4rem; color: var(--text-muted); font-weight: 700;">Zero deployments found for selected parameters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($attendance_history as $log): ?>
                        <tr>
                            <td style="color: white; font-weight: 700;"><?php echo date('M d, Y', strtotime($log['attendance_date'])); ?></td>
                            <td style="color: var(--accent-green); font-weight: 600;"><?php echo date('h:i A', strtotime($log['time_in'])); ?></td>
                            <td style="color: var(--accent-red); font-weight: 600;"><?php echo $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : '--:-- --'; ?></td>
                            <td>
                                <span class="status-pill" style="background: <?php echo $log['time_out'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(99, 102, 241, 0.10)'; ?>; color: <?php echo $log['time_out'] ? 'var(--accent-green)' : 'var(--primary-color)'; ?>;">
                                    <?php echo $log['time_out'] ? 'Completed' : 'Active Duty'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>

    <!-- Flatpickr Script -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Init Flatpickr (Modern Calendar)
        flatpickr("#calendarFilter", {
            dateFormat: "Y-m-d",
            disableMobile: "true",
            theme: "dark"
        });

        function updateClock() {
            const now = new Date();
            document.getElementById('clock').innerText = now.toLocaleTimeString('en-US', { hour12: true });
        }
        setInterval(updateClock, 1000);
        updateClock();

        function processAttendance(type) {
            Swal.fire({
                title: 'Confirm Operation?',
                text: `Executing ${type.replace('_', ' ')} protocol.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: type === 'time_in' ? '#10b981' : '#ef4444',
                cancelButtonColor: '#1e293b',
                confirmButtonText: `YES, ${type.replace('_', ' ').toUpperCase()}`
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'attendance_process.php?action=' + type;
                }
            });
        }
    </script>
</body>
</html>
