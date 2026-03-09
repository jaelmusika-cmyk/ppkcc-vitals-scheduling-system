<?php
// Include necessary files
include('../includes/auth_check.php');
include('../includes/header.php');
include('../config/db.php');

// Fetch all announcements
$sql = "SELECT * FROM announcements";
$result = $pdo->query($sql);
?>

<div class="container">
    <h1>Manage Announcements</h1>

    <a href="create_announcement.php" class="btn btn-success mb-3">Create New Announcement</a>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Announcement ID</th>
                <th>Title</th>
                <th>Content</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                    <td><?php echo htmlspecialchars($row['content']); ?></td>
                    <td>
                        <a href="edit_announcement.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                        <a href="delete_announcement.php?id=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm">Delete</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php include('../includes/footer.php'); ?>
