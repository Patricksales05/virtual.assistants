<?php
require_once 'db_config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    header("Location: index.php");
    exit();
}
$user_name = $_SESSION['username'];
$role = $_SESSION['role'];

// Fetch all members for OM oversight (excludes Admin for hierarchy clarity)
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role NOT IN ('Admin', 'ADMIN', 'admin') ORDER BY id DESC");
    $stmt->execute();
    $staff_members = $stmt->fetchAll();

    // Fetch total pending Approvals for the Sidebar Badge
    $pending_approvals = $pdo->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetchColumn();
} catch (PDOException $e) { $staff_members = []; $pending_approvals = 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Directory - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #6366f1; --bg-dark: #0f172a; --card-glass: rgba(15, 23, 42, 0.7); --text-muted: #94a3b8; --card-border: rgba(255, 255, 255, 0.1); --accent-green: #10b981; }
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
        
        .btn-add { background: var(--primary-color); color: white; border: none; border-radius: 12px; padding: 0.75rem 1.5rem; font-size: 0.95rem; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.75rem; transition: all 0.3s; }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4); }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <aside class="sidebar">
        <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        <a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> <span class="nav-text">Home</span></a>
        <a href="users.php" class="nav-link active">
            <i class="fas fa-users"></i> <span class="nav-text">Dashboard</span>
            <?php if ($pending_approvals > 0): ?>
                <span style="background: #ef4444; color: white; padding: 0.1rem 0.5rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; margin-left: auto;">
                    <?php echo $pending_approvals; ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="reports.php" class="nav-link"><i class="fas fa-chart-line"></i> <span class="nav-text">Staff Audit</span></a>
        <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> <span class="nav-text">Settings</span></a>
        <div style="margin-top:auto; padding-top:2rem; border-top:1px solid var(--card-border);">
            <a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> <span class="nav-text">Logout</span></a>
        </div>
    </aside>

    <main class="main">
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem;">
                <div>
                    <h2 style="font-size: 1.45rem; font-weight: 800; margin-bottom: 0.25rem;">Personnel Dashboard</h2>
                    <p style="color: var(--text-muted); font-size: 0.8rem;">Manage and monitor your assigned staff tier.</p>
                </div>
                <a href="create_user.php" class="btn-add"><i class="fas fa-user-plus"></i> Add New User</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staff_members)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted);">No staff found in XAMPP database.</td></tr>
                    <?php else: ?>
                        <?php foreach ($staff_members as $member): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <div class="avatar"><?php echo strtoupper(substr($member['username'], 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">@<?php echo htmlspecialchars($member['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($member['email']); ?></td>
                                <td><?php echo htmlspecialchars($member['phone_number']); ?></td>
                                <td>
                                    <?php 
                                        $badge_style = "background:rgba(99, 102, 241, 0.1); color:var(--primary-color);";
                                        if($member['role'] === 'Team Lead') $badge_style = "background:rgba(16, 185, 129, 0.1); color:#10b981;";
                                        if($member['role'] === 'Operations Manager') $badge_style = "background:rgba(245, 158, 11, 0.1); color:#f59e0b;";
                                    ?>
                                    <span style="<?php echo $badge_style; ?> padding:0.2rem 0.6rem; border-radius:100px; font-size:0.75rem; font-weight:700; text-transform:uppercase;">
                                        <?php echo htmlspecialchars($member['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($member['created_at'])); ?></td>
                                <td>
                                    <?php if ($member['is_approved']): ?>
                                        <span style="color:var(--accent-green); font-weight:800; font-size:0.75rem; text-transform:uppercase;">Approved</span>
                                    <?php else: ?>
                                        <span style="color:#f59e0b; font-weight:800; font-size:0.75rem; text-transform:uppercase;">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$member['is_approved']): ?>
                                        <button onclick="approveUser(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['username']); ?>')" style="background:var(--accent-green); color:white; border:none; padding:0.4rem 0.8rem; border-radius:8px; cursor:pointer; font-size:0.7rem; font-weight:800;">APPROVE</button>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:0.7rem;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        function approveUser(userId, username) {
            Swal.fire({
                title: 'Approve User?',
                text: `Grant @${username} access to the system?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#1e293b',
                confirmButtonText: 'Yes, Approve'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `approve_user_process.php?id=${userId}`;
                }
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('success') === 'approved') {
            Swal.fire({
                icon: 'success',
                title: 'User Approved!',
                text: 'The account has been successfully activated.',
                background: '#1e293b',
                color: '#f8fafc',
                confirmButtonColor: '#10b981',
                timer: 3000,
                timerProgressBar: true
            });
        } else if (urlParams.get('success') === 'created') {
            Swal.fire({
                icon: 'success',
                title: 'Account Created!',
                text: 'New staff member has been successfully registered.',
                background: '#1e293b',
                color: '#f8fafc',
                confirmButtonColor: '#6366f1',
                timer: 3000,
                timerProgressBar: true
            });
        }
    </script>
</body>
</html>
