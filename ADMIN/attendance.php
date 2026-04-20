<?php
require_once 'db_config.php';
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    header("Location: index.php");
    exit();
}
$user_name = $_SESSION['username'];

// Handle Deletion
if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM attendance WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    header("Location: attendance.php?status=deleted");
    exit();
}

// Fetch attendance data
try {
    $stmt = $pdo->query("
        SELECT a.*, u.full_name, u.role, u.username as acc_name 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        ORDER BY a.attendance_date DESC, a.time_in DESC
    ");
    $attendance_records = $stmt->fetchAll();
} catch (PDOException $e) {
    $attendance_records = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Management - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary-color: #6366f1; --bg-dark: #0f172a; --card-glass: rgba(15, 23, 42, 0.7); --text-muted: #94a3b8; --card-border: rgba(255, 255, 255, 0.1); --accent-red: #ef4444; --accent-green: #10b981; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { 
            background: linear-gradient(-45deg, #0f172a, #1e293b, #111827, #1e1b4b);
            background-size: 300% 300%;
            animation: gradientBG 15s ease infinite;
            color: #f8fafc; 
            min-height: 100vh; 
            display: flex; 
        }
        @keyframes gradientBG { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        
        .sidebar {
            width: 200px;
            background: rgba(15, 23, 42, 0.95);
            border-right: 1px solid var(--card-border);
            padding: 1rem 0.75rem;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
        }
        .logo-container { margin-bottom: 2rem; display: flex; align-items: center; justify-content: center; }
        .logo-img { width: 130px; filter: brightness(0) invert(1); image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; margin-bottom: 0; }
        .nav-menu { list-style: none; flex: 1; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { 
            text-decoration: none; 
            color: var(--text-muted); 
            padding: 0.5rem 0.75rem; 
            border-radius: 10px; 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            transition: all 0.3s; 
            font-weight: 600; 
            font-size: 0.7rem;
        }
        .nav-link:hover, .nav-link.active { background: rgba(99, 102, 241, 0.05); color: var(--primary-color); }
        .nav-link.active { background: var(--primary-color); color: white; box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.3); }
        .nav-link i { width: 18px; text-align: center; }

        .main-content {
            flex: 1;
            margin-left: 200px;
            padding: 1.25rem 1.75rem;
            width: calc(100% - 200px);
        }
        .card { background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 24px; padding: 2rem; animation: fadeInUp 0.6s ease; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .table-container { overflow-x: auto; margin-top: 2rem; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; color: var(--text-muted); font-size: 0.8rem; font-weight: 700; text-transform: uppercase; padding: 1rem; border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; border-bottom: 1px solid var(--card-border); font-size: 0.95rem; }
        .avatar { width: 32px; height: 32px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; }
        .btn-delete { color: var(--accent-red); background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); padding: 0.5rem; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .btn-delete:hover { background: var(--accent-red); color: white; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Users</span></a></li>
            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Reports</span></a></li>
            <li class="nav-item"><a href="attendance.php" class="nav-link active"><i class="fas fa-calendar-check"></i><span>Attendance</span></a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i><span>Settings</span></a></li>
        </ul>
        <div style="margin-top:auto; padding-top: 1rem; border-top: 1px solid var(--card-border);">
            <a href="logout.php" class="nav-link" style="color: var(--accent-red); padding: 0.5rem 0.75rem;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
        <div class="card" style="padding: 1.25rem; border-radius: 16px;">
            <h2 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 0.25rem; letter-spacing: -0.02em;">Attendance Management</h2>
            <p style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;">Monitor and manage all staff work sessions.</p>

            <div class="table-container" style="margin-top: 1rem;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.7rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--card-border); text-transform: uppercase;">
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Account</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Role</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Time In</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Time Out</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Date</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: center; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attendance_records)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">No records found in XAMPP.</td></tr>
                        <?php else: ?>
                            <?php foreach ($attendance_records as $row): ?>
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
                                        <span style="background:rgba(99, 102, 241, 0.1); color:var(--primary-color); padding: 0.2rem 0.5rem; border-radius:4px; font-size:0.55rem; font-weight:900; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($row['role']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem; color:#10b981; font-weight:800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_in'])); ?></td>
                                    <td style="padding: 0.65rem 0.85rem; color:#ef4444; font-weight:800; font-size: 0.75rem;"><?php echo $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : 'PENDING'; ?></td>
                                    <td style="padding: 0.65rem 0.85rem; color: var(--text-muted); font-size: 0.7rem;"><?php echo date('M d, Y', strtotime($row['attendance_date'])); ?></td>
                                    <td style="padding: 0.65rem 0.85rem; text-align: center;">
                                        <button onclick="confirmDelete(<?php echo $row['id']; ?>)" style="color: #ef4444; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); width: 24px; height: 24px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; transition: 0.3s; cursor: pointer;"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        function confirmDelete(id) {
            Swal.fire({
                title: 'Delete this record?',
                text: "This action cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#1e293b',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'attendance.php?delete_id=' + id;
                }
            })
        }

        <?php if (isset($_GET['status']) && $_GET['status'] == 'deleted'): ?>
            Swal.fire({ icon: 'success', title: 'Deleted!', text: 'Record removed successfully.', timer: 2000, showConfirmButton: false });
        <?php endif; ?>
    </script>
</body>
</html>
