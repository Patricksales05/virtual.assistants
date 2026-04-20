<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    header("Location: index.php");
    exit();
}

$user_name = $_SESSION['username'];
$role = $_SESSION['role'];

// Handle Approval
if (isset($_GET['approve'])) {
    $id = $_GET['approve'];
    $stmt = $pdo->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: users.php?success=approved");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: users.php?success=deleted");
    exit();
}

// Fetch all users
try {
    $users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
} catch (PDOException $e) {
    $users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
            --bg-dark: #0f172a;
            --card-glass: rgba(15, 23, 42, 0.7);
            --header-glass: rgba(15, 23, 42, 0.9);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --card-border: rgba(255, 255, 255, 0.1);
            --accent-green: #10b981;
            --accent-red: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { 
            background: linear-gradient(-45deg, #0f172a, #111827, #1e293b, #1e1b4b);
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

        .sidebar {
            width: 250px;
            background: rgba(15, 23, 42, 0.95);
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
        .logo-container { margin-bottom: 3rem; display: flex; align-items: center; justify-content: center; }
        .logo-img { width: 180px; filter: brightness(0) invert(1); image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; }
        .nav-menu { list-style: none; flex: 1; }
        .nav-item { margin-bottom: 0.5rem; }
        .nav-link { 
            text-decoration: none; 
            color: var(--text-muted); 
            padding: 0.85rem 1.25rem; 
            border-radius: 12px; 
            display: flex; 
            align-items: center; 
            gap: 1rem; 
            transition: all 0.3s; 
            font-weight: 600; 
            font-size: 0.85rem;
        }
        .nav-link:hover, .nav-link.active { background: rgba(99, 102, 241, 0.1); color: var(--primary-color); }
        .nav-link.active { background: var(--primary-color); color: white; box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.3); }
        .nav-link i { width: 22px; text-align: center; font-size: 1rem; }

        .main-content { 
            flex: 1; 
            margin-left: 250px; 
            padding: 2rem 3rem; 
            width: calc(100% - 250px); 
        }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3rem; }
        
        .content-card { background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 24px; padding: 2rem; }
        .card-header { margin-bottom: 2rem; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; color: var(--text-muted); font-size: 0.8rem; font-weight: 700; text-transform: uppercase; padding: 1rem; border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; border-bottom: 1px solid var(--card-border); font-size: 0.95rem; }

        .avatar { width: 36px; height: 36px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; }
        .badge { padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.75rem; font-weight: 700; }
        .badge-success { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }

        .btn-action { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--card-border); display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; cursor: pointer; color: var(--text-muted); font-size: 0.9rem; }
        .btn-approve:hover { background: rgba(16, 185, 129, 0.2); color: #10b981; border-color: #10b981; }
        .btn-delete:hover { background: rgba(239, 68, 68, 0.2); color: #ef4444; border-color: #ef4444; }

        @keyframes fadeInUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        .sidebar { animation: fadeInUp 0.6s ease; }
        .top-header { animation: fadeInUp 0.6s ease 0.1s both; }
        .content-card { animation: fadeInUp 0.6s ease 0.2s both; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Logo" class="logo-img">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="users.php" class="nav-link active"><i class="fas fa-users"></i><span>Users</span></a></li>
            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Reports</span></a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i><span>Settings</span></a></li>
        </ul>
        <div style="margin-top:auto; padding-top: 2rem; border-top: 1px solid var(--card-border);">
            <a href="logout.php" class="nav-link" style="color: #ff4d4d !important; padding: 0.85rem 1.25rem;"><i class="fas fa-sign-out-alt" style="color: #ff4d4d !important;"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header" style="margin-bottom: 1.5rem;">
            <div class="welcome-msg">
                <h2 style="font-size: 1.25rem; font-weight: 800; letter-spacing: -0.02em;">User Management</h2>
                <p style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;">Root authorization for all registered operational nodes.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center;">
                <a href="create_user.php" class="nav-link active" style="padding: 0.4rem 0.85rem; font-size: 0.6rem; border: none; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; background: var(--primary-color); color: white; border-radius: 8px; text-decoration: none;">
                    <i class="fas fa-user-plus"></i> ADD NEW NODE
                </a>
                <div class="user-profile" style="background: rgba(30, 41, 59, 0.5); padding: 0.25rem 0.35rem 0.25rem 0.85rem; border-radius: 100px; border: 1px solid var(--card-border); gap: 0.5rem; display: flex; align-items: center;">
                    <span style="font-size: 0.65rem; font-weight: 700; color: white;"><?php echo htmlspecialchars($user_name); ?></span>
                    <div class="avatar" style="width: 24px; height: 24px; font-size: 0.65rem; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                </div>
            </div>
        </header>
        
        <section class="content-card" style="padding: 1rem; border-radius: 16px;">
            <div class="table-container">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.7rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--card-border); text-transform: uppercase;">
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">User Details</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Role</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Location</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: left; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Status</th>
                            <th style="padding: 0.75rem 0.85rem; text-align: center; color: var(--text-muted); font-weight: 800; font-size: 0.6rem; letter-spacing: 0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">No operational nodes registered.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr style="border-bottom: 1px solid var(--card-border); transition: background 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                    <td style="padding: 0.65rem 0.85rem;">
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div class="avatar" style="width: 24px; height: 24px; font-size: 0.65rem; background: var(--primary-color); border: none; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 800; color: white; line-height: 1.1; font-size: 0.75rem;"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></div>
                                                <div style="font-size: 0.6rem; color: var(--text-muted);">@<?php echo htmlspecialchars($user['username']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem;">
                                        <span style="font-size: 0.55rem; font-weight: 900; color: <?php 
                                            echo (strtolower($user['role']) === 'admin') ? '#ef4444' : 
                                                 ((strtolower($user['role']) === 'team lead') ? '#10b981' : '#6366f1'); 
                                        ?>; text-transform: uppercase; background: rgba(0,0,0,0.2); padding: 0.2rem 0.5rem; border-radius: 4px;">
                                            <?php echo htmlspecialchars($user['role']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem; color: var(--text-muted); font-size: 0.65rem;">
                                        <?php echo htmlspecialchars($user['city'] . ', ' . $user['region']); ?>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem;">
                                        <?php if ($user['is_approved']): ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #10b981; font-size: 0.5rem; padding: 0.15rem 0.5rem; border: 1px solid rgba(16, 185, 129, 0.2); font-weight: 800;">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; font-size: 0.5rem; padding: 0.15rem 0.5rem; border: 1px solid rgba(245, 158, 11, 0.2); font-weight: 800;">PENDING</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.65rem 0.85rem; text-align: center;">
                                        <div style="display: flex; gap: 0.4rem; justify-content: center;">
                                            <?php if (!$user['is_approved']): ?>
                                                <a href="users.php?approve=<?php echo $user['id']; ?>" style="color: #10b981; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); width: 24px; height: 24px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; transition: 0.3s;"><i class="fas fa-check"></i></a>
                                            <?php endif; ?>
                                            <button onclick="confirmDelete(<?php echo $user['id']; ?>)" style="color: #ef4444; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); width: 24px; height: 24px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; transition: 0.3s; cursor: pointer;"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        function confirmDelete(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "This will permanently remove the user account.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6366f1',
                confirmButtonText: 'Yes, delete it!',
                background: '#1e293b',
                color: '#f8fafc'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'users.php?delete=' + id;
                }
            });
        }

        // Success Alerts
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) {
            const type = urlParams.get('success');
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: type === 'approved' ? 'User approved successfully.' : 'User deleted successfully.',
                background: '#1e293b',
                color: '#f8fafc',
                timer: 3000,
                showConfirmButton: false
            });
        }
    </script>
</body>
</html>
