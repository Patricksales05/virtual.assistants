<?php
require_once 'db_config.php';

$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || ($current_role !== 'staff' && $current_role !== 'staff member')) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];

// Fetch detailed user info
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch();
} catch (PDOException $e) {
    $user_info = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - Seamless Assist</title>
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { background: #020617; color: var(--text-main); min-height: 100vh; display: flex; font-size: 0.75rem; }

        .sidebar { width: 195px; background: var(--header-glass); border-right: 1px solid var(--card-border); padding: 1.5rem 1rem; display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; }
        .logo-img { width: 140px; filter: brightness(0) invert(1); margin-bottom: 2rem; }
        .nav-menu { list-style: none; flex: 1; }
        .nav-link { text-decoration: none; color: var(--text-muted); padding: 0.6rem 1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.75rem; transition: all 0.3s; font-weight: 600; font-size: 0.75rem; }
        .nav-link:hover { background: rgba(99, 102, 241, 0.08); color: var(--primary-color); }
        .nav-link.active { background: #6366f1; color: white; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4); }

        .main-content { flex: 1; padding: 1.5rem; background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.1) 0%, transparent 50%); }
        
        .profile-container { max-width: 650px; margin: 1.5rem auto; text-align: center; }
        .profile-card { background: var(--card-glass); backdrop-filter: blur(20px); border-radius: 20px; padding: 2.25rem; border: 1px solid var(--card-border); position: relative; }
        
        .avatar { width: 80px; height: 80px; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: 800; color: white; margin: 0 auto 1.5rem; box-shadow: 0 15px 35px rgba(99, 102, 241, 0.3); }
        
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; text-align: left; margin-top: 2rem; }
        .info-item { background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 12px; border: 1px solid var(--card-border); }
        .label { font-size: 0.55rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; display: block; margin-bottom: 0.15rem; font-weight: 800; }
        .value { font-size: 0.9rem; font-weight: 600; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="dashboard.php?view=profile" class="nav-link active"><i class="fas fa-user-circle"></i><span>Profile</span></a></li>
            <li class="nav-item"><a href="dashboard.php?view=history" class="nav-link"><i class="fas fa-history"></i><span>History</span></a></li>
        </ul>
    </aside>

    <main class="main-content">
        <div class="profile-container">
            <div class="profile-card">
                <div class="avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <h1 style="font-size: 1.45rem; font-weight: 800;"><?php echo htmlspecialchars($user_info['full_name']); ?></h1>
                <p style="color: var(--primary-color); font-weight: 800; margin-top: 0.35rem; text-transform: uppercase; letter-spacing: 0.1em; font-size:0.65rem;"><?php echo htmlspecialchars($user_info['role']); ?></p>

                <div class="info-grid">
                    <div class="info-item"><span class="label">Username</span><span class="value"><?php echo htmlspecialchars($user_info['username']); ?></span></div>
                    <div class="info-item"><span class="label">Staff ID</span><span class="value">VA-<?php echo str_pad($user_info['id'], 4, '0', STR_PAD_LEFT); ?></span></div>
                    <div class="info-item"><span class="label">Date Joined</span><span class="value">April 05, 2026</span></div>
                    <div class="info-item"><span class="label">Primary Role</span><span class="value">Virtual Assistant</span></div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
