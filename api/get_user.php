<?php
include '../config/db.php';

if (isset($_GET['email'])) {
    $email = $_GET['email'];
    
    $stmt = $conn->prepare("SELECT full_name, email, phone_number FROM patients WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo json_encode($row);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
    }

    $stmt->close();
    $conn->close();
} else {
    http_response_code(400);
    echo json_encode(["error" => "Email is required"]);
}
?>
